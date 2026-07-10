<?php

namespace App\Modules\Channels\Http\Middleware;

use App\Modules\Channels\Models\ChannelAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the Zalo Personal sidecar to call the CRM through a loopback URL even
 * when public provider webhooks are domain-bound to webhook.qrf.vn.
 */
class EnsureLoopbackSidecarWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->route('channelAccount');

        // The local fallback route is ONLY for the ZALO_PERSONAL sidecar. Public
        // providers must keep using the dedicated webhook host route.
        abort_unless($account instanceof ChannelAccount && $account->provider === 'ZALO_PERSONAL', 404);

        $remoteAddr = (string) $request->server('REMOTE_ADDR', '');
        $clientIp = (string) $request->ip();
        $isLoopback = in_array($remoteAddr, ['127.0.0.1', '::1'], true)
            || in_array($clientIp, ['127.0.0.1', '::1'], true);

        abort_unless($isLoopback || app()->environment(['local', 'testing']), 404);

        return $next($request);
    }
}
