<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_subdomain_is_not_found(): void
    {
        $this->tenant('does-not-exist');

        $this->get('https://does-not-exist.'.config('tenant.domain').'/admin')
            ->assertNotFound();
    }

    public function test_user_cannot_access_another_workspace_subdomain(): void
    {
        $home = Workspace::create(['name' => 'Home', 'slug' => 'home', 'status' => 'ACTIVE']);
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'status' => 'ACTIVE']);
        $user = User::factory()->create(['workspace_id' => $home->id, 'role' => 'owner', 'status' => 'ACTIVE']);

        $this->actingAs($user)->tenant('other');

        $this->get('https://other.'.config('tenant.domain').'/admin')
            ->assertForbidden();
    }

    public function test_global_scope_isolates_reads_between_workspaces(): void
    {
        $a = Workspace::create(['name' => 'A', 'slug' => 'wa', 'status' => 'ACTIVE']);
        $b = Workspace::create(['name' => 'B', 'slug' => 'wb', 'status' => 'ACTIVE']);
        $current = app(CurrentWorkspace::class);

        $current->run($a, fn () => Contact::create(['full_name' => 'A One', 'status' => 'ACTIVE', 'source' => 'MANUAL']));
        $current->run($b, fn () => Contact::create(['full_name' => 'B One', 'status' => 'ACTIVE', 'source' => 'MANUAL']));

        $this->assertSame(['A One'], $current->run($a, fn () => Contact::pluck('full_name')->all()));
        $this->assertSame(['B One'], $current->run($b, fn () => Contact::pluck('full_name')->all()));
    }

    public function test_create_auto_stamps_current_workspace(): void
    {
        $ws = Workspace::create(['name' => 'Stamp', 'slug' => 'stamp', 'status' => 'ACTIVE']);
        $current = app(CurrentWorkspace::class);

        $contact = $current->run($ws, fn () => Contact::create([
            'full_name' => 'Stamped', 'status' => 'ACTIVE', 'source' => 'MANUAL',
        ]));

        $this->assertSame($ws->id, $contact->workspace_id);
    }
}
