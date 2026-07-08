<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Adapters\TikTokShopAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class TikTokShopAdapterTest extends TestCase
{
    use RefreshDatabase;

    private ChannelAccount $account;

    private TikTokShopAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $workspace = Workspace::create([
            'slug' => 'tt-adapter',
            'name' => 'TT Adapter Test',
            'status' => 'ACTIVE',
        ]);
        $this->account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'TIKTOK_SHOP',
            'name' => 'TikTok Adapter Shop',
            'status' => 'ACTIVE',
            'credentials' => [
                'shop_id' => 'SHOP-001',
                'shop_cipher' => 'GCipA==',
                'access_token' => 'fake-access',
                'refresh_token' => 'fake-refresh',
                'access_token_expires_at' => Carbon::now()->addHour()->toIso8601String(),
            ],
            'webhook_secret' => 'tt-secret',
        ]);
        $this->adapter = new TikTokShopAdapter;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'event_type' => 'NEW_MESSAGE',
            'message_id' => 'MSG-TT-1',
            'conversation_id' => 'CONV-1',
            'shop_id' => 'SHOP-001',
            'message_type' => 'text',
            'sender' => [
                'open_id' => 'open-uid-1',
                'nickname' => 'Minh Anh',
                'avatar_url' => 'https://p16-sign-sg.tiktokcdn.com/avatar.jpg',
            ],
            'content' => ['text' => 'Hello from TikTok'],
            'created_at' => 1735000000,
            'version' => 1,
        ], $overrides);
    }

    // ---------- normalizeInbound ----------

    public function test_normalize_text_maps_to_canonical_shape(): void
    {
        $payload = $this->basePayload();

        $norm = $this->adapter->normalizeInbound($this->account, $payload);

        $this->assertSame('MSG-TT-1', $norm['provider_message_id']);
        $this->assertSame('TEXT', $norm['message_type']);
        $this->assertSame('Hello from TikTok', $norm['body_text']);
        $this->assertSame('open-uid-1', $norm['provider_user_id']);
        $this->assertSame('CONV-1', $norm['provider_chat_id']);
        $this->assertSame('Minh Anh', $norm['sender_display_name']);
        $this->assertSame('https://p16-sign-sg.tiktokcdn.com/avatar.jpg', $norm['sender_avatar_url']);
        $this->assertSame('tiktok:'.$this->account->id.':msg:MSG-TT-1', $norm['idempotency_key']);
        $this->assertSame('message', $norm['event_type']);
        $this->assertFalse($norm['is_edit']);
        $this->assertSame(1, $norm['provider_message_seq']);
        $this->assertNull($norm['attachment_url']);
    }

    public function test_normalize_image_maps_attachments(): void
    {
        $payload = $this->basePayload([
            'message_type' => 'image',
            'content' => [
                'image_url' => 'https://p16-sign-sg.tiktokcdn.com/abc.jpg',
                'caption' => 'look at this',
            ],
        ]);

        $norm = $this->adapter->normalizeInbound($this->account, $payload);

        $this->assertSame('IMAGE', $norm['message_type']);
        $this->assertSame('https://p16-sign-sg.tiktokcdn.com/abc.jpg', $norm['attachment_url']);
        $this->assertSame('look at this', $norm['body_text']);
    }

    public function test_normalize_unsupported_type_persists_as_unsupported(): void
    {
        $payload = $this->basePayload([
            'message_type' => 'product_card',
            'content' => ['product_id' => 'X'],
        ]);

        $norm = $this->adapter->normalizeInbound($this->account, $payload);

        $this->assertSame('UNSUPPORTED', $norm['message_type']);
        $this->assertSame('unsupported', $norm['event_type']);
    }

    public function test_normalize_video_is_unsupported_in_cut1(): void
    {
        $payload = $this->basePayload([
            'message_type' => 'video',
            'content' => ['video_url' => 'https://example.com/v.mp4', 'caption' => 'vid'],
        ]);

        $norm = $this->adapter->normalizeInbound($this->account, $payload);

        $this->assertSame('UNSUPPORTED', $norm['message_type']);
        // Caption is still preserved in body_text for fallback rendering.
        $this->assertSame('vid', $norm['body_text']);
    }

    public function test_normalize_sticker_is_unsupported_in_cut1(): void
    {
        $payload = $this->basePayload([
            'message_type' => 'sticker',
            'content' => ['sticker_id' => 'X'],
        ]);

        $norm = $this->adapter->normalizeInbound($this->account, $payload);

        $this->assertSame('UNSUPPORTED', $norm['message_type']);
    }

    public function test_normalize_edit_increments_idempotency_key(): void
    {
        $v1 = $this->adapter->normalizeInbound($this->account, $this->basePayload(['version' => 1]));
        $v2 = $this->adapter->normalizeInbound($this->account, $this->basePayload(['version' => 2, 'content' => ['text' => 'edited']]));

        $this->assertSame('tiktok:'.$this->account->id.':msg:MSG-TT-1', $v1['idempotency_key']);
        $this->assertSame('tiktok:'.$this->account->id.':msg:MSG-TT-1:v2', $v2['idempotency_key']);
        $this->assertTrue($v2['is_edit']);
        $this->assertSame(2, $v2['provider_message_seq']);
        $this->assertSame('edited', $v2['body_text']);
    }

    public function test_normalize_extracts_idempotency_key_uses_message_id(): void
    {
        $norm = $this->adapter->normalizeInbound($this->account, $this->basePayload(['message_id' => 'XYZ-9']));

        $this->assertSame('tiktok:'.$this->account->id.':msg:XYZ-9', $norm['idempotency_key']);
        $this->assertSame('XYZ-9', $norm['provider_event_id']);
    }

    public function test_normalize_handles_iso8601_created_at(): void
    {
        $payload = $this->basePayload(['created_at' => '2026-07-08T10:30:00Z']);

        $norm = $this->adapter->normalizeInbound($this->account, $payload);

        $this->assertInstanceOf(Carbon::class, $norm['provider_timestamp']);
        $this->assertSame('2026-07-08 10:30:00', $norm['provider_timestamp']->format('Y-m-d H:i:s'));
    }

    public function test_normalize_rejects_missing_message_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing message_id');

        $payload = $this->basePayload();
        unset($payload['message_id']);

        $this->adapter->normalizeInbound($this->account, $payload);
    }

    public function test_normalize_rejects_shop_id_mismatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match channel account shop_id');

        $payload = $this->basePayload(['shop_id' => 'WRONG-SHOP']);

        $this->adapter->normalizeInbound($this->account, $payload);
    }

    public function test_normalize_allows_missing_shop_id_in_payload(): void
    {
        // Some TikTok Shop events don't carry shop_id (e.g. cross-shop
        // delivery updates). Don't reject; just leave raw_profile.shop_id empty.
        $payload = $this->basePayload();
        unset($payload['shop_id']);

        $norm = $this->adapter->normalizeInbound($this->account, $payload);

        $this->assertSame('', $norm['raw_profile']['shop_id']);
    }

    public function test_normalize_preserves_raw_payload(): void
    {
        $payload = $this->basePayload();

        $norm = $this->adapter->normalizeInbound($this->account, $payload);

        $this->assertSame($payload, $norm['raw_payload']);
    }

    // ---------- buildOutboundPayload ----------

    public function test_build_outbound_text_payload(): void
    {
        $message = new \App\Modules\Channels\Models\OutboxMessage([
            'message_type' => 'text',
            'body_text' => 'hi back',
            'recipient_external_id' => 'open-uid-1',
        ]);

        $payload = $this->adapter->buildOutboundPayload($this->account, $message);

        $this->assertSame('open-uid-1', $payload['recipient_id']);
        $this->assertSame('text', $payload['message_type']);
        $this->assertSame(['text' => 'hi back'], $payload['content']);
    }

    public function test_build_outbound_image_payload_includes_image_url(): void
    {
        $message = new \App\Modules\Channels\Models\OutboxMessage([
            'message_type' => 'image',
            'body_text' => 'check this',
            'recipient_external_id' => 'open-uid-1',
            'payload' => ['image_url' => 'https://example.com/i.jpg'],
        ]);

        $payload = $this->adapter->buildOutboundPayload($this->account, $message);

        $this->assertSame('image', $payload['message_type']);
        $this->assertSame('https://example.com/i.jpg', $payload['image_url']);
        $this->assertSame('https://example.com/i.jpg', $payload['content']['image_url']);
        $this->assertSame('check this', $payload['content']['caption']);
    }

    public function test_build_outbound_falls_back_to_payload_conversation_id(): void
    {
        $message = new \App\Modules\Channels\Models\OutboxMessage([
            'message_type' => 'text',
            'body_text' => 'x',
            'recipient_external_id' => null,
            'payload' => ['conversation_id' => 'CONV-42'],
        ]);

        $payload = $this->adapter->buildOutboundPayload($this->account, $message);

        $this->assertSame('CONV-42', $payload['recipient_id']);
    }

    public function test_build_outbound_rejects_missing_recipient(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing conversation_id');

        $message = new \App\Modules\Channels\Models\OutboxMessage([
            'message_type' => 'text',
            'body_text' => 'x',
            'recipient_external_id' => null,
            'payload' => [],
        ]);

        $this->adapter->buildOutboundPayload($this->account, $message);
    }

    // ---------- sendOutbound error mapping ----------

    public function test_send_outbound_returns_missing_credentials_when_no_token(): void
    {
        $this->account->update([
            'credentials' => ['shop_id' => 'SHOP-001'], // no access_token
        ]);

        $result = $this->adapter->sendOutbound($this->account, [
            'recipient_id' => 'open-uid-1',
            'message_type' => 'text',
            'content' => ['text' => 'hi'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('MISSING_CREDENTIALS', $result['error_code']);
        $this->assertFalse($result['retryable']);
    }

    public function test_send_outbound_reauth_required_on_401(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'open.tiktokglobalshop.com/*' => \Illuminate\Support\Facades\Http::response([
                'code' => 401,
                'message' => 'invalid access token',
                'error' => 'unauthorized',
            ], 401),
        ]);

        $result = $this->adapter->sendOutbound($this->account, [
            'recipient_id' => 'open-uid-1',
            'message_type' => 'text',
            'content' => ['text' => 'hi'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('REAUTH_REQUIRED', $result['error_code']);
        $this->assertFalse($result['retryable']);

        $this->account->refresh();
        $this->assertSame('DEGRADED', $this->account->status);
        $this->assertSame('REAUTH_REQUIRED', $this->account->last_error_code);
    }

    public function test_send_outbound_429_is_retryable_with_retry_after(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'open.tiktokglobalshop.com/*' => \Illuminate\Support\Facades\Http::response([
                'code' => 429,
                'error' => 'rate_limited',
                'message' => 'too many',
            ], 429, ['Retry-After' => '120']),
        ]);

        $result = $this->adapter->sendOutbound($this->account, [
            'recipient_id' => 'open-uid-1',
            'message_type' => 'text',
            'content' => ['text' => 'hi'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('RATE_LIMITED', $result['error_code']);
        $this->assertTrue($result['retryable']);
        $this->assertSame(120, $result['_retry_after_seconds']);
    }

    public function test_send_outbound_recipient_blocked_is_not_retryable(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'open.tiktokglobalshop.com/*' => \Illuminate\Support\Facades\Http::response([
                'code' => 400,
                'error' => 'recipient_blocked',
                'message' => 'blocked',
            ], 400),
        ]);

        $result = $this->adapter->sendOutbound($this->account, [
            'recipient_id' => 'open-uid-1',
            'message_type' => 'text',
            'content' => ['text' => 'hi'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('recipient_blocked', $result['error_code']);
        $this->assertFalse($result['retryable']);
    }

    public function test_send_outbound_success_returns_provider_message_id(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'open.tiktokglobalshop.com/*' => \Illuminate\Support\Facades\Http::response([
                'code' => 0,
                'message' => 'success',
                'data' => ['message_id' => 'TT-OUT-1'],
            ], 200),
        ]);

        $result = $this->adapter->sendOutbound($this->account, [
            'recipient_id' => 'open-uid-1',
            'message_type' => 'text',
            'content' => ['text' => 'hi'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('TT-OUT-1', $result['provider_message_id']);
    }

public function test_send_outbound_uses_tiktok_cdn_url_without_reuploading(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            // Match the full URL pattern with /api/ prefix
            '*open.tiktokglobalshop.com/api/im/202412/send_message*' => \Illuminate\Support\Facades\Http::response([
                'code' => 0,
                'message' => 'success',
                'data' => ['message_id' => 'TT-OUT-2'],
            ], 200),
            '*open.tiktokglobalshop.com/api/im/202412/upload_image*' => \Illuminate\Support\Facades\Http::response('not called', 404),
        ]);

        $result = $this->adapter->sendOutbound($this->account, [
            'recipient_id' => 'open-uid-1',
            'message_type' => 'image',
            'image_url' => 'https://p16-sign-sg.tiktokcdn.com/already-here.jpg',
            'content' => ['caption' => 'x'],
        ]);

        $this->assertTrue($result['ok']);
        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), 'send_message');
        });
        \Illuminate\Support\Facades\Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'upload_image');
        });
    }
}