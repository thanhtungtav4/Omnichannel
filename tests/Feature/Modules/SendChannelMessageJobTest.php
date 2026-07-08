<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Adapters\ShopeeAdapter;
use App\Modules\Channels\Adapters\TikTokShopAdapter;
use App\Modules\Channels\Jobs\SendChannelMessageJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendChannelMessageJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::create([
            'slug' => 'send-job',
            'name' => 'Send Job Test',
            'status' => 'ACTIVE',
        ]);
    }

    private function makeChannel(string $provider): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => $provider,
            'name' => "Test {$provider}",
            'status' => 'ACTIVE',
            'credentials' => [
                'access_token' => 'fake',
                'refresh_token' => 'fake',
                'shop_id' => $provider === 'SHOPEE' ? 12345 : 'SHOP-TT',
                'shop_cipher' => $provider === 'TIKTOK_SHOP' ? 'GCipA==' : null,
                'access_token_expires_at' => Carbon::now()->addHour()->toIso8601String(),
            ],
        ]);
    }

    private function makeOutbox(ChannelAccount $channel, string $body = 'hello'): OutboxMessage
    {
        $conversation = Conversation::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $channel->id,
            'subject' => 'Test conv',
            'status' => 'OPEN',
            'is_group' => false,
        ]);
        $message = Message::create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $conversation->id,
            'channel_account_id' => $channel->id,
            'direction' => 'OUTBOUND',
            'sender_type' => 'AGENT',
            'message_type' => 'TEXT',
        ]);

        return OutboxMessage::create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel_account_id' => $channel->id,
            'recipient_external_id' => 'open-uid-1',
            'provider' => $channel->provider,
            'payload' => ['text' => $body],
            'status' => 'QUEUED',
            'attempts' => 0,
        ]);
    }

    // ---------- happy path: Shopee success ----------

    public function test_shopee_success_marks_outbox_sent(): void
    {
        $channel = $this->makeChannel('SHOPEE');
        $outbox = $this->makeOutbox($channel);

        \Illuminate\Support\Facades\Http::fake([
            'partner.shopeemobile.com/*' => \Illuminate\Support\Facades\Http::response([
                'message_id' => 'SHP-OUT-1',
            ], 200),
        ]);

        (new SendChannelMessageJob($outbox->id))->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));

        $outbox->refresh();
        $this->assertSame('SENT', $outbox->status);
        $this->assertSame('SHP-OUT-1', $outbox->provider_response['message_id'] ?? null);
        $this->assertNull($outbox->last_error_code);
        $this->assertSame(1, $outbox->attempts);
    }

    public function test_tiktok_success_marks_outbox_sent(): void
    {
        $channel = $this->makeChannel('TIKTOK_SHOP');
        $outbox = $this->makeOutbox($channel);

        \Illuminate\Support\Facades\Http::fake([
            '*open.tiktokglobalshop.com/api/im/202412/send_message*' => \Illuminate\Support\Facades\Http::response([
                'code' => 0,
                'message' => 'success',
                'data' => ['message_id' => 'TT-OUT-1'],
            ], 200),
        ]);

        (new SendChannelMessageJob($outbox->id))->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));

        $outbox->refresh();
        $this->assertSame('SENT', $outbox->status);
        $this->assertSame(1, $outbox->attempts);
    }

    // ---------- retry_after (RATE_LIMITED) ----------

    public function test_shopee_429_retry_after_sets_next_attempt_to_provider_value(): void
    {
        $channel = $this->makeChannel('SHOPEE');
        $outbox = $this->makeOutbox($channel);

        \Illuminate\Support\Facades\Http::fake([
            'partner.shopeemobile.com/*' => \Illuminate\Support\Facades\Http::response([
                'error' => 'too_many_requests',
                'message' => 'too many requests',
            ], 429, ['Retry-After' => '120']),
        ]);

        // Queue::fake makes release() a no-op so we can verify outbox state
        // without trying to invoke the real queue manager.
        Queue::fake();

        $job = new SendChannelMessageJob($outbox->id);
        $job->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));

        $outbox->refresh();
        $this->assertSame('RETRYING', $outbox->status);
        $this->assertSame('RATE_LIMITED', $outbox->last_error_code);
        // next_attempt_at should be ~120s from now (within 5s tolerance).
        $expectedAt = now()->addSeconds(120);
        $this->assertEqualsWithDelta(
            $expectedAt->timestamp,
            $outbox->next_attempt_at->timestamp,
            5,
        );
    }

    public function test_tiktok_429_retry_after_respects_provider_value(): void
    {
        $channel = $this->makeChannel('TIKTOK_SHOP');
        $outbox = $this->makeOutbox($channel);

        \Illuminate\Support\Facades\Http::fake([
            '*open.tiktokglobalshop.com/api/im/202412/send_message*' => \Illuminate\Support\Facades\Http::response([
                'code' => 429,
                'error' => 'rate_limited',
                'message' => 'too many',
            ], 429, ['Retry-After' => '90']),
        ]);

        Queue::fake();

        $job = new SendChannelMessageJob($outbox->id);
        $job->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));

        $outbox->refresh();
        $this->assertSame('RETRYING', $outbox->status);
        $expectedAt = now()->addSeconds(90);
        $this->assertEqualsWithDelta(
            $expectedAt->timestamp,
            $outbox->next_attempt_at->timestamp,
            5,
        );
    }

    public function test_retry_after_is_clamped_to_max_3600_seconds(): void
    {
        $channel = $this->makeChannel('SHOPEE');
        $outbox = $this->makeOutbox($channel);

        // Provider returns absurd retry_after = 86400 (24h). We cap at 1h.
        \Illuminate\Support\Facades\Http::fake([
            'partner.shopeemobile.com/*' => \Illuminate\Support\Facades\Http::response([
                'error' => 'too_many_requests',
            ], 429, ['Retry-After' => '86400']),
        ]);

        Queue::fake();

        $job = new SendChannelMessageJob($outbox->id);
        $job->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));

        $outbox->refresh();
        $expectedAt = now()->addSeconds(3600);
        $this->assertEqualsWithDelta(
            $expectedAt->timestamp,
            $outbox->next_attempt_at->timestamp,
            5,
        );
    }

    public function test_retry_after_floor_at_5_seconds(): void
    {
        $channel = $this->makeChannel('SHOPEE');
        $outbox = $this->makeOutbox($channel);

        // Provider returns 1s. Floor at 5s.
        \Illuminate\Support\Facades\Http::fake([
            'partner.shopeemobile.com/*' => \Illuminate\Support\Facades\Http::response([
                'error' => 'too_many_requests',
            ], 429, ['Retry-After' => '1']),
        ]);

        Queue::fake();

        $job = new SendChannelMessageJob($outbox->id);
        $job->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));

        $outbox->refresh();
        $expectedAt = now()->addSeconds(5);
        $this->assertEqualsWithDelta(
            $expectedAt->timestamp,
            $outbox->next_attempt_at->timestamp,
            5,
        );
    }

    // ---------- non-retryable errors ----------

    public function test_recipient_blocked_marks_failed_no_retry(): void
    {
        $channel = $this->makeChannel('TIKTOK_SHOP');
        $outbox = $this->makeOutbox($channel);

        \Illuminate\Support\Facades\Http::fake([
            '*open.tiktokglobalshop.com/api/im/202412/send_message*' => \Illuminate\Support\Facades\Http::response([
                'code' => 400,
                'error' => 'recipient_blocked',
                'message' => 'blocked',
            ], 400),
        ]);

        Queue::fake();

        $job = new SendChannelMessageJob($outbox->id);
        $job->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));

        $outbox->refresh();
        $this->assertSame('FAILED', $outbox->status);
        $this->assertNull($outbox->next_attempt_at);
        $this->assertSame('recipient_blocked', $outbox->last_error_code);
    }

    public function test_auth_error_marks_account_degraded_and_outbox_failed(): void
    {
        $channel = $this->makeChannel('SHOPEE');
        $outbox = $this->makeOutbox($channel);

        \Illuminate\Support\Facades\Http::fake([
            'partner.shopeemobile.com/*' => \Illuminate\Support\Facades\Http::response([
                'error' => 'unauthorized',
                'message' => 'token invalid',
            ], 401),
        ]);

        Queue::fake();

        $job = new SendChannelMessageJob($outbox->id);
        $job->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));

        $outbox->refresh();
        $this->assertSame('FAILED', $outbox->status);
        $this->assertSame('REAUTH_REQUIRED', $outbox->last_error_code);

        $channel->refresh();
        $this->assertSame('DEGRADED', $channel->status);
        $this->assertSame('REAUTH_REQUIRED', $channel->last_error_code);
    }

    // ---------- attempts counter ----------

    public function test_increments_attempts_on_each_run(): void
    {
        $channel = $this->makeChannel('SHOPEE');
        $outbox = $this->makeOutbox($channel);

        \Illuminate\Support\Facades\Http::fake([
            'partner.shopeemobile.com/*' => \Illuminate\Support\Facades\Http::response([
                'message_id' => 'SHP-OUT-1',
            ], 200),
        ]);

        (new SendChannelMessageJob($outbox->id))->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));
        $outbox->refresh();
        $this->assertSame(1, $outbox->attempts);

        // Run again on a SENT row — should be a no-op (returns early).
        (new SendChannelMessageJob($outbox->id))->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));
        $outbox->refresh();
        $this->assertSame(1, $outbox->attempts); // not incremented because SENT
    }

    // ---------- max tries -> FAILED ----------

    public function test_marks_failed_after_max_tries(): void
    {
        $channel = $this->makeChannel('TIKTOK_SHOP');
        $outbox = $this->makeOutbox($channel);
        $outbox->forceFill(['attempts' => 5])->save();

        \Illuminate\Support\Facades\Http::fake([
            '*open.tiktokglobalshop.com/api/im/202412/send_message*' => \Illuminate\Support\Facades\Http::response([
                'code' => 500,
                'error' => 'server_error',
            ], 500),
        ]);

        Queue::fake();

        $job = new SendChannelMessageJob($outbox->id);
        $job->handle(app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class));

        $outbox->refresh();
        $this->assertSame('FAILED', $outbox->status);
        $this->assertNull($outbox->next_attempt_at);
    }
}