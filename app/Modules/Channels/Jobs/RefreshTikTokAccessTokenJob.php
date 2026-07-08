<?php

namespace App\Modules\Channels\Jobs;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\TikTok\TikTokTokenException;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Refresh a TikTok Shop Chat VN access token before it expires (specs/13
 * § Token lifecycle, W2 G1.1). Runs at 75% of TTL.
 *
 * VERIFIED against TikTok Shop Partner API docs:
 *   Endpoint: GET https://auth.tiktok-shops.com/api/v2/token/get
 *   Query params:
 *     - app_key
 *     - app_secret
 *     - refresh_token
 *     - grant_type (value: `refresh_token`)
 *
 * Response: same shape as code exchange — access_token, refresh_token,
 * refresh_token_expire_in, etc.
 *
 * On success: tokens rotate atomically; channel account stays ACTIVE.
 * On failure: channel account flips to DEGRADED with last_error_code =
 * REAUTH_REQUIRED so the admin UI surfaces the reconnect button.
 */
class RefreshTikTokAccessTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $channelAccountId) {}

    public function handle(): void
    {
        $account = ChannelAccount::query()
            ->withoutWorkspaceScope()
            ->whereKey($this->channelAccountId)
            ->first();

        if ($account === null || $account->provider !== 'TIKTOK_SHOP') {
            return;
        }

        $creds = $account->credentials ?? [];
        $refreshToken = Arr::get($creds, 'refresh_token');
        if (! is_string($refreshToken) || $refreshToken === '') {
            $this->markReauthRequired($account, 'MISSING_REFRESH_TOKEN', 'Refresh token not stored.');

            return;
        }

        $workspace = Workspace::query()->whereKey($account->workspace_id)->first();
        if ($workspace === null) {
            $this->markReauthRequired($account, 'WORKSPACE_GONE', 'Workspace no longer exists.');

            return;
        }

$partnerCreds = app(WorkspaceSettings::class)->get($workspace, 'tiktok.partner_credentials');
        if (! is_array($partnerCreds) || empty($partnerCreds['app_key']) || empty($partnerCreds['app_secret'])) {
            $this->markReauthRequired($account, 'MISSING_PARTNER_CREDENTIALS', 'Partner credentials not configured.');

            return;
        }

        $authBase = rtrim((string) config('services.tiktok_shop.auth_base'), '/');
        $endpoint = $authBase.'/token/get';

        try {
            $response = app(HttpClient::class)
                ->timeout(15)
                ->get($endpoint, [
                    'app_key' => (string) $partnerCreds['app_key'],
                    'app_secret' => (string) $partnerCreds['app_secret'],
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);

            if (! $response->successful()) {
                $body = $response->json();
                $code = (string) (Arr::get($body, 'error') ?? 'HTTP_'.$response->status());
                $msg = (string) (Arr::get($body, 'error_description') ?? 'TikTok refused token refresh.');
                $this->markReauthRequired($account, $code, $msg);

                return;
            }

            $payload = $response->json();
            $newAccess = Arr::get($payload, 'access_token');
            $newRefresh = Arr::get($payload, 'refresh_token');
            $accessExpiresIn = (int) Arr::get($payload, 'access_token_expire_in', 86400);
            $refreshExpiresIn = (int) Arr::get($payload, 'refresh_token_expire_in', time() + 86400 * 30);

            if (! is_string($newAccess) || $newAccess === '') {
                $this->markReauthRequired($account, 'NO_ACCESS_TOKEN', 'TikTok returned no access_token.');

                return;
            }

            $account->forceFill([
                'credentials' => array_merge($creds, [
                    'access_token' => $newAccess,
                    'refresh_token' => is_string($newRefresh) && $newRefresh !== '' ? $newRefresh : $refreshToken,
                    'access_token_expires_at' => Carbon::now()->addSeconds($accessExpiresIn)->toIso8601String(),
                    'refresh_token_expires_at' => Carbon::now()->addSeconds($refreshExpiresIn - time())->toIso8601String(),
                ]),
                'status' => 'ACTIVE',
                'last_health_check_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
        } catch (TikTokTokenException $e) {
            Log::warning('TikTok token refresh failed', [
                'channel_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
            $this->markReauthRequired($account, 'EXCEPTION', $e->getMessage());
        }
    }

    private function markReauthRequired(ChannelAccount $account, string $code, string $message): void
    {
        $account->forceFill([
            'status' => 'DEGRADED',
            'last_health_check_at' => now(),
            'last_error_code' => 'REAUTH_REQUIRED',
            'last_error_message' => "{$code}: {$message}",
        ])->save();

        Log::warning('TikTok channel account marked REAUTH_REQUIRED', [
            'channel_account_id' => $account->id,
            'reason' => $code,
            'detail' => $message,
        ]);
    }
}