<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Jobs\SyncZaloThreadHistoryJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\ZaloThreadHistorySyncService;
use App\Modules\Crm\Models\Contact;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Platform\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Heuristic + dispatch logic for the auto-trigger that fires when an agent
 * opens a stale Zalo Personal thread. The job itself (sidecar call + state
 * update) lives in SyncZaloThreadHistoryJobTest.
 *
 * Decision matrix being verified:
 *   - non-ZALO_PERSONAL accounts → never trigger
 *   - recently-synced conversations → debounce, skip
 *   - conversations with last_message_at within 5min → fresh, skip
 *   - conversations with NULL last_message_at → no cursor anchor, skip
 *   - stale ZALO_PERSONAL conversation → dispatch the job
 */
class ZaloThreadHistorySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Contact $contact;

    private ZaloThreadHistorySyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'zalo-sync-test',
            'name' => 'Zalo Sync Test',
            'status' => 'ACTIVE',
        ]);

        $this->contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Test customer',
            'source' => 'ZALO_PERSONAL',
        ]);

        $this->service = app(ZaloThreadHistorySyncService::class);
    }

    public function test_dispatches_job_for_stale_zalo_personal_conversation(): void
    {
        Bus::fake([SyncZaloThreadHistoryJob::class]);

        $account = $this->makeAccount('ZALO_PERSONAL');
        $conversation = $this->makeConversation($account, lastMessageAt: now()->subMinutes(10));

        $this->service->maybeTrigger($conversation);

        Bus::assertDispatched(SyncZaloThreadHistoryJob::class, fn ($job) => $job->conversationId === $conversation->id);
    }

    public function test_does_not_dispatch_for_zalo_oa_conversation(): void
    {
        // ZALO_OA uses Zalo's own webhook delivery — no sidecar pull needed.
        // Triggering for OA would call a non-existent sidecar endpoint.
        Bus::fake([SyncZaloThreadHistoryJob::class]);

        $account = $this->makeAccount('ZALO_OA');
        $conversation = $this->makeConversation($account, lastMessageAt: now()->subMinutes(10));

        $this->service->maybeTrigger($conversation);

        Bus::assertNotDispatched(SyncZaloThreadHistoryJob::class);
    }

    public function test_does_not_dispatch_for_shopee_conversation(): void
    {
        // Shopee sync is via realtime webhook + a different adapter path —
        // not this Zalo-specific trigger.
        Bus::fake([SyncZaloThreadHistoryJob::class]);

        $account = $this->makeAccount('SHOPEE');
        $conversation = $this->makeConversation($account, lastMessageAt: now()->subMinutes(10));

        $this->service->maybeTrigger($conversation);

        Bus::assertNotDispatched(SyncZaloThreadHistoryJob::class);
    }

    public function test_does_not_dispatch_for_conversation_without_channel_account(): void
    {
        // Edge case — conversation row exists but channel_account was deleted
        // (FK cascade OR account purge). Service must not crash and must not
        // dispatch — the channelAccount relation is null, so provider check
        // fails safely.
        Bus::fake([SyncZaloThreadHistoryJob::class]);

        $account = $this->makeAccount('ZALO_PERSONAL');
        $conversation = $this->makeConversation($account, lastMessageAt: now()->subMinutes(10));

        // Override the relation in-memory so the service sees channelAccount
        // as null without us having to actually drop the FK row (which would
        // cascade-delete the conversation itself and invalidate the test).
        $conversation->setRelation('channelAccount', null);

        $this->service->maybeTrigger($conversation);

        Bus::assertNotDispatched(SyncZaloThreadHistoryJob::class);
    }

    public function test_does_not_dispatch_for_fresh_conversation(): void
    {
        // Realtime listener should be keeping up; no need to pull history.
        Bus::fake([SyncZaloThreadHistoryJob::class]);

        $account = $this->makeAccount('ZALO_PERSONAL');
        $conversation = $this->makeConversation($account, lastMessageAt: now()->subMinutes(2));

        $this->service->maybeTrigger($conversation);

        Bus::assertNotDispatched(SyncZaloThreadHistoryJob::class);
    }

    public function test_does_not_dispatch_for_conversation_within_one_minute_of_last_sync(): void
    {
        // Debounce — multiple agents opening the same thread shouldn't burst-sync.
        Bus::fake([SyncZaloThreadHistoryJob::class]);

        $account = $this->makeAccount('ZALO_PERSONAL');
        $conversation = $this->makeConversation(
            $account,
            lastMessageAt: now()->subMinutes(10),
            lastHistorySyncAt: now()->subSeconds(15),
        );

        $this->service->maybeTrigger($conversation);

        Bus::assertNotDispatched(SyncZaloThreadHistoryJob::class);
    }

    public function test_dispatches_after_debounce_window_passes(): void
    {
        // Same setup as above but sync was > 30s ago — should fire again.
        Bus::fake([SyncZaloThreadHistoryJob::class]);

        $account = $this->makeAccount('ZALO_PERSONAL');
        $conversation = $this->makeConversation(
            $account,
            lastMessageAt: now()->subMinutes(10),
            lastHistorySyncAt: now()->subMinutes(2),
        );

        $this->service->maybeTrigger($conversation);

        Bus::assertDispatched(SyncZaloThreadHistoryJob::class);
    }

    public function test_does_not_dispatch_for_conversation_without_last_message_at(): void
    {
        // No last_message_at means no realtime listener has ever reported a
        // message for this thread. We don't have a cursor anchor for a
        // per-thread pull, so skip. The full-history Setup dialog button is
        // the right tool for cold-start threads.
        Bus::fake([SyncZaloThreadHistoryJob::class]);

        $account = $this->makeAccount('ZALO_PERSONAL');
        $conversation = $this->makeConversation($account, lastMessageAt: null);

        $this->service->maybeTrigger($conversation);

        Bus::assertNotDispatched(SyncZaloThreadHistoryJob::class);
    }

    public function test_should_sync_returns_true_for_stale_zalo_personal_conversation(): void
    {
        // Pure decision-test (no dispatch).
        $account = $this->makeAccount('ZALO_PERSONAL');
        $conversation = $this->makeConversation($account, lastMessageAt: now()->subMinutes(10));

        $this->assertTrue($this->service->shouldSync($conversation));
    }

    public function test_should_sync_returns_false_for_zalo_oa(): void
    {
        $account = $this->makeAccount('ZALO_OA');
        $conversation = $this->makeConversation($account, lastMessageAt: now()->subMinutes(10));

        $this->assertFalse($this->service->shouldSync($conversation));
    }

    private function makeAccount(string $provider): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => $provider,
            'name' => "Test {$provider} account",
            'status' => 'ACTIVE',
            'credentials' => ['access_token' => 'tok'],
        ]);
    }

    private function makeConversation(
        ChannelAccount $account,
        ?CarbonInterface $lastMessageAt,
        ?CarbonInterface $lastHistorySyncAt = null,
    ): Conversation {
        return Conversation::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $this->contact->id,
            'status' => 'OPEN',
            'subject' => 'Test thread',
            'last_message_at' => $lastMessageAt,
            'last_history_sync_at' => $lastHistorySyncAt,
        ]);
    }
}