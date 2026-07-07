<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the tenant app: these routes only exist on a resolved tenant subdomain.
 * On the apex or admin host (no workspace pinned) they 404, so tenant URLs never
 * leak onto non-tenant hosts.
 */
class RequireWorkspace
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->current->has(), 404);

        return $next($request);
    }
}
