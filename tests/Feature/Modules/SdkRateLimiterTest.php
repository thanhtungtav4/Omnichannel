<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\SdkRateLimiter;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SdkRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    private function account(): ChannelAccount
    {
        $ws = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);

        return ChannelAccount::create([
            'workspace_id' => $ws->id,
            'provider' => 'ZALO_PERSONAL',
            'name' => 'Nick '.uniqid(),
            'status' => 'ACTIVE',
        ]);
    }

    public function test_blocks_after_daily_limit_and_allows_before(): void
    {
        $account = $this->account();
        // Per-nick override: tiny daily cap, no burst gate, so the test is deterministic.
        DB::table('sdk_limits')->insert([
            'id' => \Str::uuid(),
            'workspace_id' => $account->workspace_id,
            'channel_account_id' => $account->id,
            'category' => 'MESSAGE',
            'daily_limit' => 3,
            'burst_limit' => null,
            'burst_window_ms' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $limiter = app(SdkRateLimiter::class);
        // clean any leftover redis keys for this account
        Redis::del("rl:daily:{$account->id}:MESSAGE", "rl:burst:{$account->id}:MESSAGE");

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($limiter->check($account)['allowed'], "send #$i should be allowed");
            $limiter->record($account);
        }

        $blocked = $limiter->check($account);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('DAILY_LIMIT', $blocked['reason']);

        Redis::del("rl:daily:{$account->id}:MESSAGE", "rl:burst:{$account->id}:MESSAGE");
    }

    public function test_falls_back_to_default_limit_when_no_row(): void
    {
        $account = $this->account();
        Redis::del("rl:daily:{$account->id}:MESSAGE", "rl:burst:{$account->id}:MESSAGE");

        $result = $account && ($r = app(SdkRateLimiter::class)->check($account)) ? $r : null;
        $this->assertTrue($result['allowed']);
        $this->assertSame(300, $result['daily_limit']); // hardcoded MESSAGE fallback
    }
}
