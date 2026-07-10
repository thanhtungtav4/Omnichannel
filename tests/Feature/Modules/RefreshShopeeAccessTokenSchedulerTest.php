<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Jobs\RefreshShopeeAccessTokenJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\RefreshShopeeAccessTokenScheduler;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Coverage for the scheduler that dispatches RefreshShopeeAccessTokenJob
 * (specs/11 § Token lifecycle, routes/console.php).
 *
 * Without these tests a regression in the scheduler filter would silently
 * leave Shopee accounts with dead tokens — every inbound webhook and outbound
 * send would 401 without any visible signal until an admin noticed. Belt
 * and suspenders: the underlying job has its own tests (RefreshShopeeAccess
 * TokenJobTest) — this file only covers the dispatch decision.
 */
class RefreshShopeeAccessTokenSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'shopee-scheduler',
            'name' => 'Shopee Scheduler Test',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_dispatches_for_active_account_expiring_within_one_hour(): void
    {
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        $account = $this->makeShopeeAccount('ACTIVE', now()->addMinutes(30)->toIso8601String());

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertDispatchedTimes(RefreshShopeeAccessTokenJob::class, 1);
        Bus::assertDispatched(RefreshShopeeAccessTokenJob::class, fn ($job) => $job->channelAccountId === $account->id);
    }

    public function test_dispatches_for_active_account_exactly_at_one_hour_boundary(): void
    {
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        // Spec says "75% of TTL" with TTL=4h → refresh when remaining ≤ 1h.
        // Boundary uses gte, so exactly 60min remaining still dispatches.
        $this->makeShopeeAccount('ACTIVE', now()->addMinutes(60)->toIso8601String());

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertDispatchedTimes(RefreshShopeeAccessTokenJob::class, 1);
    }

    public function test_does_not_dispatch_for_active_account_with_more_than_one_hour_remaining(): void
    {
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        $this->makeShopeeAccount('ACTIVE', now()->addMinutes(61)->toIso8601String());

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertNotDispatched(RefreshShopeeAccessTokenJob::class);
    }

    public function test_does_not_dispatch_for_active_account_with_three_hours_remaining(): void
    {
        // A freshly refreshed token should NOT be re-refreshed by this sweep.
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        $this->makeShopeeAccount('ACTIVE', now()->addHours(3)->toIso8601String());

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertNotDispatched(RefreshShopeeAccessTokenJob::class);
    }

    public function test_dispatches_for_active_account_with_no_expiry_recorded(): void
    {
        // No expires_at → assume stale (never refreshed / corrupted state).
        // Better to attempt and let the refresh job flag REAUTH_REQUIRED
        // than to leave the account silently 401'ing.
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        $this->makeShopeeAccount('ACTIVE', null);

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertDispatchedTimes(RefreshShopeeAccessTokenJob::class, 1);
    }

    public function test_dispatches_for_degraded_account_so_reauth_required_can_be_retried(): void
    {
        // DEGRADED accounts are still candidates — the refresh job may
        // succeed and flip them back to ACTIVE if the refresh token is
        // still valid. Only DISABLED is excluded.
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        $account = $this->makeShopeeAccount('DEGRADED', now()->addMinutes(10)->toIso8601String());

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertDispatchedTimes(RefreshShopeeAccessTokenJob::class, 1);
        Bus::assertDispatched(RefreshShopeeAccessTokenJob::class, fn ($job) => $job->channelAccountId === $account->id);
    }

    public function test_does_not_dispatch_for_draft_account(): void
    {
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        // DRAFT means the account was created but never connected — no
        // refresh_token, nothing to refresh.
        $this->makeShopeeAccount('DRAFT', now()->addMinutes(10)->toIso8601String());

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertNotDispatched(RefreshShopeeAccessTokenJob::class);
    }

    public function test_does_not_dispatch_for_disabled_account(): void
    {
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        $this->makeShopeeAccount('DISABLED', now()->addMinutes(10)->toIso8601String());

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertNotDispatched(RefreshShopeeAccessTokenJob::class);
    }

    public function test_does_not_dispatch_for_non_shopee_providers(): void
    {
        // A ZALO_OA account with a matching expiry must NOT be picked up by
        // the Shopee scheduler — different refresh job, different credentials.
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'ZALO_OA',
            'name' => 'Zalo OA',
            'status' => 'ACTIVE',
            'credentials' => [
                'access_token' => 'zalo-token',
                'access_token_expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ]);

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertNotDispatched(RefreshShopeeAccessTokenJob::class);
    }

    public function test_dispatches_only_for_due_accounts_in_a_mixed_workspace(): void
    {
        // Realistic sweep: 5 accounts, only 2 due.
        Bus::fake([RefreshShopeeAccessTokenJob::class]);

        $due1 = $this->makeShopeeAccount('ACTIVE', now()->addMinutes(5)->toIso8601String(), 'due-1');
        $due2 = $this->makeShopeeAccount('DEGRADED', now()->addMinutes(20)->toIso8601String(), 'due-2');
        $this->makeShopeeAccount('ACTIVE', now()->addHours(3)->toIso8601String(), 'fresh');
        $this->makeShopeeAccount('DISABLED', now()->addMinutes(5)->toIso8601String(), 'disabled');
        $this->makeShopeeAccount('DRAFT', null, 'draft');

        RefreshShopeeAccessTokenScheduler::run();

        Bus::assertDispatchedTimes(RefreshShopeeAccessTokenJob::class, 2);
        Bus::assertDispatched(RefreshShopeeAccessTokenJob::class, fn ($job) => $job->channelAccountId === $due1->id);
        Bus::assertDispatched(RefreshShopeeAccessTokenJob::class, fn ($job) => $job->channelAccountId === $due2->id);
    }

    private function makeShopeeAccount(string $status, ?string $expiresAt, string $name = 'Shopee shop 1'): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'SHOPEE',
            'name' => $name,
            'status' => $status,
            'credentials' => array_filter([
                'shop_id' => 123456,
                'access_token' => 'tok',
                'refresh_token' => 'refresh',
                'access_token_expires_at' => $expiresAt,
            ], fn ($v) => $v !== null),
        ]);
    }
}