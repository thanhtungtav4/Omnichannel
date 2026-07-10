<?php

namespace App\Modules\Channels\Services;

use App\Modules\Channels\Jobs\RefreshShopeeAccessTokenJob;
use App\Modules\Channels\Models\ChannelAccount;
use Illuminate\Support\Carbon;

/**
 * Scheduler logic for refreshing Shopee Chat VN access tokens
 * (specs/11_SHOPEE_CHAT_VN.md § Token lifecycle).
 *
 * Shopee access_token TTL is 4h. This scanner dispatches
 * RefreshShopeeAccessTokenJob for any SHOPEE channel account whose token
 * expires within the next hour, landing at the 75% mark called out in the
 * spec (3h elapsed → 1h remaining). Also dispatches when the expiry is
 * unknown (stale account, possibly already past — better to attempt and let
 * the refresh job flip it to REAUTH_REQUIRED if Shopee has revoked).
 *
 * Lives as a static method so the closure in routes/console.php stays
 * trivial AND so unit tests can invoke it directly without faking the
 * scheduler. No DB writes, no external calls — only Bus::dispatch.
 */
class RefreshShopeeAccessTokenScheduler
{
    public static function run(): void
    {
        ChannelAccount::query()
            ->where('provider', 'SHOPEE')
            ->whereIn('status', ['ACTIVE', 'DEGRADED'])
            ->each(function (ChannelAccount $account) {
                $expiresAt = data_get($account->credentials, 'access_token_expires_at');
                $dueSoon = ! $expiresAt
                    || now()->addHour()->gte(Carbon::parse($expiresAt));

                if ($dueSoon) {
                    RefreshShopeeAccessTokenJob::dispatch($account->id);
                }
            });
    }
}