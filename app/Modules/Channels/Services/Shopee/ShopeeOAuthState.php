<?php

namespace App\Modules\Channels\Services\Shopee;

use App\Modules\Platform\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * OAuth state token for Shopee Chat VN.
 *
 * Why a separate helper instead of `URL::signedRoute()`:
 *   - state must be single-use (a replayed callback from a stolen code
 *     should fail). SignedRoute doesn't track use.
 *   - state must encode the workspace_id so the callback knows which tenant
 *     to attach the tokens to without relying on session/auth state.
 *   - state must expire within minutes so a leaked URL can't be used forever.
 *
 * Flow:
 *   1. ShopeeOAuthController::connect calls `issue($workspace)` → returns a
 *      random opaque token string.
 *   2. Token is stored in cache (TTL 10 min) with workspace_id.
 *   3. Shopee redirects back to /callback with `state=...`.
 *   4. ShopeeOAuthController::callback calls `consume($state)` → returns
 *      workspace_id and atomically invalidates the token (one-shot).
 *
 * Failure modes:
 *   - token missing / unknown: throw InvalidShopeeStateException
 *   - token already consumed: same (Cache::pull handles this — second call
 *     gets null even if TTL hasn't expired)
 *   - token expired: cache returns null after 10 min → invalid
 */
class ShopeeOAuthState
{
    private const CACHE_KEY_PREFIX = 'shopee:oauth:state:';

    private const TTL_SECONDS = 600; // 10 min — Shopee's recommended window

    public function issue(Workspace $workspace): string
    {
        $token = Str::random(48);

        Cache::put(
            self::CACHE_KEY_PREFIX.$token,
            ['workspace_id' => $workspace->id, 'issued_at' => time()],
            self::TTL_SECONDS,
        );

        return $token;
    }

    /**
     * @return array{workspace_id: string, issued_at: int}
     *
     * @throws InvalidShopeeStateException
     */
    public function consume(string $token): array
    {
        $key = self::CACHE_KEY_PREFIX.$token;

        // Cache::pull is atomic: read + delete in one op. Second call returns
        // null even if TTL hasn't expired — that's the single-use guarantee.
        $payload = Cache::pull($key);

        if ($payload === null) {
            throw new InvalidShopeeStateException(
                'OAuth state token is missing, expired, or has already been used.',
            );
        }

        if (! is_array($payload) || ! isset($payload['workspace_id'])) {
            throw new InvalidShopeeStateException('OAuth state payload is malformed.');
        }

        return $payload;
    }
}