<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Adapters\ZaloOaAdapter;
use App\Modules\Channels\Jobs\RefreshZaloAccessTokenJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZaloOaTest extends TestCase
{
    use RefreshDatabase;

    private function oaAccount(array $creds = []): ChannelAccount
    {
        $ws = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);

        return ChannelAccount::create([
            'workspace_id' => $ws->id,
            'provider' => 'ZALO_OA',
            'name' => 'OA '.uniqid(),
            'status' => 'ACTIVE',
            'credentials' => array_merge([
                'app_id' => 'app-1', 'app_secret' => 'secret-1',
                'access_token' => 'old-access', 'refresh_token' => 'old-refresh',
            ], $creds),
        ]);
    }

    public function test_token_refresh_updates_credentials(): void
    {
        $account = $this->oaAccount();
        Http::fake(['*/v4/oa/access_token' => Http::response([
            'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 90000,
        ])]);

        (new RefreshZaloAccessTokenJob($account->id))->handle();

        $account->refresh();
        $this->assertSame('new-access', $account->credentials['access_token']);
        $this->assertSame('new-refresh', $account->credentials['refresh_token']);
        $this->assertSame('ACTIVE', $account->status);
        $this->assertNotNull($account->settings['token_expires_at']);
    }

    public function test_token_refresh_failure_degrades_account(): void
    {
        $account = $this->oaAccount();
        Http::fake(['*/v4/oa/access_token' => Http::response(['error' => -14001, 'message' => 'refresh expired'], 400)]);

        (new RefreshZaloAccessTokenJob($account->id))->handle();

        $account->refresh();
        $this->assertSame('DEGRADED', $account->status);
        $this->assertNotNull($account->last_error_message);
    }

    public function test_oa_send_success(): void
    {
        $account = $this->oaAccount();
        Http::fake(['*/oa/message/cs' => Http::response(['error' => 0, 'data' => ['message_id' => 'oa-msg-1']])]);

        $result = app(ZaloOaAdapter::class)->sendOutbound($account, ['recipient_external_id' => 'u-1', 'text' => 'hi']);

        $this->assertTrue($result['ok']);
        $this->assertSame('oa-msg-1', $result['provider_message_id']);
    }

    public function test_oa_send_token_error_degrades_and_is_retryable(): void
    {
        $account = $this->oaAccount();
        Http::fake(['*/oa/message/cs' => Http::response(['error' => -124, 'message' => 'access token expired'])]);

        $result = app(ZaloOaAdapter::class)->sendOutbound($account, ['recipient_external_id' => 'u-1', 'text' => 'hi']);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['retryable']);
        $account->refresh();
        $this->assertSame('DEGRADED', $account->status);
    }
}
