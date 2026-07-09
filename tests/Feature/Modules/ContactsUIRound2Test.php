<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactNote;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\Stage;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cut 1 of specs/15_CONTACTS_INGESTION.md — UI gap closure.
 * Covers: list-page filters + pagination, status/owner/note sub-resource
 * updates, "create lead from contact".
 *
 * Conventions:
 *  - API routes (`/api/admin/...`) return JSON 422 on validation errors,
 *    so validation tests use `putJson/postJson` + `assertJsonValidationErrors`.
 *  - List/detail pages are Inertia; Inertia renders an HTML page even on
 *    validation failure, so we assert via `assertInertia` on the success
 *    path and via `assertJsonValidationErrors` on the API sub-resources.
 */
class ContactsUIRound2Test extends TestCase
{
    use RefreshDatabase;

    private function bootWorkspace(): array
    {
        $workspace = Workspace::create(['name' => 'W', 'slug' => 'w', 'status' => 'ACTIVE']);
        $agent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'role' => 'support_agent',
            'status' => 'ACTIVE',
            'display_name' => 'Agent A',
        ]);
        $otherAgent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'role' => 'support_agent',
            'status' => 'ACTIVE',
            'display_name' => 'Agent B',
        ]);
        $viewer = User::factory()->create([
            'workspace_id' => $workspace->id,
            'role' => 'viewer',
            'status' => 'ACTIVE',
        ]);
        $sales = User::factory()->create([
            'workspace_id' => $workspace->id,
            'role' => 'sales',
            'status' => 'ACTIVE',
            'display_name' => 'Sales A',
        ]);

        return compact('workspace', 'agent', 'otherAgent', 'viewer', 'sales');
    }

    // ------------------------------------------------------------ list page

    public function test_contacts_index_supports_search_filter_sort_and_pagination(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();

        // 30 contacts: half with phone 0912…, half with phone 0909….
        for ($i = 0; $i < 15; $i++) {
            Contact::create([
                'workspace_id' => $w->id,
                'full_name' => "Khách A{$i}",
                'phone' => "0912000{$i}",
                'email' => "a{$i}@x.vn",
                'status' => 'ACTIVE',
                'source' => 'MANUAL',
                'tags' => $i % 2 === 0 ? ['VIP'] : [],
            ]);
        }
        for ($i = 0; $i < 15; $i++) {
            Contact::create([
                'workspace_id' => $w->id,
                'full_name' => "Khách B{$i}",
                'phone' => "0909000{$i}",
                'status' => 'ARCHIVED',
                'source' => 'TELEGRAM',
                'tags' => [],
            ]);
        }

        // 1) search by phone prefix
        $this->actingAs($agent)
            ->get('/admin/contacts?q=0912')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contacts')
                ->where('contacts.total', 15)
                ->where('filters.q', '0912')
                ->etc());

        // 2) status filter
        $this->actingAs($agent)
            ->get('/admin/contacts?status=ARCHIVED')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.total', 15)
                ->where('filters.status', 'ARCHIVED')
                ->etc());

        // 3) source filter
        $this->actingAs($agent)
            ->get('/admin/contacts?source=MANUAL')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.total', 15)
                ->where('filters.source', 'MANUAL')
                ->etc());

        // 4) tag filter (JSONB containment)
        $this->actingAs($agent)
            ->get('/admin/contacts?tag=VIP')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.total', 8)
                ->where('filters.tag', 'VIP')
                ->etc());

        // 5) owner filter "null" = unassigned
        $this->actingAs($agent)
            ->get('/admin/contacts?owner_id=null')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.total', 30)
                ->where('filters.owner_id', 'null')
                ->etc());

        // 6) sort + paginate. per_page=25 is a valid size; page 1 of 2.
        $this->actingAs($agent)
            ->get('/admin/contacts?sort=full_name&dir=asc&per_page=25')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contacts')
                ->where('contacts.per_page', 25)
                ->where('contacts.current_page', 1)
                ->where('contacts.last_page', 2)
                ->where('contacts.total', 30)
                ->where('filters.sort', 'full_name')
                ->where('filters.dir', 'asc')
                ->where('filters.per_page', 25)
                ->has('contacts.data', 25)
                ->etc());

        // 7) per_page=50 — page 1 of 1
        $this->actingAs($agent)
            ->get('/admin/contacts?per_page=50')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.per_page', 50)
                ->where('contacts.last_page', 1)
                ->etc());

        // 8) tampered per_page snaps to 25
        $this->actingAs($agent)
            ->get('/admin/contacts?per_page=99999')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.per_page', 25)
                ->where('filters.per_page', 25)
                ->etc());

        // 9) tampered sort snaps to default
        $this->actingAs($agent)
            ->get('/admin/contacts?sort=DROP_TABLE')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'last_inbound_at')
                ->etc());

        // 10) tampered dir snaps to default
        $this->actingAs($agent)
            ->get('/admin/contacts?dir=sideways')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.dir', 'desc')
                ->etc());
    }

    public function test_first_page_ascending_sort_starts_with_lowest_name(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();

        // Order matters: insert in reverse so we can prove the sort, not
        // the insertion order, drives the result.
        foreach (['Charlie', 'Bravo', 'Alpha'] as $name) {
            Contact::create([
                'workspace_id' => $w->id, 'full_name' => $name,
                'status' => 'ACTIVE', 'source' => 'MANUAL',
            ]);
        }

        $this->actingAs($agent)
            ->get('/admin/contacts?sort=full_name&dir=asc&per_page=25')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.data.0.name', 'Alpha')
                ->where('contacts.data.1.name', 'Bravo')
                ->where('contacts.data.2.name', 'Charlie')
                ->etc());
    }

    public function test_open_leads_count_excludes_won_lost_archived(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $c = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        Lead::create(['workspace_id' => $w->id, 'contact_id' => $c->id, 'owner_id' => $agent->id, 'title' => 'NEW', 'status' => 'NEW', 'source' => 'MANUAL']);
        Lead::create(['workspace_id' => $w->id, 'contact_id' => $c->id, 'owner_id' => $agent->id, 'title' => 'QUAL', 'status' => 'QUALIFYING', 'source' => 'MANUAL']);
        Lead::create(['workspace_id' => $w->id, 'contact_id' => $c->id, 'owner_id' => $agent->id, 'title' => 'OPEN', 'status' => 'OPEN', 'source' => 'MANUAL']);
        Lead::create(['workspace_id' => $w->id, 'contact_id' => $c->id, 'owner_id' => $agent->id, 'title' => 'WON', 'status' => 'WON', 'source' => 'MANUAL']);
        Lead::create(['workspace_id' => $w->id, 'contact_id' => $c->id, 'owner_id' => $agent->id, 'title' => 'LOST', 'status' => 'LOST', 'source' => 'MANUAL']);

        $this->actingAs($agent)
            ->get('/admin/contacts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contacts.data.0.openLeadsCount', 3)
                ->etc());
    }

    public function test_agents_payload_excludes_disabled_and_viewer_roles(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        // disabled agent — should NOT show up
        User::factory()->create([
            'workspace_id' => $w->id, 'role' => 'support_agent', 'status' => 'DISABLED',
            'display_name' => 'Disabled',
        ]);

        // bootWorkspace creates agent + otherAgent + sales (3 active owner-eligible)
        // viewer is excluded by role filter.
        $this->actingAs($agent)
            ->get('/admin/contacts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('agents', 3)
                ->etc());
    }

    // ------------------------------------------------------------ status

    public function test_support_agent_can_change_contact_status(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $this->actingAs($agent)
            ->putJson(route('admin.contacts.status', $contact), ['status' => 'ARCHIVED'])
            ->assertRedirect();

        $this->assertSame('ARCHIVED', $contact->fresh()->status);
    }

    public function test_status_must_be_in_enum(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $this->actingAs($agent)
            ->putJson(route('admin.contacts.status', $contact), ['status' => 'BANNED'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('ACTIVE', $contact->fresh()->status);
    }

    public function test_status_on_other_workspace_contact_is_not_found(): void
    {
        ['agent' => $agent] = $this->bootWorkspace();
        $other = Workspace::create(['name' => 'O', 'slug' => 'o', 'status' => 'ACTIVE']);
        $foreign = Contact::create(['workspace_id' => $other->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $this->actingAs($agent)
            ->putJson(route('admin.contacts.status', $foreign), ['status' => 'BLOCKED'])
            ->assertNotFound();
    }

    // ------------------------------------------------------------ owner

    public function test_owner_can_be_reassigned_to_a_workspace_member(): void
    {
        ['workspace' => $w, 'agent' => $agent, 'otherAgent' => $other] = $this->bootWorkspace();
        $contact = Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'K',
            'owner_id' => $agent->id, 'status' => 'ACTIVE', 'source' => 'MANUAL',
        ]);

        $this->actingAs($agent)
            ->putJson(route('admin.contacts.owner', $contact), ['owner_id' => $other->id])
            ->assertRedirect();

        $this->assertSame($other->id, $contact->fresh()->owner_id);
    }

    public function test_owner_can_be_unassigned(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $contact = Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'K',
            'owner_id' => $agent->id, 'status' => 'ACTIVE', 'source' => 'MANUAL',
        ]);

        $this->actingAs($agent)
            ->putJson(route('admin.contacts.owner', $contact), ['owner_id' => null])
            ->assertRedirect();

        $this->assertNull($contact->fresh()->owner_id);
    }

    public function test_owner_cannot_be_set_to_user_outside_workspace(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $otherWs = Workspace::create(['name' => 'O', 'slug' => 'o', 'status' => 'ACTIVE']);
        $foreign = User::factory()->create([
            'workspace_id' => $otherWs->id, 'role' => 'support_agent', 'status' => 'ACTIVE',
        ]);
        $contact = Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'K',
            'owner_id' => $agent->id, 'status' => 'ACTIVE', 'source' => 'MANUAL',
        ]);

        $this->actingAs($agent)
            ->putJson(route('admin.contacts.owner', $contact), ['owner_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_id');

        $this->assertSame($agent->id, $contact->fresh()->owner_id);
    }

    public function test_owner_cannot_be_set_to_disabled_user(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $disabled = User::factory()->create([
            'workspace_id' => $w->id, 'role' => 'support_agent', 'status' => 'DISABLED',
        ]);
        $contact = Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'K',
            'owner_id' => $agent->id, 'status' => 'ACTIVE', 'source' => 'MANUAL',
        ]);

        $this->actingAs($agent)
            ->putJson(route('admin.contacts.owner', $contact), ['owner_id' => $disabled->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_id');

        $this->assertSame($agent->id, $contact->fresh()->owner_id);
    }

    // ------------------------------------------------------------ note edit

    public function test_author_can_edit_own_note(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);
        $note = ContactNote::create([
            'workspace_id' => $w->id, 'contact_id' => $contact->id,
            'author_id' => $agent->id, 'body' => 'old', 'pinned' => false,
        ]);

        $this->actingAs($agent)
            ->putJson(route('admin.contacts.notes.update', $note), [
                'body' => 'new body', 'pinned' => true,
            ])
            ->assertRedirect();

        $fresh = $note->fresh();
        $this->assertSame('new body', $fresh->body);
        $this->assertTrue($fresh->pinned);
    }

    public function test_non_author_without_owner_role_cannot_edit_note(): void
    {
        ['workspace' => $w, 'agent' => $agent, 'otherAgent' => $other] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);
        $note = ContactNote::create([
            'workspace_id' => $w->id, 'contact_id' => $contact->id,
            'author_id' => $agent->id, 'body' => 'old', 'pinned' => false,
        ]);

        $this->actingAs($other)
            ->putJson(route('admin.contacts.notes.update', $note), ['body' => 'hijack'])
            ->assertForbidden();

        $this->assertSame('old', $note->fresh()->body);
    }

    public function test_owner_role_can_edit_any_note(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $owner = User::factory()->create(['workspace_id' => $w->id, 'role' => 'owner', 'status' => 'ACTIVE']);
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);
        $note = ContactNote::create([
            'workspace_id' => $w->id, 'contact_id' => $contact->id,
            'author_id' => $agent->id, 'body' => 'old', 'pinned' => false,
        ]);

        $this->actingAs($owner)
            ->putJson(route('admin.contacts.notes.update', $note), ['body' => 'edited'])
            ->assertRedirect();

        $this->assertSame('edited', $note->fresh()->body);
    }

    public function test_note_edit_requires_non_empty_body(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);
        $note = ContactNote::create([
            'workspace_id' => $w->id, 'contact_id' => $contact->id,
            'author_id' => $agent->id, 'body' => 'old', 'pinned' => false,
        ]);

        $this->actingAs($agent)
            ->putJson(route('admin.contacts.notes.update', $note), ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame('old', $note->fresh()->body);
    }

    // ------------------------------------------------------------ create lead from contact

    public function test_sales_can_create_lead_from_contact(): void
    {
        ['workspace' => $w, 'agent' => $agent, 'sales' => $sales] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $pipeline = Pipeline::create([
            'workspace_id' => $w->id, 'name' => 'Default', 'type' => 'LEAD', 'is_default' => true,
        ]);
        $stage = Stage::create([
            'workspace_id' => $w->id, 'pipeline_id' => $pipeline->id,
            'name' => 'Mới', 'status_group' => 'OPEN', 'sort_order' => 1,
        ]);

        $this->actingAs($sales)
            ->postJson(route('admin.contacts.leads.store', $contact), [
                'title' => 'Tư vấn gói Pro',
                'value_amount' => 5000000,
            ])
            ->assertRedirect(route('admin.leads'));

        $lead = Lead::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame('Tư vấn gói Pro', $lead->title);
        $this->assertSame('NEW', $lead->status);
        $this->assertSame('MANUAL', $lead->source); // lead's source ≠ contact's source (per spec)
        $this->assertSame($sales->id, $lead->owner_id);
        $this->assertSame($pipeline->id, $lead->pipeline_id);
        $this->assertSame($stage->id, $lead->stage_id);
        $this->assertSame('5000000.00', (string) $lead->value_amount);
    }

    public function test_support_agent_cannot_create_lead_from_contact(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $this->actingAs($agent)
            ->postJson(route('admin.contacts.leads.store', $contact), ['title' => 'no'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->assertSame(0, Lead::query()->where('contact_id', $contact->id)->count());
    }

    public function test_create_lead_without_pipeline_still_works(): void
    {
        // Bootstrap edge case: workspace has no LEAD pipeline yet. The lead is
        // created with null pipeline/stage — operator will drag it onto a
        // stage later when the pipeline lands.
        ['workspace' => $w, 'sales' => $sales] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $this->actingAs($sales)
            ->postJson(route('admin.contacts.leads.store', $contact), ['title' => 'Cold call'])
            ->assertRedirect(route('admin.leads'));

        $lead = Lead::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertNull($lead->pipeline_id);
        $this->assertNull($lead->stage_id);
    }

    public function test_create_lead_requires_title(): void
    {
        ['workspace' => $w, 'sales' => $sales] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $this->actingAs($sales)
            ->postJson(route('admin.contacts.leads.store', $contact), ['title' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_create_lead_rejects_negative_value_amount(): void
    {
        ['workspace' => $w, 'sales' => $sales] = $this->bootWorkspace();
        $contact = Contact::create(['workspace_id' => $w->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $this->actingAs($sales)
            ->postJson(route('admin.contacts.leads.store', $contact), [
                'title' => 'Test', 'value_amount' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value_amount');
    }

    // ------------------------------------------------------------ contact-show props

    public function test_contact_show_passes_owner_id_and_agents(): void
    {
        ['workspace' => $w, 'agent' => $agent] = $this->bootWorkspace();
        $contact = Contact::create([
            'workspace_id' => $w->id, 'full_name' => 'K',
            'owner_id' => $agent->id, 'status' => 'ACTIVE', 'source' => 'MANUAL',
        ]);

        $this->actingAs($agent)
            ->get(route('admin.contacts.show', $contact))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contact-show')
                ->where('contact.ownerId', $agent->id)
                ->has('agents', 3) // agent + otherAgent + sales; viewer excluded
                ->etc());
    }
}