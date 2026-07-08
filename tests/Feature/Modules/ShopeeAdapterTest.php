<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Adapters\ShopeeAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * ShopeeAdapter::normalizeInbound unit tests.
 * Covers: text/image/video/sticker message types, edit (version > 1),
 * unsupported types (product/order) marked UNSUPPORTED, shop_id mismatch
 * rejected, missing message_id rejected.
 */
class ShopeeAdapterTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'shopee',
            'name' => 'Shopee Adapter Test',
            'status' => 'ACTIVE',
        ]);

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'SHOPEE',
            'name' => 'Shopee shop 123456',
            'status' => 'ACTIVE',
            'credentials' => [
                'shop_id' => 123456,
                'access_token' => 'access',
                'refresh_token' => 'refresh',
            ],
        ]);
    }

    public function test_normalizes_text_message(): void
    {
        $payload = $this->basePayload([
            'message_type' => 'text',
            'content' => ['text' => 'Hello from Shopee'],
        ]);

        $normalized = app(ShopeeAdapter::class)->normalizeInbound($this->account, $payload);

        $this->assertSame('TEXT', $normalized['message_type']);
        $this->assertSame('Hello from Shopee', $normalized['body_text']);
        $this->assertSame('MSG-1', $normalized['provider_message_id']);
        $this->assertSame(55555, (int) $normalized['provider_user_id']);
        $this->assertSame('CONV-789', $normalized['provider_chat_id']);
        $this->assertSame('Nguyen Van A', $normalized['sender_display_name']);
        $this->assertFalse($normalized['is_edit']);
        $this->assertNull($normalized['attachment_url']);
        $this->assertStringStartsWith("shopee:{$this->account->id}:msg:MSG-1:v1", $normalized['idempotency_key']);
    }

    public function test_normalizes_image_message_with_attachment_url(): void
    {
        $payload = $this->basePayload([
            'message_type' => 'image',
            'content' => [
                'image_url' => 'https://cf.shopee.vn/file-abc',
                'caption' => 'Look at this',
            ],
        ]);

        $normalized = app(ShopeeAdapter::class)->normalizeInbound($this->account, $payload);

        $this->assertSame('IMAGE', $normalized['message_type']);
        $this->assertSame('Look at this', $normalized['body_text']);
        $this->assertSame('https://cf.shopee.vn/file-abc', $normalized['attachment_url']);
    }

    public function test_normalizes_video_message(): void
    {
        $payload = $this->basePayload([
            'message_type' => 'video',
            'content' => [
                'video_url' => 'https://cf.shopee.vn/video-xyz',
                'caption' => 'Watch this',
            ],
        ]);

        $normalized = app(ShopeeAdapter::class)->normalizeInbound($this->account, $payload);

        $this->assertSame('VIDEO', $normalized['message_type']);
        $this->assertSame('Watch this', $normalized['body_text']);
        $this->assertSame('https://cf.shopee.vn/video-xyz', $normalized['attachment_url']);
    }

    public function test_normalizes_sticker_message(): void
    {
        $payload = $this->basePayload([
            'message_type' => 'sticker',
            'content' => ['sticker_id' => 'happy'],
        ]);

        $normalized = app(ShopeeAdapter::class)->normalizeInbound($this->account, $payload);

        $this->assertSame('STICKER', $normalized['message_type']);
        $this->assertSame('[Sticker]', $normalized['body_text']);
    }

    public function test_marks_unsupported_message_types_as_unsupported(): void
    {
        foreach (['product', 'order', 'voucher', 'combo'] as $type) {
            $payload = $this->basePayload([
                'message_type' => $type,
                'content' => ['product_id' => 'P-123', 'text' => 'ignored'],
            ]);

            $normalized = app(ShopeeAdapter::class)->normalizeInbound($this->account, $payload);

            $this->assertSame('UNSUPPORTED', $normalized['message_type'], "Type {$type} should be UNSUPPORTED");
            $this->assertSame('unsupported', $normalized['event_type'], "Type {$type} should emit unsupported event");
        }
    }

    public function test_detects_edit_via_version_field(): void
    {
        $payload = $this->basePayload([
            'version' => 2,
            'message_type' => 'text',
            'content' => ['text' => 'Edited text'],
        ]);

        $normalized = app(ShopeeAdapter::class)->normalizeInbound($this->account, $payload);

        $this->assertTrue($normalized['is_edit']);
        $this->assertSame(2, $normalized['provider_message_seq']);
        $this->assertStringContainsString(':v2', $normalized['idempotency_key']);
        $this->assertStringContainsString(':edit:', $normalized['provider_event_id']);
    }

    public function test_rejects_shop_id_mismatch(): void
    {
        $payload = $this->basePayload(['shop_id' => 999999]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shop_id (999999) does not match');

        app(ShopeeAdapter::class)->normalizeInbound($this->account, $payload);
    }

    public function test_rejects_missing_message_id(): void
    {
        $payload = $this->basePayload();
        unset($payload['message_id']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing message_id');

        app(ShopeeAdapter::class)->normalizeInbound($this->account, $payload);
    }

    public function test_outbound_methods_still_throw_in_cut1(): void
    {
        $outbox = new \App\Modules\Channels\Models\OutboxMessage([
            'conversation_id' => '00000000-0000-0000-0000-000000000000',
            'direction' => 'OUTBOUND',
            'message_type' => 'TEXT',
            'body_text' => 'x',
            'status' => 'QUEUED',
        ]);

        $adapter = app(ShopeeAdapter::class);

        $this->expectException(RuntimeException::class);
        $adapter->buildOutboundPayload($this->account, $outbox);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'message_id' => 'MSG-1',
            'conversation_id' => 'CONV-789',
            'shop_id' => 123456,
            'buyer_id' => 55555,
            'buyer_name' => 'Nguyen Van A',
            'buyer_portrait_url' => 'https://cf.shopee.vn/avatar-55555',
            'message_type' => 'text',
            'content' => ['text' => 'hi'],
            'created_timestamp' => 1720000000,
            'version' => 1,
        ], $overrides);
    }
}