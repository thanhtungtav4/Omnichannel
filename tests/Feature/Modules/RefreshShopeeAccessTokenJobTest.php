<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Jobs\RefreshShopeeAccessTokenJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Refresh token lifecycle (specs/11 § Token lifecycle).
 *
 * Covers:
 *   - successful refresh rotates tokens + flips to ACTIVE
 *   - failed refresh marks REAUTH_REQUIRED + DEGRADED
 *   - missing refresh token marks REAUTH_REQUIRED
 *   - missing partner credentials marks REAUTH_REQUIRED
 *   - Shopee request includes HMAC signature + correct body shape
 */
class RefreshShopeeAccessTokenJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'refresh',
            'name' => 'Refresh Test',
            'status' => 'ACTIVE',
        ]);

        app(WorkspaceSettings::class)->set(
            $this->workspace,
            'shopee.partner_credentials',
            ['partner_id' => '111', 'partner_key' => 'partner-secret'],
        );

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'SHOPEE',
            'name' => 'Shopee shop 123456',
            'status' => 'ACTIVE',
            'credentials' => [
                'shop_id' => 123456,
                'access_token' => 'old-access',
                'refresh_token' => 'old-refresh',
                'access_token_expires_at' => now()->subMinutes(5)->toIso8601String(),
            ],
        ]);
    }

    public function test_successful_refresh_rotates_tokens_and_keeps_active(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/auth/access_token/get' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expire_in' => 14400,
            ], 200),
        ]);

        (new RefreshShopeeAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('ACTIVE', $this->account->status);
        $this->assertSame('new-access', $this->account->credentials['access_token']);
        $this->assertSame('new-refresh', $this->account->credentials['refresh_token']);
        $this->assertNull($this->account->last_error_code);

        Http::assertSent(function ($request) {
            $body = http_build_query($request->data());

            return str_contains($request->url(), '/auth/access_token/get')
                && $request['refresh_token'] === 'old-refresh'
                && $request['partner_id'] === 111
                && ! empty($request['sign'])
                && ! empty($request['timestamp']);
        });
    }

    public function test_failed_refresh_marks_reauth_required(): void
    {
        Http::fake([
            'partner.shopeemobile.com/*' => Http::response([
                'error' => 'invalid_refresh_token',
                'message' => 'Refresh token has expired or been revoked.',
            ], 400),
        ]);

        (new RefreshShopeeAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('DEGRADED', $this->account->status);
        $this->assertSame('REAUTH_REQUIRED', $this->account->last_error_code);
        $this->assertStringContainsString('invalid_refresh_token', $this->account->last_error_message);
    }

    public function test_missing_refresh_token_marks_reauth_required(): void
    {
        $this->account->update(['credentials' => ['shop_id' => 123456]]);

        (new RefreshShopeeAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('DEGRADED', $this->account->status);
        $this->assertSame('REAUTH_REQUIRED', $this->account->last_error_code);
    }

    public function test_missing_partner_credentials_marks_reauth_required(): void
    {
        app(WorkspaceSettings::class)->forget($this->workspace, 'shopee.partner_credentials');

        (new RefreshShopeeAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('DEGRADED', $this->account->status);
        $this->assertSame('REAUTH_REQUIRED', $this->account->last_error_code);
    }

    public function test_http_5xx_marks_reauth_required(): void
    {
        Http::fake([
            'partner.shopeemobile.com/*' => Http::response('Internal Server Error', 500),
        ]);

        (new RefreshShopeeAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('DEGRADED', $this->account->status);
        $this->assertSame('REAUTH_REQUIRED', $this->account->last_error_code);
    }
}