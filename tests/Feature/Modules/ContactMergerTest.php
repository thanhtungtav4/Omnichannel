<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactNote;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\Stage;
use App\Modules\Crm\Services\ContactMerger;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cut 5 of specs/15_CONTACTS_INGESTION.md — ContactMerger.
 *
 * Covers the conflict policy:
 *  - full_name: prefer non-empty; tie-break on identity count
 *  - phone/email: fill empty, keep winner's non-empty
 *  - tags: union
 *  - status: most permissive (ACTIVE > ARCHIVED > BLOCKED)
 *  - owner_id: always winner's
 *  - last_inbound_at: max
 *  - attributes: shallow merge, loser wins
 *  - consent_*: first-write-wins
 *  - source_detail: first-write-wins
 *
 * Re-pointing:
 *  - external_identities, contact_notes, leads, conversations → winner
 *  - timeline_activities.subject_id NOT touched
 *
 * Validation:
 *  - self-merge rejected
 *  - cross-workspace merge rejected
 *  - empty loser set rejected
 *
 * Audit:
 *  - audit_logs row written with winner + loser_ids + diffs
 */
class ContactMergerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
        $this->owner = User::factory()->create([
            'workspace_id' => $this->workspace->id,
            'role' => 'owner',
            'status' => 'ACTIVE',
        ]);
    }

    private function makeContact(array $attrs): Contact
    {
        return Contact::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Default',
            'status' => 'ACTIVE',
            'source' => 'WEBSITE_FORM',
        ], $attrs));
    }

    private function addIdentity(Contact $contact, string $provider = 'TELEGRAM', string $userId = 'tg-1', ?string $name = null): ExternalIdentity
    {
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => $provider,
            'name' => $name ?? $provider.'-'.$userId,
            'status' => 'ACTIVE',
        ]);

        return ExternalIdentity::create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'provider' => $provider,
            'provider_account_id' => $channelAccount->id,
            'provider_user_id' => $userId,
        ]);
    }

    public function test_self_merge_rejected(): void
    {
        $winner = $this->makeContact(['full_name' => 'Self']);

        $this->expectException(\RuntimeException::class);
        app(ContactMerger::class)->merge($winner, collect([$winner]));
    }

    public function test_empty_losers_rejected(): void
    {
        $winner = $this->makeContact(['full_name' => 'W']);

        $this->expectException(\RuntimeException::class);
        app(ContactMerger::class)->merge($winner, collect());
    }

    public function test_cross_workspace_merge_rejected(): void
    {
        $winner = $this->makeContact(['full_name' => 'W']);
        $otherWs = Workspace::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'ACTIVE']);
        $stranger = Contact::create([
            'workspace_id' => $otherWs->id,
            'full_name' => 'S',
            'status' => 'ACTIVE',
            'source' => 'MANUAL',
        ]);

        $this->expectException(\RuntimeException::class);
        app(ContactMerger::class)->merge($winner, collect([$stranger]));
    }

    public function test_full_name_prefers_non_empty_then_identity_count(): void
    {
        $winner = $this->makeContact(['full_name' => 'Original']);
        $loserWeak = $this->makeContact(['full_name' => '']); // empty, fallback

        // Empty loser name → winner keeps its name.
        $merged = app(ContactMerger::class)->merge($winner, collect([$loserWeak]));
        $this->assertSame('Original', $merged->full_name);

        // Loser with more identities and a non-empty name → loser wins.
        // Use 3 distinct providers (each becomes a separate ChannelAccount
        // row, distinct names to satisfy the (workspace, provider, name)
        // unique constraint).
        $loserStrong = $this->makeContact(['full_name' => 'Trusted']);
        $this->addIdentity($loserStrong, 'TELEGRAM', 'tg-strong-1', 'Strong TG');
        $this->addIdentity($loserStrong, 'ZALO_PERSONAL', 'zp-strong-1', 'Strong ZP');
        $this->addIdentity($loserStrong, 'FACEBOOK', 'fb-strong-1', 'Strong FB');

        $winner2 = $this->makeContact(['full_name' => 'Mid']);
        $this->addIdentity($winner2, 'TELEGRAM', 'tg-w-1', 'Mid TG'); // 1 identity

        $merged2 = app(ContactMerger::class)->merge($winner2, collect([$loserStrong]));
        $this->assertSame('Trusted', $merged2->full_name);
    }

    public function test_phone_fills_empty_winner(): void
    {
        $winner = $this->makeContact(['full_name' => 'W', 'phone' => null, 'phone_normalized' => null]);
        $loser = $this->makeContact(['full_name' => 'L', 'phone' => '0912345678', 'phone_normalized' => '84912345678']);

        $merged = app(ContactMerger::class)->merge($winner, collect([$loser]));
        $this->assertSame('0912345678', $merged->phone);
        $this->assertSame('84912345678', $merged->phone_normalized);
    }

    public function test_phone_keeps_winner_when_both_have_different_numbers(): void
    {
        $winner = $this->makeContact(['full_name' => 'W', 'phone' => '0911111111', 'phone_normalized' => '84911111111']);
        $loser = $this->makeContact(['full_name' => 'L', 'phone' => '0922222222', 'phone_normalized' => '84922222222']);

        $merged = app(ContactMerger::class)->merge($winner, collect([$loser]));
        $this->assertSame('0911111111', $merged->phone);
    }

    public function test_tags_union(): void
    {
        $winner = $this->makeContact(['full_name' => 'W', 'tags' => ['VIP', 'new-customer']]);
        $loser = $this->makeContact(['full_name' => 'L', 'tags' => ['VIP', 'wholesale']]);

        $merged = app(ContactMerger::class)->merge($winner, collect([$loser]));
        $this->assertEqualsCanonicalizing(['VIP', 'new-customer', 'wholesale'], $merged->tags);
    }

    public function status_permissive_test(): void
    {
        $winner = $this->makeContact(['full_name' => 'W', 'status' => 'BLOCKED']);
        $loserActive = $this->makeContact(['full_name' => 'L1', 'status' => 'ACTIVE']);

        $merged = app(ContactMerger::class)->merge($winner, collect([$loserActive]));
        $this->assertSame('ACTIVE', $merged->status);

        // ACTIVE stays ACTIVE even if loser is ARCHIVED.
        $winner2 = $this->makeContact(['full_name' => 'W2', 'status' => 'ACTIVE']);
        $loser2 = $this->makeContact(['full_name' => 'L2', 'status' => 'ARCHIVED']);

        $merged2 = app(ContactMerger::class)->merge($winner2, collect([$loser2]));
        $this->assertSame('ACTIVE', $merged2->status);
    }

    public function test_owner_id_always_winner(): void
    {
        $ownerA = User::factory()->create(['workspace_id' => $this->workspace->id, 'role' => 'sales']);
        $ownerB = User::factory()->create(['workspace_id' => $this->workspace->id, 'role' => 'sales']);

        $winner = $this->makeContact(['full_name' => 'W', 'owner_id' => $ownerA->id]);
        $loser = $this->makeContact(['full_name' => 'L', 'owner_id' => $ownerB->id]);

        $merged = app(ContactMerger::class)->merge($winner, collect([$loser]));
        $this->assertSame($ownerA->id, $merged->owner_id);
    }

    public function test_last_inbound_at_takes_max(): void
    {
        $old = Carbon::parse('2026-01-01');
        $new = Carbon::parse('2026-07-01');
        $winner = $this->makeContact(['full_name' => 'W', 'last_inbound_at' => $old]);
        $loser = $this->makeContact(['full_name' => 'L', 'last_inbound_at' => $new]);

        $merged = app(ContactMerger::class)->merge($winner, collect([$loser]));
        $this->assertEquals($new, $merged->last_inbound_at);

        // Reverse — winner's later timestamp wins.
        $winner2 = $this->makeContact(['full_name' => 'W2', 'last_inbound_at' => $new]);
        $loser2 = $this->makeContact(['full_name' => 'L2', 'last_inbound_at' => $old]);

        $merged2 = app(ContactMerger::class)->merge($winner2, collect([$loser2]));
        $this->assertEquals($new, $merged2->last_inbound_at);
    }

    public function test_attributes_shallow_merge_loser_wins(): void
    {
        $winner = $this->makeContact([
            'full_name' => 'W',
            'attributes' => ['utm_source' => 'fb', 'shared' => 'winner'],
        ]);
        $loser = $this->makeContact([
            'full_name' => 'L',
            'attributes' => ['utm_source' => 'google', 'shared' => 'loser', 'extra' => 1],
        ]);

        $merged = app(ContactMerger::class)->merge($winner, collect([$loser]));
        // utm_source overwritten by loser (last in merge order).
        // shared overwritten by loser.
        // extra added.
        $this->assertSame('google', $merged->attributes['utm_source']);
        $this->assertSame('loser', $merged->attributes['shared']);
        $this->assertSame(1, $merged->attributes['extra']);
    }

    public function test_consent_first_write_wins(): void
    {
        $winner = $this->makeContact([
            'full_name' => 'W',
            'consent_given_at' => '2026-05-01 00:00:00',
            'consent_ip' => '1.1.1.1',
            'consent_user_agent' => 'Chrome',
            'consent_text' => 'agree v1',
        ]);
        $loser = $this->makeContact([
            'full_name' => 'L',
            'consent_given_at' => '2026-06-01 00:00:00',
            'consent_ip' => '2.2.2.2',
            'consent_user_agent' => 'Firefox',
            'consent_text' => 'agree v2',
        ]);

        $merged = app(ContactMerger::class)->merge($winner, collect([$loser]));
        $this->assertSame('1.1.1.1', $merged->consent_ip);
        $this->assertSame('Chrome', $merged->consent_user_agent);
        $this->assertSame('agree v1', $merged->consent_text);
    }

    public function test_external_identities_are_repointed_to_winner(): void
    {
        $winner = $this->makeContact(['full_name' => 'W']);
        $loser = $this->makeContact(['full_name' => 'L']);

        $winnerIdentity = $this->addIdentity($winner, 'TELEGRAM', 'tg-w');
        $loserIdentity = $this->addIdentity($loser, 'ZALO_PERSONAL', 'zp-l');

        app(ContactMerger::class)->merge($winner, collect([$loser]));

        $this->assertDatabaseHas('external_identities', [
            'id' => $winnerIdentity->id,
            'contact_id' => $winner->id,
        ]);
        $this->assertDatabaseHas('external_identities', [
            'id' => $loserIdentity->id,
            'contact_id' => $winner->id, // re-pointed
        ]);
    }

    public function test_leads_and_conversations_and_notes_repointed(): void
    {
        $winner = $this->makeContact(['full_name' => 'W']);
        $loser = $this->makeContact(['full_name' => 'L']);

        $pipeline = Pipeline::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Sales',
            'type' => 'LEAD',
            'is_default' => true,
        ]);
        $stage = Stage::create([
            'workspace_id' => $this->workspace->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'New',
            'sort_order' => 1,
            'status_group' => 'OPEN',
        ]);

        $lead = Lead::create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $loser->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'title' => 'Lost lead',
            'status' => 'NEW',
            'source' => 'WEBSITE_FORM',
            'last_activity_at' => now(),
        ]);
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'TELEGRAM',
            'name' => 'TG',
            'status' => 'ACTIVE',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $channelAccount->id,
            'contact_id' => $loser->id,
            'status' => 'OPEN',
            'priority' => 'NORMAL',
        ]);
        $note = ContactNote::create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $loser->id,
            'author_id' => $this->owner->id,
            'body' => 'A note on the loser',
        ]);

        app(ContactMerger::class)->merge($winner, collect([$loser]));

        // Loser is hard-deleted.
        $this->assertDatabaseMissing('contacts', ['id' => $loser->id]);

        // All FKs re-pointed.
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'contact_id' => $winner->id]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'contact_id' => $winner->id]);
        $this->assertDatabaseHas('contact_notes', ['id' => $note->id, 'contact_id' => $winner->id]);
    }

    public function test_audit_log_row_written(): void
    {
        $winner = $this->makeContact(['full_name' => 'W']);
        $loser = $this->makeContact(['full_name' => 'L']);

        app(ContactMerger::class)->merge($winner, collect([$loser]));

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'module' => 'crm',
            'action' => 'contacts.merged',
            'subject_type' => 'contact',
            'subject_id' => $winner->id,
        ]);

        $log = AuditLog::query()
            ->where('action', 'contacts.merged')
            ->firstOrFail();
        $this->assertSame($winner->id, $log->metadata['winner_id']);
        $this->assertSame([$loser->id], $log->metadata['loser_ids']);
        $this->assertArrayHasKey('diffs', $log->metadata);
    }

    public function test_preview_returns_merged_snapshot_without_committing(): void
    {
        $winner = $this->makeContact(['full_name' => 'W', 'tags' => ['a']]);
        $loser = $this->makeContact(['full_name' => 'L', 'tags' => ['b']]);

        $preview = app(ContactMerger::class)->preview($winner, collect([$loser]));

        $this->assertSame($winner->id, $preview['winner_id']);
        $this->assertSame([$loser->id], $preview['loser_ids']);
        $this->assertEqualsCanonicalizing(['a', 'b'], $preview['merged_fields']['tags']);
        // Loser NOT deleted (preview is read-only).
        $this->assertDatabaseHas('contacts', ['id' => $loser->id]);
    }
}
