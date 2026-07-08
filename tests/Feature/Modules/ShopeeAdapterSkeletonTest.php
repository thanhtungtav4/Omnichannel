<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Adapters\ShopeeAdapter;
use App\Modules\Channels\Adapters\TikTokShopAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\ChannelAdapterRegistry;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Tests\TestCase;

/**
 * W1 G0 acceptance: ShopeeAdapter skeleton is registered with the
 * ChannelAdapterRegistry, routes SHOPEE accounts to it, and throws a
 * descriptive RuntimeException on every method (so a stray call fails loud
 * instead of silently no-op'ing).
 *
 * Cut 2+ replaces these throws with real implementations.
 */
class ShopeeAdapterSkeletonTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ChannelAccount $shopeeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'skel',
            'name' => 'Skeleton Test',
            'status' => 'ACTIVE',
        ]);

        $this->shopeeAccount = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'SHOPEE',
            'name' => 'Shopee Test Shop',
            'status' => 'DRAFT',
            'credentials' => ['shop_id' => 123456, 'merchant_id' => 'M-001'],
            'webhook_secret' => 'shopee-secret',
        ]);
    }

    public function test_registry_routes_shopee_account_to_shopee_adapter(): void
    {
        $registry = app(ChannelAdapterRegistry::class);

        $adapter = $registry->for($this->shopeeAccount);

        $this->assertInstanceOf(ShopeeAdapter::class, $adapter);
    }

    public function test_shopee_adapter_normalize_inbound_is_implemented(): void
    {
        // W3 G1.2 (specs/11) implemented normalizeInbound — it should now return
        // a canonical shape rather than throwing. (Edit this test if normalizeInbound
        // gets refactored to throw again — the skeleton-test pattern is dead.)
        $adapter = app(ShopeeAdapter::class);

        $payload = [
            'message_id' => 'MSG-SKEL',
            'conversation_id' => 'CONV-1',
            'shop_id' => 123456,
            'buyer_id' => 1,
            'buyer_name' => 'Tester',
            'message_type' => 'text',
            'content' => ['text' => 'hi'],
            'created_timestamp' => time(),
            'version' => 1,
        ];

        $normalized = $adapter->normalizeInbound($this->shopeeAccount, $payload);

        $this->assertSame('TEXT', $normalized['message_type']);
        $this->assertSame('MSG-SKEL', $normalized['provider_message_id']);
    }

    public function test_shopee_adapter_build_outbound_is_implemented(): void
    {
        // W4 G1.3 (specs/11) implemented buildOutboundPayload. The skeleton
        // throw-test is dead code now; replaced with a smoke check.
        $adapter = app(ShopeeAdapter::class);

        $outbox = new \App\Modules\Channels\Models\OutboxMessage([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $this->shopeeAccount->id,
            'conversation_id' => '00000000-0000-0000-0000-000000000000',
            'message_id' => '00000000-0000-0000-0000-000000000000',
            'direction' => 'OUTBOUND',
            'message_type' => 'TEXT',
            'body_text' => 'hello',
            'status' => 'QUEUED',
            'recipient_external_id' => 'CONV-X',
            'payload' => ['conversation_id' => 'CONV-X', 'text' => 'hello'],
        ]);

        $payload = $adapter->buildOutboundPayload($this->shopeeAccount, $outbox);
        $this->assertSame('CONV-X', $payload['recipient_id']);
        $this->assertSame('text', $payload['message_type']);
    }

    public function test_shopee_adapter_send_outbound_is_implemented(): void
    {
        // W4 G1.3 implemented sendOutbound. The skeleton throw-test is dead;
        // replaced with a "missing credentials fails loud" check (full coverage
        // is in ShopeeSendOutboundTest).
        $adapter = app(ShopeeAdapter::class);

        $result = $adapter->sendOutbound($this->shopeeAccount, ['recipient_id' => 'C', 'message_type' => 'text']);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['retryable']);
        $this->assertSame('MISSING_CREDENTIALS', $result['error_code']);
    }

    public function test_tiktok_shop_adapter_is_registered_as_placeholder(): void
    {
        $tiktokAccount = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'TIKTOK_SHOP',
            'name' => 'TikTok Test Shop',
            'status' => 'DRAFT',
        ]);

        $registry = app(ChannelAdapterRegistry::class);
        $adapter = $registry->for($tiktokAccount);

        $this->assertInstanceOf(TikTokShopAdapter::class, $adapter);

        try {
            $adapter->normalizeInbound($tiktokAccount, []);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not implemented', $e->getMessage());
            $this->assertStringContainsString('specs/13_TIKTOK_SHOP_VN.md', $e->getMessage());
        }
    }
}