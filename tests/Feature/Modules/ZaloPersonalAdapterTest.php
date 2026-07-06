<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Adapters\ZaloPersonalAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\ChannelAdapterRegistry;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ZaloPersonalAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function account(): ChannelAccount
    {
        $ws = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);

        return ChannelAccount::create([
            'workspace_id' => $ws->id,
            'provider' => 'ZALO_PERSONAL',
            'name' => 'Nick '.uniqid(),
            'status' => 'ACTIVE',
        ]);
    }

    public function test_registry_returns_personal_adapter(): void
    {
        $adapter = app(ChannelAdapterRegistry::class)->for($this->account());
        $this->assertInstanceOf(ZaloPersonalAdapter::class, $adapter);
    }

    public function test_normalize_maps_seq_and_fields(): void
    {
        $account = $this->account();
        $norm = app(ZaloPersonalAdapter::class)->normalizeInbound($account, [
            'event_name' => 'user_send_text',
            'timestamp' => 1783263300000,
            'message' => ['msg_id' => 'm-1', 'seq' => 987654321, 'text' => 'hi'],
            'sender' => ['id' => 'u-9', 'name' => 'Cust'],
        ]);

        $this->assertSame('m-1', $norm['provider_message_id']);
        $this->assertSame(987654321, $norm['provider_message_seq']);
        $this->assertSame('hi', $norm['body_text']);
        $this->assertStringStartsWith('zalo_personal:', $norm['idempotency_key']);
    }

    public function test_send_calls_sidecar_and_records_rate_limit(): void
    {
        $account = $this->account();
        Redis::del("rl:daily:{$account->id}:MESSAGE", "rl:burst:{$account->id}:MESSAGE");

        Http::fake([
            '*/accounts/*/send' => Http::response(['ok' => true, 'providerMessageId' => 'stub-1'], 200),
        ]);

        $result = app(ZaloPersonalAdapter::class)->sendOutbound($account, [
            'recipient_external_id' => 'cust-1',
            'text' => 'hello',
            'message_id' => 'local-1',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('stub-1', $result['provider_message_id']);
        Http::assertSent(fn ($req) => str_contains($req->url(), "/accounts/{$account->id}/send"));

        Redis::del("rl:daily:{$account->id}:MESSAGE", "rl:burst:{$account->id}:MESSAGE");
    }

    public function test_send_blocked_when_sidecar_unreachable_is_retryable(): void
    {
        $account = $this->account();
        Redis::del("rl:daily:{$account->id}:MESSAGE", "rl:burst:{$account->id}:MESSAGE");

        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $result = app(ZaloPersonalAdapter::class)->sendOutbound($account, ['text' => 'x', 'recipient_external_id' => 'c']);
        $this->assertFalse($result['ok']);
        $this->assertSame('SIDECAR_UNREACHABLE', $result['error_code']);
        $this->assertTrue($result['retryable']);

        Redis::del("rl:daily:{$account->id}:MESSAGE", "rl:burst:{$account->id}:MESSAGE");
    }
}
