<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\Stage;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Routing\Models\AgentPresence;
use App\Modules\Routing\Models\RoutingQueue;
use App\Modules\Routing\Models\RoutingQueueMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_webhook_without_configured_secret_is_rejected(): void
    {
        [$workspace] = $this->context();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'TELEGRAM',
            'name' => 'No-secret Bot',
            'status' => 'ACTIVE',
            'webhook_secret' => null,
        ]);

        $this->postJson(route('webhooks.telegram', $account), [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'date' => now()->timestamp,
                'from' => ['id' => 42, 'first_name' => 'Mallory'],
                'chat' => ['id' => 42, 'type' => 'private'],
                'text' => 'forged',
            ],
        ])->assertStatus(401)
            ->assertJson(['error' => ['code' => 'WEBHOOK_SECRET_NOT_CONFIGURED']]);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_same_provider_message_in_two_events_is_deduped_not_500(): void
    {
        [$workspace, $agent] = $this->context();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'TELEGRAM',
            'name' => 'Bot',
            'status' => 'ACTIVE',
            'webhook_secret' => 'sekret',
        ]);

        $message = fn (int $updateId) => [
            'update_id' => $updateId, // distinct -> distinct idempotency key
            'message' => [
                'message_id' => 555, // SAME provider message id
                'date' => now()->timestamp,
                'from' => ['id' => 7, 'first_name' => 'Real', 'last_name' => 'Customer'],
                'chat' => ['id' => 7, 'type' => 'private'],
                'text' => 'hello',
            ],
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'sekret')
            ->postJson(route('webhooks.telegram', $account), $message(100))
            ->assertOk()
            ->assertJson(['duplicate' => false]);

        // Second event, new update_id but same message_id: must NOT 500 on the
        // unique message index, and must not create a second message row.
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'sekret')
            ->postJson(route('webhooks.telegram', $account), $message(101))
            ->assertOk()
            ->assertJson(['duplicate' => true]);

        $this->assertSame(1, Message::query()->count());
        $this->assertDatabaseHas('webhook_events', ['status' => 'IGNORED']);
    }

    /** @return array{0: Workspace, 1: User} */
    private function context(): array
    {
        $workspace = Workspace::create(['name' => 'WS', 'slug' => 'ws', 'status' => 'ACTIVE']);
        $agent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'display_name' => 'Agent',
            'role' => 'support_agent',
            'status' => 'ACTIVE',
        ]);
        $pipeline = Pipeline::create([
            'workspace_id' => $workspace->id,
            'name' => 'Lead',
            'type' => 'LEAD',
            'is_default' => true,
            'sort_order' => 1,
        ]);
        Stage::create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'New',
            'status_group' => 'OPEN',
            'sort_order' => 1,
        ]);
        $queue = RoutingQueue::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'status' => 'ACTIVE',
            'mode' => 'STICKY_THEN_EVEN',
            'timeout_seconds' => 300,
            'max_active_per_agent' => 5,
            'requires_online' => true,
        ]);
        RoutingQueueMember::create([
            'workspace_id' => $workspace->id,
            'routing_queue_id' => $queue->id,
            'user_id' => $agent->id,
            'sort_order' => 1,
            'status' => 'ACTIVE',
        ]);
        AgentPresence::create([
            'workspace_id' => $workspace->id,
            'user_id' => $agent->id,
            'status' => 'ONLINE',
            'active_conversation_count' => 0,
            'last_seen_at' => now(),
        ]);

        return [$workspace, $agent];
    }
}
