<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Channels\Models\WebhookEvent;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminChannelsHealthTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'admin-channels',
            'name' => 'Admin Test',
            'status' => 'ACTIVE',
        ]);

        $this->user = User::create([
            'name' => 'Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('secret'),
            'role' => 'owner',
            'workspace_id' => $this->workspace->id,
        ]);
    }

    private function makeChannel(string $provider, array $creds = []): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => $provider,
            'name' => "Test {$provider}",
            'status' => 'ACTIVE',
            'credentials' => array_merge([
                'access_token' => 'fake',
                'refresh_token' => 'fake',
                'shop_id' => $provider === 'SHOPEE' ? 99999 : 'SHOP-TT',
                'shop_cipher' => $provider === 'TIKTOK_SHOP' ? 'GCipA==' : null,
                'access_token_expires_at' => Carbon::now()->addHours(2)->toIso8601String(),
            ], $creds),
        ]);
    }

    public function test_channels_page_renders_shopee_specific_fields(): void
    {
        $this->makeChannel('SHOPEE', ['merchant_id' => 'MRC-12345']);

        $response = $this->actingAs($this->user)->get('/admin/channels');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/channels')
            ->where('channels.0.provider', 'SHOPEE')
            ->where('channels.0.shopId', 99999)
            ->where('channels.0.merchantId', 'MRC-12345')
            ->where('channels.0.isReauthRequired', false)
        );
    }

    public function test_channels_page_renders_tiktok_specific_fields(): void
    {
        $this->makeChannel('TIKTOK_SHOP');

        $response = $this->actingAs($this->user)->get('/admin/channels');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/channels')
            ->where('channels.0.provider', 'TIKTOK_SHOP')
            ->where('channels.0.tiktokShopId', 'SHOP-TT')
            ->where('channels.0.tiktokShopCipher', 'GCipA==')
        );
    }

    public function test_channels_page_exposes_pending_outbox_count(): void
    {
        $channel = $this->makeChannel('TIKTOK_SHOP');

        // Create 3 pending outbox messages (QUEUED or RETRYING).
        $conversation = Conversation::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $channel->id,
            'subject' => 'c',
            'status' => 'OPEN',
            'is_group' => false,
        ]);
        for ($i = 0; $i < 3; $i++) {
            $message = Message::create([
                'workspace_id' => $this->workspace->id,
                'conversation_id' => $conversation->id,
                'channel_account_id' => $channel->id,
                'direction' => 'OUTBOUND',
                'sender_type' => 'AGENT',
                'message_type' => 'TEXT',
            ]);
            OutboxMessage::create([
                'workspace_id' => $this->workspace->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'channel_account_id' => $channel->id,
                'recipient_external_id' => 'open-uid-1',
                'provider' => $channel->provider,
                'payload' => [],
                'status' => 'QUEUED',
                'attempts' => 0,
            ]);
        }

        $response = $this->actingAs($this->user)->get('/admin/channels');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('channels.0.pendingOutboxCount', 3)
        );
    }

    public function test_pending_outbox_count_ignores_sent_and_failed(): void
    {
        $channel = $this->makeChannel('TIKTOK_SHOP');

        $conversation = Conversation::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $channel->id,
            'subject' => 'c',
            'status' => 'OPEN',
            'is_group' => false,
        ]);

        foreach (['QUEUED', 'RETRYING'] as $i => $status) {
            $message = Message::create([
                'workspace_id' => $this->workspace->id,
                'conversation_id' => $conversation->id,
                'channel_account_id' => $channel->id,
                'direction' => 'OUTBOUND',
                'sender_type' => 'AGENT',
                'message_type' => 'TEXT',
            ]);
            OutboxMessage::create([
                'workspace_id' => $this->workspace->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'channel_account_id' => $channel->id,
                'recipient_external_id' => 'open-uid-1',
                'provider' => $channel->provider,
                'payload' => [],
                'status' => $status,
                'attempts' => 0,
            ]);
        }

        // One SENT + one FAILED — should not count.
        foreach (['SENT', 'FAILED'] as $status) {
            $message = Message::create([
                'workspace_id' => $this->workspace->id,
                'conversation_id' => $conversation->id,
                'channel_account_id' => $channel->id,
                'direction' => 'OUTBOUND',
                'sender_type' => 'AGENT',
                'message_type' => 'TEXT',
            ]);
            OutboxMessage::create([
                'workspace_id' => $this->workspace->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'channel_account_id' => $channel->id,
                'recipient_external_id' => 'open-uid-1',
                'provider' => $channel->provider,
                'payload' => [],
                'status' => $status,
                'attempts' => 1,
            ]);
        }

        $response = $this->actingAs($this->user)->get('/admin/channels');
        $response->assertInertia(fn ($page) => $page
            ->where('channels.0.pendingOutboxCount', 2)
        );
    }

    public function test_channels_page_exposes_last_inbound_timestamp(): void
    {
        $channel = $this->makeChannel('SHOPEE');

        WebhookEvent::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $channel->id,
            'provider' => 'SHOPEE',
            'provider_event_id' => 'MSG-IN-1',
            'idempotency_key' => "shopee:{$channel->id}:msg:MSG-IN-1",
            'event_type' => 'message',
            'headers' => [],
            'payload' => ['message_id' => 'MSG-IN-1'],
            'status' => 'PROCESSED',
            'processed_at' => Carbon::now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->user)->get('/admin/channels');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('channels.0.lastInboundAt', '5 minutes ago')
        );
    }

    public function test_last_inbound_ignores_unsupported_webhook_events(): void
    {
        $channel = $this->makeChannel('TIKTOK_SHOP');

        // IGNORED events should not count as "last inbound".
        WebhookEvent::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $channel->id,
            'provider' => 'TIKTOK_SHOP',
            'provider_event_id' => 'IGN-1',
            'idempotency_key' => "tiktok:{$channel->id}:msg:IGN-1:ignored",
            'event_type' => 'unsupported',
            'headers' => [],
            'payload' => [],
            'status' => 'IGNORED',
            'processed_at' => Carbon::now()->subMinute(),
        ]);

        $response = $this->actingAs($this->user)->get('/admin/channels');
        $response->assertInertia(fn ($page) => $page
            ->where('channels.0.lastInboundAt', null)
        );
    }

    public function test_is_reauth_required_reflects_last_error_code(): void
    {
        $channel = $this->makeChannel('SHOPEE');
        $channel->forceFill([
            'last_error_code' => 'REAUTH_REQUIRED',
            'last_error_message' => 'Token expired',
            'status' => 'DEGRADED',
        ])->save();

        $response = $this->actingAs($this->user)->get('/admin/channels');
        $response->assertInertia(fn ($page) => $page
            ->where('channels.0.isReauthRequired', true)
            ->where('channels.0.status', 'DEGRADED')
        );
    }

    public function test_tiktok_callback_url_uses_tiktok_shop_path(): void
    {
        $channel = $this->makeChannel('TIKTOK_SHOP');

        $response = $this->actingAs($this->user)->get('/admin/channels');
        $response->assertInertia(fn ($page) => $page
            ->where('channels.0.callbackUrl', url('/webhooks/tiktok-shop/'.$channel->id))
        );
    }

    public function test_callback_url_uses_dedicated_webhook_host_when_configured(): void
    {
        config(['tenant.webhook_host' => 'webhook.qrf.vn']);
        $channel = $this->makeChannel('ZALO_OA');

        $response = $this->actingAs($this->user)->get('/admin/channels');

        $response->assertInertia(fn ($page) => $page
            ->where('webhookBase', 'https://webhook.qrf.vn/webhooks')
            ->where('channels.0.callbackUrl', 'https://webhook.qrf.vn/webhooks/zalo/'.$channel->id)
        );
    }
}
