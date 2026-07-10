<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the active tenant from the request subdomain ({slug}.qrf.vn).
 *
 * Runs before auth on every web request. Non-tenant hosts (apex, admin console,
 * webhook host) pass through with no workspace set. A tenant-shaped host with an
 * unknown or suspended slug is a hard 404 — no cross-tenant fallback.
 */
class ResolveWorkspace
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->tenantSlug($request);

        if ($slug === null) {
            return $next($request);
        }

        $workspace = Workspace::query()
            ->where('slug', $slug)
            ->where('status', 'ACTIVE')
            ->first();

        abort_if($workspace === null, 404);

        $this->current->set($workspace);

        return $next($request);
    }

    /** Leftmost label if the host is an immediate subdomain of the tenant root. */
    private function tenantSlug(Request $request): ?string
    {
        $root = config('tenant.domain');
        $host = $request->getHost();

        if ($host === $root || ! str_ends_with($host, '.'.$root)) {
            return null;
        }

        $label = substr($host, 0, -strlen('.'.$root));

        // Only the immediate subdomain is a tenant; reject nested/reserved hosts.
        $reserved = array_filter([
            config('tenant.admin_subdomain'),
            config('tenant.webhook_subdomain'),
        ]);

        if ($label === '' || str_contains($label, '.') || in_array($label, $reserved, true)) {
            return null;
        }

        return $label;
    }
}
