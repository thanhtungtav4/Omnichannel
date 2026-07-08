<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the out-of-tenant platform admin console (admin.qrf.vn).
 * Only users flagged is_platform_admin (workspace_id = NULL) may enter.
 *
 * A signed-in *tenant* user landing here is not forbidden — they just hit the
 * wrong host. Bounce them to their own workspace app instead of a bare 403.
 * Guests and orphaned users (no workspace) still get 403.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->is_platform_admin) {
            return $next($request);
        }

        if ($user && $user->workspace_id) {
            $slug = Workspace::whereKey($user->workspace_id)->value('slug');

            if ($slug) {
                $domain = config('tenant.domain', 'qrf.vn');

                return redirect()->away("https://{$slug}.{$domain}/admin");
            }
        }

        abort(403);
    }
}
