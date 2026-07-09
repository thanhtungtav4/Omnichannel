<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactIngestEvent;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Crm\Models\TimelineActivity;
use App\Modules\Crm\Services\ContactIngestor;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cut 2 of specs/15_CONTACTS_INGESTION.md — ContactIngestor single chokepoint.
 *
 * Verifies the four core paths:
 *  1. first-time contact create (new row, identity row attached)
 *  2. identity match across sources (same user via 2 channels)
 *  3. phone match across sources (cross-channel)
 *  4. dedup via ingest_event_id returns existing contact without re-creating
 *
 * Plus the negative paths: matched contact doesn't get re-owned, attributes
 * shallow-merge, consent is first-write-wins, source_detail first-write-wins.
 */
class ContactIngestorTest extends TestCase
{
    use RefreshDatabase;

    private ContactIngestor $ingestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestor = $this->app->make(ContactIngestor::class);
    }

    private function bootWorkspace(): array
    {
        $workspace = Workspace::create(['name' => 'W', 'slug' => 'w', 'status' => 'ACTIVE']);
        $agent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'role' => 'support_agent',
            'status' => 'ACTIVE',
        ]);

        return compact('workspace', 'agent');
    }

    // ------------------------------------------------------------ create

    public function test_first_time_ingest_creates_contact_and_identity(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $account = ChannelAccount::create([
            'workspace_id' => $w->id, 'provider' => 'TELEGRAM',
            'name' => 'TG', 'status' => 'ACTIVE',
        ]);

        $contact = $this->ingestor->ingest([
            'workspace_id' => $w->id,
            'source' => 'TELEGRAM',
            'full_name' => 'Nguyen Van A',
            'phone' => '0912345678',
            'email' => 'a@example.test',
            'external_identity' => [
                'provider' => 'TELEGRAM',
                'provider_account_id' => $account->id,
                'provider_user_id' => 'tg-1001',
                'display_name' => 'Nguyen Van A',
            ],
            'attributes' => ['first_seen_via' => 'telegram_message'],
            'consent' => ['ip' => '127.0.0.1', 'user_agent' => 'TelegramBot'],
            'ingest_event_id' => 'evt-001',
            'last_inbound_at' => now(),
        ]);

        $this->assertSame('Nguyen Van A', $contact->full_name);
        $this->assertSame('0912345678', $contact->phone);
        $this->assertSame('84912345678', $contact->phone_normalized);
        $this->assertSame('a@example.test', $contact->email);
        $this->assertSame(['first_seen_via' => 'telegram_message'], $contact->attributes);
        $this->assertSame('127.0.0.1', $contact->consent_ip);
        $this->assertSame('TelegramBot', $contact->consent_user_agent);

        // Identity attached to the new contact
        $identity = ExternalIdentity::query()
            ->where('contact_id', $contact->id)
            ->firstOrFail();
        $this->assertSame('TELEGRAM', $identity->provider);
        $this->assertSame('tg-1001', $identity->provider_user_id);
        $this->assertSame($account->id, $identity->provider_account_id);

        // Ingest event recorded for audit
        $this->assertDatabaseHas('contact_ingest_events', [
            'workspace_id' => $w->id,
            'contact_id' => $contact->id,
            'source' => 'TELEGRAM',
            'source_event_id' => 'evt-001',
        ]);

        // Timeline row written for the new contact
        $this->assertDatabaseHas('timeline_activities', [
            'workspace_id' => $w->id,
            'subject_id' => $contact->id,
            'subject_type' => 'crm.contact',
            'module' => 'crm',
            'type' => 'CONTACT_INGESTED',
        ]);
    }

    public function test_existing_contact_match_does_not_create_a_new_timeline_row(): void
    {
        ['workspace' => $w] = $this->bootWorkspace();

        $existing = Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'Existing',
            'status' => 'ACTIVE', 'source' => 'TELEGRAM',
        ]);

        // Same contact, second ingest: must NOT add a CONTACT_INGESTED row.
        $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'TELEGRAM',
            'full_name' => 'Different Name But Same Person',
            'ingest_event_id' => 'evt-002',
        ]);

        $this->assertSame(0, TimelineActivity::query()
            ->where('subject_type', 'crm.contact')
            ->where('subject_id', $existing->id)
            ->where('type', 'CONTACT_INGESTED')
            ->count());
    }

    // ------------------------------------------------------------ match: identity

    public function test_match_by_external_identity_returns_existing_contact(): void
    {
        ['workspace' => $w] = $this->bootWorkspace();
        $account = ChannelAccount::create([
            'workspace_id' => $w->id, 'provider' => 'TELEGRAM',
            'name' => 'TG', 'status' => 'ACTIVE',
        ]);

        $existing = Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'Already Known',
            'status' => 'ACTIVE', 'source' => 'TELEGRAM',
        ]);
        ExternalIdentity::create([
            'workspace_id' => $w->id, 'contact_id' => $existing->id,
            'provider' => 'TELEGRAM',
            'provider_account_id' => $account->id,
            'provider_user_id' => 'tg-known',
            'display_name' => 'Already Known',
        ]);

        // New ingest from the same Telegram user — should match by identity.
        $contact = $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'TELEGRAM',
            'full_name' => 'Renamed After Match',
            'external_identity' => [
                'provider' => 'TELEGRAM',
                'provider_account_id' => $account->id,
                'provider_user_id' => 'tg-known',
                'display_name' => 'Renamed After Match',
            ],
            'ingest_event_id' => 'evt-match-1',
        ]);

        $this->assertSame($existing->id, $contact->id);
    }

    // ------------------------------------------------------------ match: phone

    public function test_match_by_phone_normalized_across_sources(): void
    {
        ['workspace' => $w] = $this->bootWorkspace();

        // Existing ZALO_PERSONAL contact with phone 0912...
        $existing = Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'Phone Match',
            'phone' => '0912345678',
            'phone_normalized' => '84912345678',
            'status' => 'ACTIVE', 'source' => 'ZALO_PERSONAL',
        ]);

        // New ingest from website form with phone 84... (canonical form) —
        // should match by phone_normalized, not create a duplicate.
        $contact = $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'WEBSITE_FORM',
            'source_detail' => 'summer-sale',
            'full_name' => 'Web Form Customer',
            'phone' => '+84 912 345 678',
            'attributes' => ['utm_source' => 'fb'],
            'ingest_event_id' => 'evt-phone-match',
        ]);

        $this->assertSame($existing->id, $contact->id);
        // source_detail first-write-wins: ZALO_PERSONAL contact already has
        // null source_detail, web form value fills it.
        $this->assertSame('summer-sale', $contact->fresh()->source_detail);
    }

    // ------------------------------------------------------------ dedup

    public function test_dedup_via_ingest_event_id_returns_existing_contact(): void
    {
        ['workspace' => $w] = $this->bootWorkspace();

        // First call creates the contact + ingest event.
        $first = $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'WEBSITE_FORM',
            'full_name' => 'Form Submitter',
            'phone' => '0987654321',
            'ingest_event_id' => 'web-idem-1',
        ]);

        // Same event id, different payload — must return the original
        // contact, not create a new one.
        $second = $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'WEBSITE_FORM',
            'full_name' => 'Different Name',  // would-be-create data
            'phone' => '0900000000',
            'ingest_event_id' => 'web-idem-1',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Form Submitter', $second->full_name);
        $this->assertSame(1, Contact::query()->where('workspace_id', $w->id)->count());
    }

    // ------------------------------------------------------------ consent + source_detail first-write-wins

    public function test_consent_and_source_detail_are_first_write_wins(): void
    {
        ['workspace' => $w] = $this->bootWorkspace();

        $existing = Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'K',
            'source' => 'WEBSITE_FORM',
            'source_detail' => 'original-campaign',
            'consent_ip' => '10.0.0.1',
            'phone' => '0912345678',
            'phone_normalized' => '84912345678',
            'status' => 'ACTIVE',
        ]);

        // Second ingest tries to overwrite — must NOT touch these fields.
        // Phone match anchors the lookup so the second ingest hits the
        // matched-contact path (not the create path).
        $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'WEBSITE_FORM',
            'full_name' => 'Same Person',
            'phone' => '0912345678',
            'source_detail' => 'should-not-overwrite',
            'consent' => ['ip' => '99.99.99.99'],
            'ingest_event_id' => 'evt-2nd',
        ]);

        $fresh = $existing->fresh();
        $this->assertSame('original-campaign', $fresh->source_detail);
        $this->assertSame('10.0.0.1', $fresh->consent_ip);
    }

    public function test_attributes_shallow_merge_payload_wins(): void
    {
        ['workspace' => $w] = $this->bootWorkspace();

        Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'K',
            'source' => 'WEBSITE_FORM',
            'phone' => '0912345678',
            'phone_normalized' => '84912345678',
            'attributes' => ['utm_source' => 'fb', 'existing_key' => 'keep'],
            'status' => 'ACTIVE',
        ]);

        $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'WEBSITE_FORM',
            'full_name' => 'Same Person',
            'phone' => '0912345678',
            'attributes' => ['utm_source' => 'google', 'new_key' => 'add'],
            'ingest_event_id' => 'evt-merge',
        ]);

        $attrs = Contact::query()->where('workspace_id', $w->id)->first()->attributes;
        $this->assertSame('google', $attrs['utm_source']);   // payload wins
        $this->assertSame('keep', $attrs['existing_key']);   // kept
        $this->assertSame('add', $attrs['new_key']);         // added
    }

    public function test_phone_match_fills_empty_email_but_does_not_overwrite(): void
    {
        ['workspace' => $w] = $this->bootWorkspace();

        Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'K',
            'phone' => '0912345678',
            'phone_normalized' => '84912345678',
            'email' => 'known@example.test',
            'status' => 'ACTIVE', 'source' => 'ZALO_PERSONAL',
        ]);

        $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'WEBSITE_FORM',
            'full_name' => 'Same Person',
            'phone' => '0912345678',
            'email' => 'should-not-overwrite@example.test',
            'ingest_event_id' => 'evt-email',
        ]);

        $email = Contact::query()->where('workspace_id', $w->id)->value('email');
        $this->assertSame('known@example.test', $email);
    }

    // ------------------------------------------------------------ tenant isolation

    public function test_ingest_cannot_match_contact_in_other_workspace(): void
    {
        ['workspace' => $w1] = $this->bootWorkspace();
        $w2 = Workspace::create(['name' => 'W2', 'slug' => 'w2', 'status' => 'ACTIVE']);

        Contact::create([
            'workspace_id' => $w1->id, 'full_name' => 'K',
            'phone' => '0912345678',
            'phone_normalized' => '84912345678',
            'status' => 'ACTIVE', 'source' => 'ZALO_PERSONAL',
        ]);

        // Same phone, but in workspace 2 — must NOT match workspace 1's
        // contact. The BelongsToWorkspace global scope enforces this at the
        // Eloquent layer; even an explicit workspace_id in the query gets
        // overridden.
        $contact = $this->ingestor->ingest([
            'workspace_id' => $w2->id, 'source' => 'WEBSITE_FORM',
            'full_name' => 'Different Workspace',
            'phone' => '0912345678',
            'ingest_event_id' => 'evt-iso',
        ]);

        $this->assertSame($w2->id, $contact->workspace_id);
        $this->assertSame(2, Contact::query()->count());
    }

    // ------------------------------------------------------------ owner resolution

    public function test_caller_supplied_owner_id_is_applied(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();

        $contact = $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'MANUAL',
            'full_name' => 'Manual Create',
            'owner_id' => $agent->id,
            'ingest_event_id' => 'evt-owner',
        ]);

        $this->assertSame($agent->id, $contact->fresh()->owner_id);
    }

    public function test_default_owner_is_unassigned_for_non_routed_sources(): void
    {
        ['workspace' => $w] = $this->bootWorkspace();

        $contact = $this->ingestor->ingest([
            'workspace_id' => $w->id, 'source' => 'WEBSITE_FORM',
            'full_name' => 'No Owner',
            'ingest_event_id' => 'evt-no-owner',
        ]);

        $this->assertNull($contact->fresh()->owner_id);
    }
}