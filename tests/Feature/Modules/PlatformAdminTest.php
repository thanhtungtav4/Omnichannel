<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(string $path = '/admin/workspaces'): string
    {
        return 'https://'.config('tenant.admin_subdomain').'.'.config('tenant.domain').$path;
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'workspace_id' => null,
            'is_platform_admin' => true,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_platform_admin_can_view_workspaces(): void
    {
        $this->actingAs($this->platformAdmin());

        $this->get($this->adminHost())->assertOk();
    }

    public function test_tenant_user_cannot_access_platform_console(): void
    {
        $ws = Workspace::create(['name' => 'T', 'slug' => 'tenant-x', 'status' => 'ACTIVE']);
        $user = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'owner', 'status' => 'ACTIVE']);

        $this->actingAs($user);

        $this->get($this->adminHost())->assertForbidden();
    }

    public function test_platform_admin_creates_workspace_with_owner(): void
    {
        $this->actingAs($this->platformAdmin());

        $this->post($this->adminHost(), [
            'name' => 'Acme Corp',
            'slug' => 'acme',
            'owner_name' => 'Owner One',
            'owner_email' => 'owner@acme.test',
            'owner_password' => 'password1234',
            'owner_password_confirmation' => 'password1234',
        ])->assertRedirect();

        $workspace = Workspace::query()->where('slug', 'acme')->firstOrFail();
        $this->assertSame('ACTIVE', $workspace->status);

        $owner = User::query()->where('email', 'owner@acme.test')->firstOrFail();
        $this->assertSame($workspace->id, $owner->workspace_id);
        $this->assertSame('owner', $owner->role);
        $this->assertFalse((bool) $owner->is_platform_admin);
    }

    public function test_reserved_and_duplicate_slugs_are_rejected(): void
    {
        Workspace::create(['name' => 'Existing', 'slug' => 'taken', 'status' => 'ACTIVE']);
        $this->actingAs($this->platformAdmin());

        $payload = fn (string $slug) => [
            'name' => 'X', 'slug' => $slug,
            'owner_name' => 'O', 'owner_email' => uniqid().'@x.test',
            'owner_password' => 'password1234', 'owner_password_confirmation' => 'password1234',
        ];

        $this->post($this->adminHost(), $payload(config('tenant.admin_subdomain')))
            ->assertSessionHasErrors('slug');
        $this->post($this->adminHost(), $payload('Bad Slug'))
            ->assertSessionHasErrors('slug');
        $this->post($this->adminHost(), $payload('taken'))
            ->assertSessionHasErrors('slug');
    }

    public function test_console_creates_platform_admin_command(): void
    {
        $this->artisan('platform-admin:create', [
            '--name' => 'Root',
            '--email' => 'root@platform.test',
            '--password' => 'password1234',
        ])->assertSuccessful();

        $admin = User::query()->where('email', 'root@platform.test')->firstOrFail();
        $this->assertTrue((bool) $admin->is_platform_admin);
        $this->assertNull($admin->workspace_id);
    }
}
