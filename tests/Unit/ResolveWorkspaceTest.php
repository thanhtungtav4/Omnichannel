<?php

namespace Tests\Unit;

use App\Modules\Platform\Http\Middleware\ResolveWorkspace;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ResolveWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_subdomain_is_reserved_and_not_resolved_as_workspace(): void
    {
        config([
            'tenant.domain' => 'qrf.vn',
            'tenant.admin_subdomain' => 'admin',
            'tenant.webhook_subdomain' => 'webhook',
        ]);
        Workspace::create(['slug' => 'webhook', 'name' => 'Should Not Resolve', 'status' => 'ACTIVE']);

        $current = app(CurrentWorkspace::class);
        $current->set(null);
        $request = Request::create('https://webhook.qrf.vn/webhooks/zalo/account-id', 'POST');
        $middleware = new ResolveWorkspace($current);

        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($current->id());
    }

    public function test_tenant_subdomain_still_resolves_workspace(): void
    {
        config([
            'tenant.domain' => 'qrf.vn',
            'tenant.admin_subdomain' => 'admin',
            'tenant.webhook_subdomain' => 'webhook',
        ]);
        $workspace = Workspace::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'ACTIVE']);

        $current = app(CurrentWorkspace::class);
        $current->set(null);
        $request = Request::create('https://acme.qrf.vn/admin', 'GET');
        $middleware = new ResolveWorkspace($current);

        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($workspace->id, $current->id());
    }
}
