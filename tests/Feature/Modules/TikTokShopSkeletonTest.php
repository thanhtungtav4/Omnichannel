<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Adapters\TikTokShopAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\TikTok\InvalidTikTokStateException;
use App\Modules\Channels\Services\TikTok\TikTokOAuthState;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TikTok cut 1 W1 skeleton tests (specs/13_TIKTOK_SHOP_VN.md).
 *
 * Locks down the bare-minimum contract for W1 completion:
 *   - Registry routes TIKTOK_SHOP accounts to TikTokShopAdapter
 *   - All 3 adapter methods throw a descriptive RuntimeException
 *     referencing the appropriate spec 13 milestone
 *   - TikTokOAuthState issues + consumes tokens (single-use, isolated per workspace)
 */
class TikTokShopSkeletonTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ChannelAccount $tiktokAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'tiktok',
            'name' => 'TikTok Skeleton Test',
            'status' => 'ACTIVE',
        ]);

        $this->tiktokAccount = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'TIKTOK_SHOP',
            'name' => 'TikTok shop draft',
            'status' => 'DRAFT',
            'credentials' => ['shop_id' => 'TT-12345'],
        ]);
    }

    public function test_registry_routes_tiktok_shop_account_to_tiktok_adapter(): void
    {
        $registry = app(\App\Modules\Channels\Services\ChannelAdapterRegistry::class);

        $adapter = $registry->for($this->tiktokAccount);

        $this->assertInstanceOf(TikTokShopAdapter::class, $adapter);
    }

    public function test_tiktok_normalize_inbound_throws_with_spec_reference(): void
    {
        $adapter = app(TikTokShopAdapter::class);

        try {
            $adapter->normalizeInbound($this->tiktokAccount, []);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('normalizeInbound', $e->getMessage());
            $this->assertStringContainsString('not implemented', $e->getMessage());
            $this->assertStringContainsString($this->tiktokAccount->id, $e->getMessage());
            $this->assertStringContainsString('specs/13_TIKTOK_SHOP_VN.md', $e->getMessage());
        }
    }

    public function test_tiktok_build_outbound_throws_with_spec_reference(): void
    {
        $adapter = app(TikTokShopAdapter::class);

        $outbox = new \App\Modules\Channels\Models\OutboxMessage([
            'conversation_id' => '00000000-0000-0000-0000-000000000000',
            'direction' => 'OUTBOUND',
            'message_type' => 'TEXT',
            'body_text' => 'hello',
            'status' => 'QUEUED',
        ]);

        try {
            $adapter->buildOutboundPayload($this->tiktokAccount, $outbox);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('buildOutboundPayload', $e->getMessage());
            $this->assertStringContainsString('specs/13_TIKTOK_SHOP_VN.md', $e->getMessage());
        }
    }

    public function test_tiktok_send_outbound_throws_with_spec_reference(): void
    {
        $adapter = app(TikTokShopAdapter::class);

        try {
            $adapter->sendOutbound($this->tiktokAccount, ['text' => 'hello']);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sendOutbound', $e->getMessage());
            $this->assertStringContainsString('specs/13_TIKTOK_SHOP_VN.md', $e->getMessage());
        }
    }

    public function test_oauth_state_issued_can_be_consumed_once(): void
    {
        $state = app(TikTokOAuthState::class);
        $token = $state->issue($this->workspace);

        $payload = $state->consume($token);

        $this->assertSame($this->workspace->id, $payload['workspace_id']);
        $this->assertGreaterThan(0, $payload['issued_at']);
    }

    public function test_oauth_state_cannot_be_consumed_twice(): void
    {
        $state = app(TikTokOAuthState::class);
        $token = $state->issue($this->workspace);

        $state->consume($token);

        $this->expectException(InvalidTikTokStateException::class);
        $state->consume($token);
    }

    public function test_oauth_state_unknown_token_rejected(): void
    {
        $this->expectException(InvalidTikTokStateException::class);
        app(TikTokOAuthState::class)->consume('not-a-real-token');
    }
}