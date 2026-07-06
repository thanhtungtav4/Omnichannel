<?php

namespace App\Modules\Channels\Jobs;

use App\Modules\Channels\Models\ChannelAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Refresh a Zalo OA access token before it expires (spec 05 token lifecycle).
 * On failure the account is marked DEGRADED so the admin cockpit surfaces it.
 *
 * Zalo OA token endpoint: https://oauth.zaloapp.com/v4/oa/access_token
 */
class RefreshZaloAccessTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $channelAccountId) {}

    public function handle(): void
    {
        $account = ChannelAccount::find($this->channelAccountId);
        if (! $account || $account->provider !== 'ZALO_OA') {
            return;
        }

        $creds = $account->credentials ?? [];
        $refreshToken = Arr::get($creds, 'refresh_token');
        $appId = Arr::get($creds, 'app_id');
        $appSecret = Arr::get($creds, 'app_secret');

        if (! $refreshToken || ! $appId || ! $appSecret) {
            $this->degrade($account, 'MISSING_REFRESH_CREDENTIALS', 'Refresh token / app credentials missing.');

            return;
        }

        $response = Http::asForm()
            ->withHeaders(['secret_key' => $appSecret])
            ->timeout(15)
            ->post('https://oauth.zaloapp.com/v4/oa/access_token', [
                'refresh_token' => $refreshToken,
                'app_id' => $appId,
                'grant_type' => 'refresh_token',
            ]);

        $body = $response->json();
        $newAccess = Arr::get($body, 'access_token');
        $newRefresh = Arr::get($body, 'refresh_token');
        $expiresIn = (int) Arr::get($body, 'expires_in', 0);

        if (! $response->successful() || ! $newAccess) {
            $this->degrade(
                $account,
                (string) (Arr::get($body, 'error') ?: $response->status()),
                (string) (Arr::get($body, 'error_name') ?: Arr::get($body, 'message') ?: 'Zalo token refresh failed.'),
            );

            return;
        }

        $account->forceFill([
            'credentials' => array_merge($creds, [
                'access_token' => $newAccess,
                'refresh_token' => $newRefresh ?: $refreshToken,
            ]),
            'settings' => array_merge($account->settings ?? [], [
                'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn)->toIso8601String() : null,
            ]),
            'status' => 'ACTIVE',
            'last_health_check_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
    }

    private function degrade(ChannelAccount $account, string $code, string $message): void
    {
        $account->forceFill([
            'status' => 'DEGRADED',
            'last_health_check_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => $message,
        ])->save();
    }
}
