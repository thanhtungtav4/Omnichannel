<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Jobs\SyncZaloThreadHistoryJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Crm\Models\Contact;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration coverage for SyncZaloThreadHistoryJob: sidecar call shape, anchor
 * selection, and tracking-column updates. The heuristic gating lives in
 * ZaloThreadHistorySyncServiceTest — this file focuses on the side-effects.
 *
 * Key behaviors verified:
 *   - Calls sidecar POST /accounts/{id}/sync with {lastMsgId, threadType}
 *   - Uses GROUP threadType for is_group conversations, USER otherwise
 *   - Anchors on latest message with a non-empty provider_message_id
 *   - Updates last_history_sync_at only on successful response
 *   - Swallows sidecar errors (log + skip) without retrying
 *   - Skips non-ZALO_PERSONAL accounts at job time
 */
class SyncZaloThreadHistoryJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Contact $contact;

    private ChannelAccount $account;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'sync-job-test',
            'name' => 'Sync Job Test',
            'status' => 'ACTIVE',
        ]);

        $this->contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Test customer',
            'source' => 'ZALO_PERSONAL',
        ]);

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'ZALO_PERSONAL',
            'name' => 'Personal nick',
            'status' => 'ACTIVE',
            'credentials' => ['access_token' => 'tok'],
        ]);

        $this->conversation = Conversation::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $this->account->id,
            'contact_id' => $this->contact->id,
            'status' => 'OPEN',
            'subject' => 'Thread',
            'last_message_at' => now()->subMinutes(10), // stale → not auto-skipped
        ]);

        // Make sure the job doesn't bail on the staleness re-check.
        $this->conversation->refresh();
    }

    public function test_calls_sidecar_with_user_thread_type_for_direct_conversations(): void
    {
        Http::fake([
            '*/accounts/*/sync' => Http::response(['ok' => true], 200),
        ]);

        $this->seedMessage('latest-dm-id');

        (new SyncZaloThreadHistoryJob($this->conversation->id))->handle();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/accounts/'.$this->account->id.'/sync')
                && ($body['lastMsgId'] ?? null) === 'latest-dm-id'
                && ($body['threadType'] ?? null) === 'USER';
        });
    }

    public function test_calls_sidecar_with_group_thread_type_for_group_conversations(): void
    {
        Http::fake([
            '*/accounts/*/sync' => Http::response(['ok' => true], 200),
        ]);

        $this->conversation->update(['is_group' => true]);
        $this->seedMessage('latest-group-id');

        (new SyncZaloThreadHistoryJob($this->conversation->id))->handle();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['threadType'] ?? null) === 'GROUP'
                && ($body['lastMsgId'] ?? null) === 'latest-group-id';
        });
    }

    public function test_updates_last_history_sync_at_on_successful_sidecar_response(): void
    {
        Http::fake([
            '*/accounts/*/sync' => Http::response(['ok' => true], 200),
        ]);

        $this->seedMessage('anchor-1');

        $this->assertNull($this->conversation->fresh()->last_history_sync_at);

        (new SyncZaloThreadHistoryJob($this->conversation->id))->handle();

        $refreshed = $this->conversation->fresh();
        $this->assertNotNull($refreshed->last_history_sync_at);
        $this->assertSame('anchor-1', $refreshed->last_history_sync_msg_id);
    }

    public function test_does_not_update_tracking_on_sidecar_http_failure(): void
    {
        // We want next thread-open to retry, not be debounced by a stale
        // successful-sync timestamp.
        Http::fake([
            '*/accounts/*/sync' => Http::response(['error' => 'NOT_CONNECTED'], 500),
        ]);

        $this->seedMessage('anchor-1');

        (new SyncZaloThreadHistoryJob($this->conversation->id))->handle();

        $refreshed = $this->conversation->fresh();
        $this->assertNull($refreshed->last_history_sync_at);
        $this->assertNull($refreshed->last_history_sync_msg_id);
    }

    public function test_does_not_update_tracking_when_sidecar_unreachable(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('connection refused');
        });

        $this->seedMessage('anchor-1');

        (new SyncZaloThreadHistoryJob($this->conversation->id))->handle();

        $this->assertNull($this->conversation->fresh()->last_history_sync_at);
    }

    public function test_anchors_on_latest_message_with_provider_message_id(): void
    {
        Http::fake([
            '*/accounts/*/sync' => Http::response(['ok' => true], 200),
        ]);

        // Older + newer — must pick the NEWER's provider_message_id as cursor.
        $this->seedMessage('old-id');
        $this->seedMessage('new-id');

        (new SyncZaloThreadHistoryJob($this->conversation->id))->handle();

        Http::assertSent(fn ($request) => ($request->data()['lastMsgId'] ?? null) === 'new-id');
    }

    public function test_skips_when_conversation_has_no_message_with_provider_id(): void
    {
        // Only system notes (no provider_message_id) — nothing to anchor.
        Http::fake();

        Message::create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'channel_account_id' => $this->account->id,
            'provider_message_id' => null, // system note
            'direction' => 'OUTBOUND',
            'sender_type' => 'AGENT',
            'sender_id' => 'note',
            'body_text' => 'Internal note',
            'message_type' => 'NOTE',
            'status' => 'SENT',
        ]);

        (new SyncZaloThreadHistoryJob($this->conversation->id))->handle();

        Http::assertNothingSent();
        $this->assertNull($this->conversation->fresh()->last_history_sync_at);
    }

    public function test_skips_non_zalo_personal_account(): void
    {
        Http::fake();

        // Switch provider mid-test (rare, but defensive).
        $this->account->update(['provider' => 'ZALO_OA']);
        $this->seedMessage('anchor-1');

        (new SyncZaloThreadHistoryJob($this->conversation->id))->handle();

        Http::assertNothingSent();
    }

    public function test_skips_if_conversation_was_updated_between_dispatch_and_handle(): void
    {
        // Race: agent opened a stale thread → sync dispatched → realtime
        // listener delivered a new message → by the time the job runs,
        // last_message_at is fresh and the pull is unnecessary.
        Http::fake();

        $this->seedMessage('anchor-1');
        $this->conversation->update(['last_message_at' => now()->subSeconds(30)]);

        (new SyncZaloThreadHistoryJob($this->conversation->id))->handle();

        Http::assertNothingSent();
    }

    private function seedMessage(string $providerMessageId): void
    {
        Message::create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'channel_account_id' => $this->account->id,
            'provider_message_id' => $providerMessageId,
            'direction' => 'INBOUND',
            'sender_type' => 'CUSTOMER',
            'sender_id' => 'zalo-user',
            'body_text' => 'hello',
            'message_type' => 'TEXT',
            'status' => 'RECEIVED',
        ]);
    }
}