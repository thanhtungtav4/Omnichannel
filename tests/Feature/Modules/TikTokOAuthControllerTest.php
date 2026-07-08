<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\TikTok\TikTokOAuthState;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TikTok OAuth round-trip integration tests (specs/13 W2 G1.1).
 *
 * Tests use the VERIFIED TikTok Shop Partner API contracts:
 *   - Authorize URL:  https://auth.tiktok-shops.com/api/v2/token/authorize
 *   - Token URL:      https://auth.tiktok-shops.com/api/v2/token/get
 *   - Param:          app_key (NOT client_id)
 *   - Callback param: auth_code (NOT code)
 *   - grant_type:     authorized_code (NOT authorization_code)
 */
class TikTokOAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'tiktok-oauth',
            'name' => 'TikTok OAuth Test',
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
            'tiktok.partner_credentials',
            $creds ?? ['app_key' => 'tiktok-app-999', 'app_secret' => 'tiktok-secret'],
        );
    }

    public function test_connect_redirects_to_tiktok_with_app_key_and_state(): void
    {
        $this->withPartnerCredentials();

        $response = $this->actingAs($this->admin)->get(route('admin.channels.tiktok.connect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('auth.tiktok-shops.com/api/v2/token/authorize', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('tiktok-app-999', $params['app_key']);
        $this->assertNotEmpty($params['state']);
    }

    public function test_connect_returns_412_when_partner_credentials_missing(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.channels.tiktok.connect'))
            ->assertStatus(412);
    }

    public function test_callback_persists_tokens_on_success(): void
    {
        $this->withPartnerCredentials();
        $stateToken = app(TikTokOAuthState::class)->issue($this->workspace);

        Http::fake([
            'auth.tiktok-shops.com/*' => Http::response([
                'access_token' => 'tt-access-token',
                'refresh_token' => 'tt-refresh-token',
                'access_token_expire_in' => 86400,
                'refresh_token_expire_in' => time() + 86400 * 30,
                'open_id' => 'OPEN-1',
                'shop_id' => 'TTSHOP-42',
                'seller_name' => 'VN Test Shop',
                'seller_base_region' => 'VN',
                'granted_scopes' => ['seller.im.message', 'seller.im.basic'],
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.tiktok.callback', [
                'auth_code' => 'auth-code-from-tiktok',
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertStringContainsString('admin/channels', $response->headers->get('Location'));

        $account = ChannelAccount::withoutWorkspaceScope()
            ->where('workspace_id', $this->workspace->id)
            ->where('provider', 'TIKTOK_SHOP')
            ->first();

        $this->assertNotNull($account);
        $this->assertSame('ACTIVE', $account->status);
        $this->assertSame('TTSHOP-42', $account->credentials['shop_id']);
        $this->assertSame('OPEN-1', $account->credentials['open_id']);
        $this->assertSame('tt-access-token', $account->credentials['access_token']);
        $this->assertSame('tt-refresh-token', $account->credentials['refresh_token']);
        $this->assertNotNull($account->credentials['access_token_expires_at']);
        $this->assertNotNull($account->credentials['refresh_token_expires_at']);
        $this->assertNull($account->last_error_code);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/token/get')
                && $request['auth_code'] === 'auth-code-from-tiktok'
                && $request['grant_type'] === 'authorized_code'
                && $request['app_key'] === 'tiktok-app-999';
        });
    }

    public function test_callback_rejects_missing_auth_code(): void
    {
        $this->withPartnerCredentials();
        $stateToken = app(TikTokOAuthState::class)->issue($this->workspace);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.tiktok.callback', [
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertSame('MISSING_CODE', session('tiktok_oauth_error_code'));
    }

    public function test_callback_translates_tiktok_invalid_redirect_uri_error(): void
    {
        $this->withPartnerCredentials();
        $stateToken = app(TikTokOAuthState::class)->issue($this->workspace);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.tiktok.callback', [
                'error' => 'invalid_redirect_uri',
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertSame('INVALID_REDIRECT_URI', session('tiktok_oauth_error_code'));
    }

    public function test_callback_translates_tiktok_access_denied_error(): void
    {
        $this->withPartnerCredentials();
        $stateToken = app(TikTokOAuthState::class)->issue($this->workspace);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.tiktok.callback', [
                'error' => 'access_denied',
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertSame('ACCESS_DENIED', session('tiktok_oauth_error_code'));
    }

    public function test_callback_rejects_unknown_state(): void
    {
        $this->withPartnerCredentials();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.tiktok.callback', [
                'auth_code' => 'any-code',
                'state' => 'never-issued',
            ]));

        $response->assertRedirect();
        $this->assertSame('INVALID_STATE', session('tiktok_oauth_error_code'));
    }

    public function test_callback_rejects_already_consumed_state(): void
    {
        $this->withPartnerCredentials();
        $state = app(TikTokOAuthState::class);
        $token = $state->issue($this->workspace);
        $state->consume($token);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.tiktok.callback', [
                'auth_code' => 'replay-code',
                'state' => $token,
            ]));

        $response->assertRedirect();
        $this->assertSame('INVALID_STATE', session('tiktok_oauth_error_code'));
    }

    public function test_callback_handles_tiktok_token_endpoint_failure(): void
    {
        $this->withPartnerCredentials();
        $stateToken = app(TikTokOAuthState::class)->issue($this->workspace);

        Http::fake([
            'auth.tiktok-shops.com/*' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code has expired.',
            ], 400),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.channels.tiktok.callback', [
                'auth_code' => 'expired-code',
                'state' => $stateToken,
            ]));

        $response->assertRedirect();
        $this->assertSame('TOKEN_EXCHANGE_FAILED', session('tiktok_oauth_error_code'));
    }
}