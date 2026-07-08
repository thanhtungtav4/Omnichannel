<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\Shopee\ShopeeOAuthState;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shopee OAuth round-trip integration tests (specs/11 § OAuth flow).
 *
 * Covers:
 *   - connect() redirects to Shopee with state + partner_id
 *   - connect() 412s when partner credentials missing
 *   - callback() persists tokens + flips to ACTIVE on success
 *   - callback() rejects missing code
 *   - callback() rejects Shopee-side error (invalid_redirect_uri, access_denied)
 *   - callback() rejects invalid state (replay attack / expired)
 *   - callback() rejects Shopee token endpoint failure
 */
class ShopeeOAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'shopee-oauth',
            'name' => 'Shopee OAuth Test',
            'status' => 'ACTIVE',
        ]);

        $this->admin = User::factory()->create([
            'workspace_id' => $this->workspace->id,
            'role' => 'admin',
            'status' => 'ACTIVE',
        ]);
    }

    private function withPartnerCredentials(?array $creds = null): void
    {
        app(WorkspaceSettings::class)->set(
            $this->workspace,
            'shopee.partner_credentials',
            $creds ?? ['partner_id' => '999', 'partner_key' => 'partner-secret'],
        );
    }

    public function test_connect_redirects_to_shopee_with_state_and_partner_id(): void
    {
        $this->withPartnerCredentials();

        $response = $this->actingAs($this->admin)->get(route('admin.channels.shopee.connect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://partner.shopeemobile.com/api/v2/shop/auth_partner?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('999', $params['partner_id']);
        $this->assertNotEmpty($params['state']);
        $this->assertSame(route('admin.channels.shopee.callback'), $params['redirect']);
        $this->assertStringContainsString('shop_info', $params['scope']);
    }

    public function test_connect_returns_412_when_partner_credentials_missing(): void
    {
        // No withPartnerCredentials() call.

        $this->actingAs($this->admin)
            ->get(route('admin.channels.shopee.connect'))
            ->assertStatus(412);
    }

    public function test_callback_persists_tokens_on_success(): void
    {
        $this->withPartnerCredentials();

        $stateToken = app(ShopeeOAuthState::class)->issue($this->workspace);

        Http::fake([
            'partner.shopeemobile.com/*' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expire_in' => 14400,
                'shop_id' => 123456,
                'merchant_id' => 'M-001',
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.shopee.callback', [
                'code' => 'auth-code-from-shopee',
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertStringContainsString('admin/channels', $response->headers->get('Location'));

        $account = ChannelAccount::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('provider', 'SHOPEE')
            ->first();

        $this->assertNotNull($account);
        $this->assertSame('ACTIVE', $account->status);
        $this->assertSame(123456, $account->credentials['shop_id']);
        $this->assertSame('new-access-token', $account->credentials['access_token']);
        $this->assertSame('new-refresh-token', $account->credentials['refresh_token']);
        $this->assertNotNull($account->credentials['access_token_expires_at']);
        $this->assertNull($account->last_error_code);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/auth/token/get')
                && $request['code'] === 'auth-code-from-shopee'
                && $request['partner_id'] === 999;
        });
    }

    public function test_callback_rejects_missing_code(): void
    {
        $this->withPartnerCredentials();
        $stateToken = app(ShopeeOAuthState::class)->issue($this->workspace);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.shopee.callback', [
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertSame('MISSING_CODE', session('shopee_oauth_error_code'));
    }

    public function test_callback_translates_shopee_invalid_redirect_uri_error(): void
    {
        $this->withPartnerCredentials();
        $stateToken = app(ShopeeOAuthState::class)->issue($this->workspace);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.shopee.callback', [
                'error' => 'invalid_redirect_uri',
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertSame('INVALID_REDIRECT_URI', session('shopee_oauth_error_code'));
    }

    public function test_callback_translates_shopee_access_denied_error(): void
    {
        $this->withPartnerCredentials();
        $stateToken = app(ShopeeOAuthState::class)->issue($this->workspace);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.shopee.callback', [
                'error' => 'access_denied',
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertSame('ACCESS_DENIED', session('shopee_oauth_error_code'));
    }

    public function test_callback_rejects_unknown_state(): void
    {
        $this->withPartnerCredentials();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.shopee.callback', [
                'code' => 'any-code',
                'state' => 'never-issued',
            ]));

        $response->assertRedirect();
        $this->assertSame('INVALID_STATE', session('shopee_oauth_error_code'));
    }

    public function test_callback_rejects_already_consumed_state(): void
    {
        $this->withPartnerCredentials();

        $state = app(ShopeeOAuthState::class);
        $token = $state->issue($this->workspace);

        // consume once
        $state->consume($token);

        // replay with same state should fail
        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.shopee.callback', [
                'code' => 'replay-code',
                'state' => $token,
            ]));

        $response->assertRedirect();
        $this->assertSame('INVALID_STATE', session('shopee_oauth_error_code'));
    }

    public function test_callback_handles_shopee_token_endpoint_failure(): void
    {
        $this->withPartnerCredentials();
        $stateToken = app(ShopeeOAuthState::class)->issue($this->workspace);

        Http::fake([
            'partner.shopeemobile.com/*' => Http::response([
                'error' => 'invalid_code',
                'message' => 'Authorization code has expired.',
            ], 400),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.shopee.callback', [
                'code' => 'expired-code',
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertSame('TOKEN_EXCHANGE_FAILED', session('shopee_oauth_error_code'));
    }
}