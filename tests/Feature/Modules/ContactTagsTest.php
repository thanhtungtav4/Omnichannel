<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_set_tags_deduped_and_trimmed(): void
    {
        $ws = Workspace::create(['name' => 'W', 'slug' => 'w', 'status' => 'ACTIVE']);
        $agent = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        $contact = Contact::create(['workspace_id' => $ws->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $this->actingAs($agent)
            ->put(route('admin.contacts.tags', $contact), [
                'tags' => ['VIP', ' vip ', 'Nợ tiền', ''],
            ])
            ->assertRedirect();

        // "VIP" and " vip " collapse to one; blank dropped.
        $this->assertSame(['VIP', 'Nợ tiền'], $contact->fresh()->tags);
    }

    public function test_tags_from_another_workspace_are_forbidden(): void
    {
        $ws1 = Workspace::create(['name' => 'A', 'slug' => 'a', 'status' => 'ACTIVE']);
        $ws2 = Workspace::create(['name' => 'B', 'slug' => 'b', 'status' => 'ACTIVE']);
        $agent = User::factory()->create(['workspace_id' => $ws1->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        $other = Contact::create(['workspace_id' => $ws2->id, 'full_name' => 'K', 'status' => 'ACTIVE', 'source' => 'MANUAL']);

        $this->actingAs($agent)
            ->put(route('admin.contacts.tags', $other), ['tags' => ['x']])
            ->assertForbidden();
    }
}
