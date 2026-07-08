<?php

namespace App\Modules\Channels\Jobs;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\Shopee\ShopeeTokenException;
use App\Modules\Channels\Services\Shopee\ShopeeTokenExchanger;
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
 * Refresh a Shopee Chat VN access token before it expires (spec 11 § Token
 * lifecycle, W2 G1.1). Runs at 75% of TTL.
 *
 * On success: tokens rotate atomically; channel account stays ACTIVE.
 * On failure: channel account flips to DEGRADED with last_error_code =
 * REAUTH_REQUIRED so the admin UI surfaces the reconnect button.
 *
 * Unlike the Zalo equivalent, Shopee needs HMAC-signed requests and a
 * workspace-scoped partner key — both come from workspace_settings.
 */
class RefreshShopeeAccessTokenJob implements ShouldQueue
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

        if ($account === null || $account->provider !== 'SHOPEE') {
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

        $partnerCreds = app(WorkspaceSettings::class)->get($workspace, 'shopee.partner_credentials');
        if (! is_array($partnerCreds) || empty($partnerCreds['partner_id']) || empty($partnerCreds['partner_key'])) {
            $this->markReauthRequired($account, 'MISSING_PARTNER_CREDENTIALS', 'Partner credentials not configured.');

            return;
        }

        $base = rtrim((string) config('services.shopee.api_base'), '/');
        $path = '/auth/access_token/get';
        $timestamp = time();
        $signature = hash_hmac(
            'sha256',
            $path.'|'.$timestamp.'|'.$partnerCreds['partner_id'].'|'.$refreshToken,
            $partnerCreds['partner_key'],
        );

        try {
            $response = app(HttpClient::class)
                ->asForm()
                ->timeout(15)
                ->post($base.$path, [
                    'refresh_token' => $refreshToken,
                    'partner_id' => (int) $partnerCreds['partner_id'],
                    'timestamp' => $timestamp,
                    'sign' => $signature,
                ]);

            if (! $response->successful()) {
                $body = $response->json();
                $code = (string) (Arr::get($body, 'error') ?? 'HTTP_'.$response->status());
                $msg = (string) (Arr::get($body, 'message') ?? Arr::get($body, 'error_description') ?? 'Shopee refused token refresh.');
                $this->markReauthRequired($account, $code, $msg);

                return;
            }

            $payload = $response->json();
            $newAccess = Arr::get($payload, 'access_token');
            $newRefresh = Arr::get($payload, 'refresh_token');
            $expiresIn = (int) Arr::get($payload, 'expire_in', 14400);

            if (! is_string($newAccess) || $newAccess === '') {
                $this->markReauthRequired($account, 'NO_ACCESS_TOKEN', 'Shopee returned no access_token.');

                return;
            }

            $account->forceFill([
                'credentials' => array_merge($creds, [
                    'access_token' => $newAccess,
                    'refresh_token' => is_string($newRefresh) && $newRefresh !== '' ? $newRefresh : $refreshToken,
                    'access_token_expires_at' => Carbon::now()->addSeconds($expiresIn)->toIso8601String(),
                ]),
                'status' => 'ACTIVE',
                'last_health_check_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
        } catch (ShopeeTokenException $e) {
            Log::warning('Shopee token refresh failed', [
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

        Log::warning('Shopee channel account marked REAUTH_REQUIRED', [
            'channel_account_id' => $account->id,
            'reason' => $code,
            'detail' => $message,
        ]);
    }
}