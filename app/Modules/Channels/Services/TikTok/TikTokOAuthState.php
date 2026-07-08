<?php

namespace App\Modules\Channels\Services\TikTok;

use App\Modules\Platform\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * OAuth state token for TikTok Shop Chat VN.
 *
 * Mirrors `ShopeeOAuthState` exactly — single-use, workspace-scoped,
 * TTL-bounded. The shape is provider-agnostic; reuse the same pattern if
 * a future connector (Line, Viber, etc.) needs OAuth with a state token.
 *
 * Flow:
 *   1. TikTokOAuthController::connect calls `issue($workspace)` → returns
 *      a random opaque token string.
 *   2. Token is stored in cache (TTL 10 min) with workspace_id.
 *   3. TikTok redirects back to /callback with `state=...`.
 *   4. TikTokOAuthController::callback calls `consume($state)` → returns
 *      workspace_id and atomically invalidates the token (one-shot).
 *
 * Cache::pull handles the single-use guarantee — second call returns null
 * even if TTL hasn't expired.
 *
 * OPEN SPIKE (spec 13 § Verification): TikTok's OAuth redirect may use a
 * different `state` parameter name or include extra fields. Update this
 * class when confirmed.
 */
class TikTokOAuthState
{
    private const CACHE_KEY_PREFIX = 'tiktok:oauth:state:';

    private const TTL_SECONDS = 600; // 10 min — matches Shopee + standard OAuth window

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
     * @throws InvalidTikTokStateException
     */
    public function consume(string $token): array
    {
        $key = self::CACHE_KEY_PREFIX.$token;

        $payload = Cache::pull($key);

        if ($payload === null) {
            throw new InvalidTikTokStateException(
                'OAuth state token is missing, expired, or has already been used.',
            );
        }

        if (! is_array($payload) || ! isset($payload['workspace_id'])) {
            throw new InvalidTikTokStateException('OAuth state payload is malformed.');
        }

        return $payload;
    }
}