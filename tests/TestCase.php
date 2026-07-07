<?php

namespace Tests;

use App\Modules\Platform\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A default tenant so route('...') resolves {tenant} in guest/unauthed
        // tests; actingAs() re-points the host to the acting user's workspace.
        Workspace::query()->firstOrCreate(['slug' => 'tenant-base'], ['name' => 'Base', 'status' => 'ACTIVE']);
        $this->tenant('tenant-base');
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Auth as a user and pin the tenant host/URL to that user's workspace, so
     * {tenant} routes resolve and the ResolveWorkspace/member guards pass.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        $slug = $user->workspace_id
            ? Workspace::query()->whereKey($user->workspace_id)->value('slug')
            : null;

        if ($slug !== null) {
            $this->tenant($slug);
        }

        return $this;
    }

    /** Route all subsequent requests + generated URLs through a tenant subdomain. */
    protected function tenant(string $slug): static
    {
        $host = $slug.'.'.config('tenant.domain');

        URL::forceRootUrl('https://'.$host);
        $this->withServerVariables(['HTTP_HOST' => $host, 'HTTPS' => 'on']);

        return $this;
    }
}
