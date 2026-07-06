<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Adapters\FacebookAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\ChannelAdapterRegistry;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\Stage;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Routing\Models\AgentPresence;
use App\Modules\Routing\Models\RoutingQueue;
use App\Modules\Routing\Models\RoutingQueueMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $ws = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
        $agent = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        AgentPresence::create(['workspace_id' => $ws->id, 'user_id' => $agent->id, 'status' => 'ONLINE', 'active_conversation_count' => 0]);
        $queue = RoutingQueue::create(['workspace_id' => $ws->id, 'name' => 'Q', 'status' => 'ACTIVE', 'mode' => 'STICKY_THEN_EVEN', 'timeout_seconds' => 300, 'max_active_per_agent' => 5, 'requires_online' => true]);
        RoutingQueueMember::create(['workspace_id' => $ws->id, 'routing_queue_id' => $queue->id, 'user_id' => $agent->id, 'sort_order' => 1, 'status' => 'ACTIVE']);
        $pipeline = Pipeline::create(['workspace_id' => $ws->id, 'name' => 'Lead', 'type' => 'LEAD', 'is_default' => true, 'sort_order' => 1]);
        Stage::create(['workspace_id' => $ws->id, 'pipeline_id' => $pipeline->id, 'name' => 'New', 'status_group' => 'OPEN', 'sort_order' => 1]);

        $account = ChannelAccount::create([
            'workspace_id' => $ws->id,
            'provider' => 'FACEBOOK',
            'name' => 'FB Page',
            'status' => 'ACTIVE',
            'webhook_secret' => 'verify-token',
            'credentials' => ['app_secret' => 'app-secret-1', 'page_access_token' => 'page-token-1'],
        ]);

        return [$ws, $agent, $account];
    }

    public function test_registry_returns_facebook_adapter(): void
    {
        [, , $account] = $this->context();
        $this->assertInstanceOf(FacebookAdapter::class, app(ChannelAdapterRegistry::class)->for($account));
    }

    public function test_webhook_verification_echoes_challenge(): void
    {
        [, , $account] = $this->context();

        $this->get(route('webhooks.facebook.verify', $account).'?hub_mode=subscribe&hub_verify_token=verify-token&hub_challenge=CHAL123')
            ->assertOk()
            ->assertSee('CHAL123');

        $this->get(route('webhooks.facebook.verify', $account).'?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=X')
            ->assertStatus(403);
    }

    public function test_event_requires_valid_signature_and_ingests(): void
    {
        [, $agent, $account] = $this->context();
        $body = [
            'object' => 'page',
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => 'fb-user-1'],
                    'timestamp' => now()->getTimestampMs(),
                    'message' => ['mid' => 'mid-1', 'text' => 'Hello from Messenger'],
                ]],
            ]],
        ];
        $raw = json_encode($body);
        $sig = 'sha256='.hash_hmac('sha256', $raw, 'app-secret-1');

        // bad signature rejected
        $this->call('POST', route('webhooks.facebook', $account), [], [], [], ['HTTP_X-Hub-Signature-256' => 'sha256=bad', 'CONTENT_TYPE' => 'application/json'], $raw)
            ->assertStatus(401);

        // valid signature ingests
        $this->call('POST', route('webhooks.facebook', $account), [], [], [], ['HTTP_X-Hub-Signature-256' => $sig, 'CONTENT_TYPE' => 'application/json'], $raw)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('contacts', ['source' => 'FACEBOOK']);
        $this->assertDatabaseHas('messages', ['body_text' => 'Hello from Messenger']);
        $this->assertDatabaseHas('conversations', ['owner_id' => $agent->id]);
    }

    public function test_send_success(): void
    {
        [, , $account] = $this->context();
        Http::fake(['*/me/messages' => Http::response(['message_id' => 'fb-msg-1', 'recipient_id' => 'fb-user-1'])]);

        $result = app(FacebookAdapter::class)->sendOutbound($account, ['recipient_external_id' => 'fb-user-1', 'text' => 'hi']);

        $this->assertTrue($result['ok']);
        $this->assertSame('fb-msg-1', $result['provider_message_id']);
    }
}
