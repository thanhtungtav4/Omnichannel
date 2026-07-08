<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Jobs\RefreshTikTokAccessTokenJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Refresh token lifecycle (specs/13 § Token lifecycle).
 *
 * Mirrors RefreshShopeeAccessTokenJobTest. Covers:
 *   - successful refresh rotates tokens + flips to ACTIVE
 *   - failed refresh marks REAUTH_REQUIRED + DEGRADED
 *   - missing refresh token marks REAUTH_REQUIRED
 *   - missing partner credentials marks REAUTH_REQUIRED
 *   - HTTP 5xx marks REAUTH_REQUIRED
 */
class RefreshTikTokAccessTokenJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'tt-refresh',
            'name' => 'TikTok Refresh Test',
            'status' => 'ACTIVE',
        ]);

        app(WorkspaceSettings::class)->set(
            $this->workspace,
            'tiktok.partner_credentials',
            ['app_key' => 'tt-app-111', 'app_secret' => 'tt-secret'],
        );

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'TIKTOK_SHOP',
            'name' => 'TikTok shop TT-1',
            'status' => 'ACTIVE',
            'credentials' => [
                'shop_id' => 'TT-1',
                'open_id' => 'OPEN-1',
                'access_token' => 'old-access',
                'refresh_token' => 'old-refresh',
                'access_token_expires_at' => now()->subMinutes(5)->toIso8601String(),
            ],
        ]);
    }

    public function test_successful_refresh_rotates_tokens_and_keeps_active(): void
    {
        Http::fake([
            'auth.tiktok-shops.com/*' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'access_token_expire_in' => 86400,
                'refresh_token_expire_in' => time() + 86400 * 30,
                'open_id' => 'OPEN-1',
                'shop_id' => 'TT-1',
            ], 200),
        ]);

        (new RefreshTikTokAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('ACTIVE', $this->account->status);
        $this->assertSame('new-access', $this->account->credentials['access_token']);
        $this->assertSame('new-refresh', $this->account->credentials['refresh_token']);
        $this->assertNull($this->account->last_error_code);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/token/get')
                && $request['refresh_token'] === 'old-refresh'
                && $request['grant_type'] === 'refresh_token'
                && $request['app_key'] === 'tt-app-111';
        });
    }

    public function test_failed_refresh_marks_reauth_required(): void
    {
        Http::fake([
            'auth.tiktok-shops.com/*' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Refresh token has expired or been revoked.',
            ], 400),
        ]);

        (new RefreshTikTokAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('DEGRADED', $this->account->status);
        $this->assertSame('REAUTH_REQUIRED', $this->account->last_error_code);
        $this->assertStringContainsString('invalid_grant', $this->account->last_error_message);
    }

    public function test_missing_refresh_token_marks_reauth_required(): void
    {
        $this->account->update(['credentials' => ['shop_id' => 'TT-1']]);

        (new RefreshTikTokAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('DEGRADED', $this->account->status);
        $this->assertSame('REAUTH_REQUIRED', $this->account->last_error_code);
    }

    public function test_missing_partner_credentials_marks_reauth_required(): void
    {
        app(WorkspaceSettings::class)->forget($this->workspace, 'tiktok.partner_credentials');

        (new RefreshTikTokAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('DEGRADED', $this->account->status);
        $this->assertSame('REAUTH_REQUIRED', $this->account->last_error_code);
    }

    public function test_http_5xx_marks_reauth_required(): void
    {
        Http::fake([
            'auth.tiktok-shops.com/*' => Http::response('Internal Server Error', 500),
        ]);

        (new RefreshTikTokAccessTokenJob($this->account->id))->handle();

        $this->account->refresh();

        $this->assertSame('DEGRADED', $this->account->status);
        $this->assertSame('REAUTH_REQUIRED', $this->account->last_error_code);
    }
}