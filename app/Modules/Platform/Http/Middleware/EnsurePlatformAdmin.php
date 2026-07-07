<?php

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the out-of-tenant platform admin console (admin.qrf.vn).
 * Only users flagged is_platform_admin (workspace_id = NULL) may enter.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->is_platform_admin, 403);

        return $next($request);
    }
}
