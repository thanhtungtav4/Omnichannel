<?php

namespace App\Modules\Channels\Services\Shopee;

use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Exchange a Shopee OAuth `code` for access_token + refresh_token, signing
 * the request with HMAC-SHA256 per Shopee's partner authentication scheme.
 *
 * Throws ShopeeTokenException on any non-2xx, malformed response, or missing
 * field. The controller catches this and surfaces a generic message to the
 * admin (the specific reason is logged).
 *
 * Spec: docs/OPS_WEBHOOKS.md and specs/11_SHOPEE_CHAT_VN.md § Token lifecycle.
 */
class ShopeeTokenExchanger
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
     *     shop_id: int,
     *     merchant_id: ?string
     * }
     *
     * @throws ShopeeTokenException
     */
    public function exchangeCodeForTokens(
        Workspace $workspace,
        string $code,
        string $redirectUri,
    ): array {
        $creds = $this->settings->get($workspace, 'shopee.partner_credentials');
        if (! is_array($creds) || empty($creds['partner_id']) || empty($creds['partner_key'])) {
            throw new ShopeeTokenException(
                'partner_id and partner_key must be configured before connecting Shopee.',
                previous: null,
            );
        }

        $base = rtrim((string) config('services.shopee.api_base'), '/');
        $path = '/auth/token/get';
        $timestamp = time();

        // Shopee partner auth: sign `${path}|${timestamp}|${partner_id}|${access_token_or_empty}`
        // For the initial code exchange, access_token is empty.
        $signature = hash_hmac(
            'sha256',
            $path.'|'.$timestamp.'|'.$creds['partner_id'].'|',
            $creds['partner_key'],
        );

        $response = $this->http
            ->asForm()
            ->post($base.$path, [
                'code' => $code,
                'partner_id' => (int) $creds['partner_id'],
                'redirect' => $redirectUri,
                'timestamp' => $timestamp,
                'sign' => $signature,
            ])
            ->throw(function ($response, $e) {
                throw new ShopeeTokenException(
                    "Shopee token endpoint returned non-2xx: HTTP {$response->status()}",
                    previous: $e,
                );
            });

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ShopeeTokenException('Shopee token endpoint returned a non-JSON body.');
        }

        // Shopee returns errors with an `error` field (e.g. "invalid_code",
        // "expired_code", "invalid_partner_id"). Anything other than the
        // expected fields is treated as a failure.
        if (isset($payload['error']) || ! isset($payload['access_token'], $payload['refresh_token'])) {
            $err = $payload['error'] ?? 'unknown';
            $msg = $payload['message'] ?? $payload['error_description'] ?? 'unknown reason';
            throw new ShopeeTokenException("Shopee refused the code exchange: {$err} — {$msg}");
        }

        $expiresIn = (int) ($payload['expire_in'] ?? 14400); // Shopee default 4h

        return [
            'access_token' => (string) $payload['access_token'],
            'refresh_token' => (string) $payload['refresh_token'],
            'access_token_expires_at' => Carbon::now()->addSeconds($expiresIn),
            'shop_id' => (int) ($payload['shop_id'] ?? 0),
            'merchant_id' => isset($payload['merchant_id']) ? (string) $payload['merchant_id'] : null,
        ];
    }
}