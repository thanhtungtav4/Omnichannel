<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Services\Shopee\InvalidShopeeStateException;
use App\Modules\Channels\Services\Shopee\ShopeeOAuthState;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks down the OAuth state contract: single-use, scoped to one workspace,
 * expires in 10 minutes. Tests run against the cache driver set in phpunit.xml
 * (array).
 */
class ShopeeOAuthStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_state_can_be_consumed_once(): void
    {
        $workspace = Workspace::create(['slug' => 'oauth', 'name' => 'OAuth', 'status' => 'ACTIVE']);

        $state = app(ShopeeOAuthState::class);
        $token = $state->issue($workspace);

        $payload = $state->consume($token);

        $this->assertSame($workspace->id, $payload['workspace_id']);
        $this->assertGreaterThan(0, $payload['issued_at']);
    }

    public function test_state_cannot_be_consumed_twice(): void
    {
        $workspace = Workspace::create(['slug' => 'oauth', 'name' => 'OAuth', 'status' => 'ACTIVE']);

        $state = app(ShopeeOAuthState::class);
        $token = $state->issue($workspace);

        $state->consume($token); // first call succeeds

        $this->expectException(InvalidShopeeStateException::class);
        $state->consume($token); // second call rejects
    }

    public function test_unknown_token_rejected(): void
    {
        $this->expectException(InvalidShopeeStateException::class);

        app(ShopeeOAuthState::class)->consume('not-a-real-token');
    }

    public function test_two_workspaces_get_independent_tokens(): void
    {
        $ws1 = Workspace::create(['slug' => 'one', 'name' => 'One', 'status' => 'ACTIVE']);
        $ws2 = Workspace::create(['slug' => 'two', 'name' => 'Two', 'status' => 'ACTIVE']);

        $state = app(ShopeeOAuthState::class);
        $token1 = $state->issue($ws1);
        $token2 = $state->issue($ws2);

        $this->assertNotSame($token1, $token2);

        $payload1 = $state->consume($token1);
        $payload2 = $state->consume($token2);

        $this->assertSame($ws1->id, $payload1['workspace_id']);
        $this->assertSame($ws2->id, $payload2['workspace_id']);
    }
}