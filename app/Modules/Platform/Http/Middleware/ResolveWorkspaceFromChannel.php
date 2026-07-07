<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhooks arrive from provider servers with no tenant subdomain. The tenant is
 * derived from the target channel account instead. Resolves the account without
 * the workspace scope, pins its workspace as current, and hands the model to the
 * controller so downstream writes are correctly scoped.
 */
class ResolveWorkspaceFromChannel
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->route('channelAccount');

        $account = ChannelAccount::query()
            ->withoutWorkspaceScope()
            ->whereKey($key)
            ->first();

        abort_if($account === null, 404);

        $this->current->forId($account->workspace_id);

        // Replace the raw key with the resolved model for the controller.
        $request->route()->setParameter('channelAccount', $account);

        return $next($request);
    }
}
