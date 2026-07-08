<?php

namespace App\Modules\Channels\Http\Middleware;

use App\Modules\Channels\Models\ChannelAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify Shopee Open Platform webhook signature.
 *
 * VERIFIED against Shopee Open Platform docs (https://open.shopee.com/developer-guide/18
 * and rollout.com integration guide):
 *
 *   Header:   X-Shopee-Signature  (lowercase, hyphenated)
 *   Value:    <hex_digest>        (bare hex, NO prefix like "sha256=" or "HMAC-SHA256")
 *   Algorithm: HMAC-SHA256 over the RAW request body, keyed by webhook_secret
 *
 * Mismatch / missing / wrong header → 401 INVALID_SIGNATURE. Secret value
 * never logged. Constant-time compare via hash_equals.
 *
 * Note: This middleware exists for Shopee's NEW webhook format. If Shopee
 * ever changes the header name or signature scheme (e.g. rotates to v2
 * with a timestamp prefix), update here AND the OPS_WEBHOOKS runbook.
 */
class VerifyShopeeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->route('channelAccount');

        if (! $account instanceof ChannelAccount) {
            return response()->json([
                'error' => ['code' => 'CHANNEL_NOT_RESOLVED', 'message' => 'Channel account not resolved.'],
            ], 500);
        }

        $secret = (string) $account->webhook_secret;
        if ($secret === '') {
            // Defense in depth: a misconfigured channel account (no secret set)
            // must not silently accept unsigned webhooks. Refuse loudly so ops
            // sees it during a fresh connect.
            return response()->json([
                'error' => ['code' => 'MISSING_WEBHOOK_SECRET', 'message' => 'Webhook secret is not configured.'],
            ], 401);
        }

        // Shopee sends signature in X-Shopee-Signature header as bare hex digest.
        $signature = (string) $request->header('X-Shopee-Signature', '');
        if ($signature === '') {
            return response()->json([
                'error' => ['code' => 'MISSING_SIGNATURE', 'message' => 'Missing X-Shopee-Signature header.'],
            ], 401);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            return response()->json([
                'error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Signature mismatch.'],
            ], 401);
        }

        return $next($request);
    }
}