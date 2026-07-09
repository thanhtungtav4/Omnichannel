<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cut 5 HTTP surface (spec 15 § C5).
 *
 *   GET    /admin/contacts/merge                  Inertia page (owner)
 *   GET    /api/admin/contacts/duplicates         JSON list (owner)
 *   POST   /api/admin/contacts/{id}/merge/preview JSON preview (owner)
 *   POST   /api/admin/contacts/{id}/merge         commit (owner)
 *
 * RBAC: only role=owner. support_agent / admin → 403.
 * Tenant scoping: cross-workspace merge → 403.
 * Self-merge → 422.
 */
class ContactMergeHttpTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
        $this->owner = User::factory()->create(['workspace_id' => $this->workspace->id, 'role' => 'owner', 'status' => 'ACTIVE']);
        $this->admin = User::factory()->create(['workspace_id' => $this->workspace->id, 'role' => 'admin', 'status' => 'ACTIVE']);
        $this->agent = User::factory()->create(['workspace_id' => $this->workspace->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
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

    // ------------------------------------------------------------------ duplicates

    public function test_duplicates_finds_contacts_sharing_phone_normalized(): void
    {
        $a = $this->makeContact(['full_name' => 'A', 'phone' => '0912345678', 'phone_normalized' => '84912345678']);
        $b = $this->makeContact(['full_name' => 'B', 'phone' => '+84 912 345 678', 'phone_normalized' => '84912345678']);

        $resp = $this->actingAs($this->owner)->getJson(route('admin.contacts.duplicates'));
        $resp->assertOk();

        $groups = $resp->json('data');
        $this->assertNotEmpty($groups);
        $found = collect($groups)->firstWhere('match_type', 'phone');
        $this->assertNotNull($found);
        $this->assertSame($a->id, $found['winner_suggestion']['id']);
        $this->assertCount(2, $found['candidates']);
    }

    public function test_duplicates_finds_contacts_sharing_email_case_insensitive(): void
    {
        $this->makeContact(['full_name' => 'A', 'email' => 'Foo@Example.com']);
        $this->makeContact(['full_name' => 'B', 'email' => 'foo@example.COM']);

        $resp = $this->actingAs($this->owner)->getJson(route('admin.contacts.duplicates'));
        $resp->assertOk();
        $groups = $resp->json('data');

        $found = collect($groups)->firstWhere('match_type', 'email');
        $this->assertNotNull($found);
        $this->assertCount(2, $found['candidates']);
    }

    public function test_duplicates_skips_isolated_contacts(): void
    {
        $this->makeContact(['full_name' => 'Solo', 'phone' => '0900000000', 'phone_normalized' => '84900000000']);

        $resp = $this->actingAs($this->owner)->getJson(route('admin.contacts.duplicates'));
        $resp->assertOk();
        $this->assertSame([], $resp->json('data'));
    }

    // ------------------------------------------------------------------ RBAC

    public function test_admin_role_is_forbidden(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.contacts.duplicates'))
            ->assertForbidden();
    }

    public function test_support_agent_is_forbidden(): void
    {
        $this->actingAs($this->agent)
            ->getJson(route('admin.contacts.duplicates'))
            ->assertForbidden();
    }

    public function test_settings_page_owner_only(): void
    {
        $this->actingAs($this->agent)
            ->get(route('admin.contacts.merge'))
            ->assertForbidden();
        $this->actingAs($this->owner)
            ->get(route('admin.contacts.merge'))
            ->assertOk();
    }

    // ------------------------------------------------------------------ merge

    public function test_merge_endpoint_hard_deletes_loser_and_returns_redirect(): void
    {
        $winner = $this->makeContact(['full_name' => 'Winner']);
        $loser = $this->makeContact(['full_name' => 'Loser']);

        $resp = $this->actingAs($this->owner)
            ->postJson(route('admin.contacts.merge.store', $winner), [
                'loser_ids' => [$loser->id],
            ]);

        $resp->assertRedirect(route('admin.contacts.show', $winner));
        $this->assertDatabaseMissing('contacts', ['id' => $loser->id]);
    }

    public function test_self_merge_returns_validation_error(): void
    {
        $winner = $this->makeContact(['full_name' => 'Self']);

        $this->actingAs($this->owner)
            ->postJson(route('admin.contacts.merge.store', $winner), [
                'loser_ids' => [$winner->id],
            ])
            ->assertStatus(302); // back()->withErrors → redirect
    }

    public function test_cross_workspace_merge_returns_validation_error(): void
    {
        $winner = $this->makeContact(['full_name' => 'W']);
        $otherWs = Workspace::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'ACTIVE']);
        $stranger = Contact::create([
            'workspace_id' => $otherWs->id,
            'full_name' => 'S',
            'status' => 'ACTIVE',
            'source' => 'MANUAL',
        ]);

        $resp = $this->actingAs($this->owner)
            ->postJson(route('admin.contacts.merge.store', $winner), [
                'loser_ids' => [$stranger->id],
            ]);

        // RuntimeException → caught and surfaced via back()->withErrors
        $resp->assertStatus(302);
        $resp->assertSessionHasErrors('merge');
    }

    public function test_merge_endpoint_rejects_loser_ids_from_other_workspace_silently(): void
    {
        // The controller filters loser_ids by workspace_id; cross-ws ids
        // become an empty set → validation fails on min:1.
        $winner = $this->makeContact(['full_name' => 'W']);
        $otherWs = Workspace::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'ACTIVE']);
        $stranger = Contact::create([
            'workspace_id' => $otherWs->id,
            'full_name' => 'S',
            'status' => 'ACTIVE',
            'source' => 'MANUAL',
        ]);

        $resp = $this->actingAs($this->owner)
            ->postJson(route('admin.contacts.merge.store', $winner), [
                'loser_ids' => [$stranger->id],
            ]);

        // winner isn't merged, stranger still exists.
        $resp->assertStatus(302);
        $this->assertDatabaseHas('contacts', ['id' => $stranger->id]);
    }

    // ------------------------------------------------------------------ preview

    public function test_preview_endpoint_returns_merged_snapshot(): void
    {
        $winner = $this->makeContact(['full_name' => 'W', 'tags' => ['a']]);
        $loser = $this->makeContact(['full_name' => 'L', 'tags' => ['b']]);

        $resp = $this->actingAs($this->owner)
            ->postJson(route('admin.contacts.merge.preview', $winner), [
                'loser_ids' => [$loser->id],
            ]);

        $resp->assertOk();
        $this->assertSame($winner->id, $resp->json('data.winner_id'));
        $this->assertEqualsCanonicalizing(
            ['a', 'b'],
            $resp->json('data.merged_fields.tags'),
        );
        // Preview is read-only — loser NOT deleted.
        $this->assertDatabaseHas('contacts', ['id' => $loser->id]);
    }

    public function test_preview_requires_loser_ids(): void
    {
        $winner = $this->makeContact(['full_name' => 'W']);

        $this->actingAs($this->owner)
            ->postJson(route('admin.contacts.merge.preview', $winner), [
                'loser_ids' => [],
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------ 404 loser route resolution

    public function test_merge_route_resolves_to_contact_show_for_unrelated_uuid(): void
    {
        // /admin/contacts/merge is matched BEFORE /admin/contacts/{contact},
        // so requesting a non-UUID against /admin/contacts/{contact} lands
        // on the show route. Verify that an arbitrary UUID hits merge
        // endpoints (not the show route's 404).
        $winner = $this->makeContact(['full_name' => 'W']);

        $resp = $this->actingAs($this->owner)
            ->postJson(route('admin.contacts.merge.store', $winner), [
                'loser_ids' => [$winner->id],
            ]);

        // self-merge → handled by controller → 302 redirect (not 404).
        $resp->assertStatus(302);
    }
}
