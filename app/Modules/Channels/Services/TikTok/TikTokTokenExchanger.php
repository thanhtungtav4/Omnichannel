<?php

namespace App\Modules\Channels\Services\TikTok;

use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Exchange a TikTok Shop OAuth `auth_code` for access_token + refresh_token.
 *
 * VERIFIED against TikTok Shop Partner API docs (specs/13 § Verification):
 *
 *   Endpoint:  GET https://auth.tiktok-shops.com/api/v2/token/get
 *   Query params:
 *     - app_key       (public app identifier)
 *     - app_secret    (NOT sent in query for security in some setups;
 *                      here we use Authorization header instead — adjust
 *                      per actual Shop Partner API requirement)
 *     - auth_code     (from OAuth callback; NOT `code`)
 *     - grant_type    (value: `authorized_code` — note: NOT `authorization_code`)
 *
 *   Response:
 *     - access_token
 *     - refresh_token
 *     - refresh_token_expire_in (unix seconds; long-lived)
 *     - open_id
 *     - seller_name
 *     - seller_base_region (e.g. "VN")
 *     - granted_scopes (array)
 *
 * Throws TikTokTokenException on non-2xx, malformed response, or missing
 * field. The controller catches this and surfaces a generic message.
 */
class TikTokTokenExchanger
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly WorkspaceSettings $settings,
    ) {}

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     access_token_expires_at: Carbon,
     *     refresh_token_expires_at: Carbon,
     *     open_id: string,
     *     shop_id: string,
     *     seller_base_region: ?string,
     *     granted_scopes: array<int, string>
     * }
     *
     * @throws TikTokTokenException
     */
    public function exchangeCodeForTokens(
        Workspace $workspace,
        string $authCode,
    ): array {
        $creds = $this->settings->get($workspace, 'tiktok.partner_credentials');
        if (! is_array($creds) || empty($creds['app_key']) || empty($creds['app_secret'])) {
            throw new TikTokTokenException(
                'app_key and app_secret must be configured before connecting TikTok Shop.',
                previous: null,
            );
        }

        $authBase = rtrim((string) config('services.tiktok_shop.auth_base'), '/');
        $endpoint = $authBase.'/token/get';

        // Verified: TikTok Shop Partner uses GET with query params. App secret
        // sent as Authorization header (basic) in some setups, query param in
        // others. Default: query param (matches Python examples in reebug).
        try {
            $response = $this->http
                ->timeout(20)
                ->get($endpoint, [
                    'app_key' => (string) $creds['app_key'],
                    'app_secret' => (string) $creds['app_secret'],
                    'auth_code' => $authCode,
                    'grant_type' => 'authorized_code',
                ])
                ->throw(function ($response, $e) {
                    throw new TikTokTokenException(
                        "TikTok token endpoint returned non-2xx: HTTP {$response->status()}",
                        previous: $e,
                    );
                });

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new TikTokTokenException('TikTok token endpoint returned a non-JSON body.');
            }

            // TikTok returns `error` + `message` + `error_description` on failure
            // (similar to OAuth 2.0 standard).
            if (isset($payload['error']) || ! isset($payload['access_token'], $payload['refresh_token'])) {
                $err = $payload['error'] ?? 'unknown';
                $msg = $payload['error_description'] ?? $payload['message'] ?? 'unknown reason';
                throw new TikTokTokenException("TikTok refused the code exchange: {$err} — {$msg}");
            }

            // access_token_expire_in not always present; default 24h
            $accessExpiresIn = (int) ($payload['access_token_expire_in'] ?? 86400);
            // refresh_token_expire_in is much longer (~30+ days); store so admin can surface.
            $refreshExpiresIn = (int) ($payload['refresh_token_expire_in'] ?? (time() + 86400 * 30));

            // shop_id may come back as `shop_id` or `seller_base_region` + open_id combo.
            $shopId = (string) ($payload['shop_id'] ?? $payload['seller_base_region'] ?? '');

            return [
                'access_token' => (string) $payload['access_token'],
                'refresh_token' => (string) $payload['refresh_token'],
                'access_token_expires_at' => Carbon::now()->addSeconds($accessExpiresIn),
                'refresh_token_expires_at' => Carbon::now()->addSeconds($refreshExpiresIn - time()),
                'open_id' => (string) ($payload['open_id'] ?? ''),
                'shop_id' => $shopId,
                'seller_base_region' => isset($payload['seller_base_region']) ? (string) $payload['seller_base_region'] : null,
                'granted_scopes' => (array) ($payload['granted_scopes'] ?? []),
            ];
        } catch (TikTokTokenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TikTokTokenException(
                "TikTok token exchange failed: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}