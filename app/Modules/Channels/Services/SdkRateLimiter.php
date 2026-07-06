<?php

namespace App\Modules\Channels\Services;

use App\Modules\Channels\Models\ChannelAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Anti-block rate limiter for provider SDK operations (spec 10).
 *
 * Two gates per (account, category):
 *  - daily counter: Redis hash rl:daily:{acct}:{cat} field=YYYY-MM-DD (HINCRBY, 2d TTL)
 *  - burst window:  Redis sorted set rl:burst:{acct}:{cat} (ZADD ts, prune, ZCARD)
 *
 * Fail-open: any Redis error -> allow. We never block real customer replies
 * because the limiter's own infrastructure hiccuped.
 *
 * Limits resolve nick-override -> org-default -> hardcoded fallback, cached 60s.
 */
class SdkRateLimiter
{
    /** @var array<string, array{daily:int, burst:int|null, window:int|null}> */
    private const FALLBACK = [
        'MESSAGE' => ['daily' => 300, 'burst' => 20, 'window' => 30_000],
        'FRIEND_ADD' => ['daily' => 30, 'burst' => 5, 'window' => 60_000],
        'REACTION' => ['daily' => 500, 'burst' => 30, 'window' => 30_000],
        'CHAT_ACTION' => ['daily' => 1000, 'burst' => 60, 'window' => 30_000],
        'STRANGER_MESSAGE' => ['daily' => 300, 'burst' => 10, 'window' => 60_000],
    ];

    /**
     * @return array{allowed: bool, reason?: string, daily_used?: int, daily_limit?: int}
     */
    public function check(ChannelAccount $account, string $category = 'MESSAGE'): array
    {
        $limit = $this->effectiveLimit($account, $category);

        try {
            $day = now()->format('Y-m-d');
            $dailyKey = "rl:daily:{$account->id}:{$category}";
            $dailyUsed = (int) (Redis::hget($dailyKey, $day) ?? 0);

            if ($dailyUsed >= $limit['daily']) {
                return ['allowed' => false, 'reason' => 'DAILY_LIMIT', 'daily_used' => $dailyUsed, 'daily_limit' => $limit['daily']];
            }

            if ($limit['burst'] !== null && $limit['window'] !== null) {
                $burstKey = "rl:burst:{$account->id}:{$category}";
                $nowMs = (int) (microtime(true) * 1000);
                Redis::zremrangebyscore($burstKey, 0, $nowMs - $limit['window']);
                $burstCount = (int) Redis::zcard($burstKey);

                if ($burstCount >= $limit['burst']) {
                    return ['allowed' => false, 'reason' => 'BURST_LIMIT', 'daily_used' => $dailyUsed, 'daily_limit' => $limit['daily']];
                }
            }

            return ['allowed' => true, 'daily_used' => $dailyUsed, 'daily_limit' => $limit['daily']];
        } catch (Throwable $e) {
            // Fail-open: never block a real send on limiter infrastructure error.
            report($e);

            return ['allowed' => true, 'reason' => 'LIMITER_UNAVAILABLE'];
        }
    }

    /**
     * Record one send after it succeeds. Bumps both gates.
     */
    public function record(ChannelAccount $account, string $category = 'MESSAGE'): void
    {
        try {
            $day = now()->format('Y-m-d');
            $dailyKey = "rl:daily:{$account->id}:{$category}";
            Redis::hincrby($dailyKey, $day, 1);
            Redis::expire($dailyKey, 2 * 86400);

            $limit = $this->effectiveLimit($account, $category);
            if ($limit['window'] !== null) {
                $burstKey = "rl:burst:{$account->id}:{$category}";
                $nowMs = (int) (microtime(true) * 1000);
                Redis::zadd($burstKey, $nowMs, (string) $nowMs);
                Redis::expire($burstKey, (int) ceil($limit['window'] / 1000) + 1);
            }
        } catch (Throwable $e) {
            report($e); // recording failure must not break the send path
        }
    }

    /**
     * @return array{daily:int, burst:int|null, window:int|null}
     */
    private function effectiveLimit(ChannelAccount $account, string $category): array
    {
        return Cache::remember("sdk_limit:{$account->id}:{$category}", 60, function () use ($account, $category) {
            $rows = \DB::table('sdk_limits')
                ->where('workspace_id', $account->workspace_id)
                ->where('category', $category)
                ->where(fn ($q) => $q->whereNull('channel_account_id')->orWhere('channel_account_id', $account->id))
                ->get();

            // nick override wins over org default
            $override = $rows->firstWhere('channel_account_id', $account->id);
            $default = $rows->firstWhere('channel_account_id', null);
            $row = $override ?? $default;

            if ($row) {
                return ['daily' => (int) $row->daily_limit, 'burst' => $row->burst_limit !== null ? (int) $row->burst_limit : null, 'window' => $row->burst_window_ms !== null ? (int) $row->burst_window_ms : null];
            }

            return self::FALLBACK[$category] ?? self::FALLBACK['MESSAGE'];
        });
    }
}
