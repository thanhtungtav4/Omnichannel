<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant boundary guard. A logged-in user may only act inside their own
 * workspace's subdomain. Mismatch = logout + 403 (no silent cross-tenant
 * access). Platform admins have no workspace and are rejected here — they use
 * the admin console host, not tenant hosts.
 */
class EnsureUserBelongsToWorkspace
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ((string) $user->workspace_id !== (string) $this->current->id()) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'This account does not belong to this workspace.');
        }

        return $next($request);
    }
}
