<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\Stage;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Routing\Models\AgentPresence;
use App\Modules\Routing\Models\RoutingQueue;
use App\Modules\Routing\Models\RoutingQueueMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OmnichannelIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_webhook_creates_crm_records_and_ignores_duplicate_update(): void
    {
        [$workspace, $agent] = $this->seedRoutingContext();
        $telegram = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'TELEGRAM',
            'name' => 'Telegram Test Bot',
            'status' => 'ACTIVE',
            'webhook_secret' => 'telegram-secret',
        ]);
        $payload = [
            'update_id' => 777001,
            'message' => [
                'message_id' => 991,
                'date' => now()->timestamp,
                'from' => ['id' => 5001, 'first_name' => 'Test', 'last_name' => 'Customer'],
                'chat' => ['id' => 5001, 'type' => 'private'],
                'text' => 'Can you advise me about the CRM plan?',
            ],
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'telegram-secret')
            ->postJson(route('webhooks.telegram', $telegram), $payload)
            ->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => false]);

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('conversation_assignments', 1);
        $this->assertDatabaseHas('conversations', [
            'owner_id' => $agent->id,
            'status' => 'ASSIGNED',
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'telegram-secret')
            ->postJson(route('webhooks.telegram', $telegram), $payload)
            ->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => true]);

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_inbound_reopens_a_closed_conversation_instead_of_creating_a_new_one(): void
    {
        [$workspace] = $this->seedRoutingContext();
        $telegram = ChannelAccount::create([
            'workspace_id' => $workspace->id, 'provider' => 'TELEGRAM',
            'name' => 'Bot', 'status' => 'ACTIVE', 'webhook_secret' => 's',
        ]);
        $msg = fn (int $id) => [
            'update_id' => $id,
            'message' => [
                'message_id' => $id, 'date' => now()->timestamp,
                'from' => ['id' => 6001, 'first_name' => 'Repeat', 'last_name' => 'Customer'],
                'chat' => ['id' => 6001, 'type' => 'private'],
                'text' => "msg {$id}",
            ],
        ];

        // First message opens a thread.
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 's')
            ->postJson(route('webhooks.telegram', $telegram), $msg(1))->assertOk();
        $this->assertDatabaseCount('conversations', 1);

        // Close it.
        Conversation::query()->firstOrFail()->forceFill(['status' => 'CLOSED', 'closed_at' => now()])->save();

        // Customer messages again -> same conversation, reopened (not a new one).
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 's')
            ->postJson(route('webhooks.telegram', $telegram), $msg(2))->assertOk();

        $this->assertDatabaseCount('conversations', 1);
        $reopened = Conversation::query()->firstOrFail();
        $this->assertNotSame('CLOSED', $reopened->status);
        $this->assertNull($reopened->closed_at);
    }

    public function test_telegram_webhook_rejects_invalid_secret_before_processing(): void
    {
        [$workspace] = $this->seedRoutingContext();
        $telegram = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'TELEGRAM',
            'name' => 'Telegram Test Bot',
            'status' => 'ACTIVE',
            'webhook_secret' => 'telegram-secret',
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong-secret')
            ->postJson(route('webhooks.telegram', $telegram), ['update_id' => 1])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_WEBHOOK_SECRET');

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_telegram_webhook_command_registers_set_webhook_with_secret_token(): void
    {
        config(['app.url' => 'https://crm.example.test']);
        Http::fake([
            'https://api.telegram.org/bottest-token/setWebhook' => Http::response([
                'ok' => true,
                'result' => true,
                'description' => 'Webhook was set',
            ]),
        ]);

        [$workspace] = $this->seedRoutingContext();
        $telegram = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'TELEGRAM',
            'name' => 'Telegram Test Bot',
            'status' => 'DRAFT',
            'credentials' => ['bot_token' => 'test-token'],
            'webhook_secret' => 'telegram-secret',
        ]);

        $this->artisan('channels:telegram-webhook', [
            'account' => $telegram->id,
            '--url' => 'https://crm.example.test/webhooks/telegram/'.$telegram->id,
            '--drop-pending' => true,
        ])->assertExitCode(0);

        Http::assertSent(function ($request) use ($telegram) {
            return $request->url() === 'https://api.telegram.org/bottest-token/setWebhook'
                && $request['url'] === 'https://crm.example.test/webhooks/telegram/'.$telegram->id
                && $request['secret_token'] === 'telegram-secret'
                && $request['drop_pending_updates'] === true
                && $request['allowed_updates'] === ['message', 'edited_message', 'callback_query', 'my_chat_member'];
        });

        $telegram->refresh();

        $this->assertSame('ACTIVE', $telegram->status);
        $this->assertSame('https://crm.example.test/webhooks/telegram/'.$telegram->id, $telegram->webhook_url);
        $this->assertSame('telegram-secret', $telegram->webhook_secret);
        $this->assertNull($telegram->last_error_code);
        $this->assertNull($telegram->last_error_message);
    }

    public function test_mock_zalo_inbound_uses_provider_shape_and_assigns_support(): void
    {
        [$workspace, $agent] = $this->seedRoutingContext();
        ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'ZALO_OA',
            'name' => 'Zalo OA Test',
            'status' => 'ACTIVE',
        ]);
        $text = 'Can Zalo messages sync into one CRM inbox?';

        $this->actingAs($agent)
            ->post(route('admin.mock-inbound'), [
                'provider' => 'ZALO_OA',
                'sender_name' => 'Zalo Customer',
                'text' => $text,
            ])
            ->assertRedirect();

        $this->assertSame('ZALO_OA', Contact::query()->firstOrFail()->source);
        $this->assertSame($text, Message::query()->firstOrFail()->body_text);
        $this->assertSame($agent->id, Conversation::query()->firstOrFail()->owner_id);
        $this->assertSame('NEW', Lead::query()->firstOrFail()->status);
    }

    public function test_zalo_personal_sidecar_webhook_requires_secret_and_ingests(): void
    {
        [$workspace, $agent] = $this->seedRoutingContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'ZALO_PERSONAL',
            'name' => 'Zalo Personal Sidecar',
            'status' => 'ACTIVE',
            'webhook_secret' => 'sidecar-secret',
        ]);
        $payload = [
            'event_name' => 'user_send_text',
            'timestamp' => now()->getTimestampMs(),
            'message' => ['msg_id' => 'zalo-p-1', 'text' => 'Xin chao tu Zalo ca nhan'],
            'sender' => ['id' => 'zalo-user-77', 'name' => 'Khach Zalo'],
        ];

        // Missing/invalid sidecar token is rejected.
        $this->postJson(route('webhooks.zalo', $account), $payload)->assertStatus(401);

        // Valid token ingests and assigns.
        $this->withHeader('X-Sidecar-Token', 'sidecar-secret')
            ->postJson(route('webhooks.zalo', $account), $payload)
            ->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => false]);

        $this->assertDatabaseHas('contacts', ['full_name' => 'Khach Zalo', 'source' => 'ZALO_PERSONAL']);
        $this->assertDatabaseHas('messages', ['body_text' => 'Xin chao tu Zalo ca nhan']);
        $this->assertDatabaseHas('conversations', ['owner_id' => $agent->id]);

        // Duplicate msg_id is ignored (idempotent).
        $this->withHeader('X-Sidecar-Token', 'sidecar-secret')
            ->postJson(route('webhooks.zalo', $account), $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true]);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_refresh_zalo_profile_updates_contact_and_identity_from_sidecar(): void
    {
        [$workspace, $agent] = $this->seedRoutingContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'ZALO_PERSONAL',
            'name' => 'Nick refresh',
            'status' => 'ACTIVE',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'owner_id' => $agent->id,
            'source' => 'ZALO_PERSONAL',
            'status' => 'ACTIVE',
            'full_name' => 'Khách zalo-user-1',
        ]);
        ExternalIdentity::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'provider' => 'ZALO_PERSONAL',
            'provider_account_id' => $account->id,
            'provider_user_id' => 'zalo-user-1',
        ]);
        config(['services.zalo_sidecar.url' => 'http://sidecar.test', 'services.zalo_sidecar.token' => 'sidecar-token']);
        Http::fake([
            'http://sidecar.test/accounts/*/user/*' => Http::response([
                'ok' => true,
                'displayName' => 'Nguyễn Zalo',
                'avatar' => 'https://zalo.test/a.jpg',
            ]),
        ]);

        $this->actingAs($agent)
            ->postJson(route('admin.contacts.refresh-profile', $contact))
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Đã cập nhật hồ sơ Zalo.']);

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'full_name' => 'Nguyễn Zalo',
            'avatar_url' => 'https://zalo.test/a.jpg',
        ]);
        $this->assertDatabaseHas('external_identities', [
            'contact_id' => $contact->id,
            'display_name' => 'Nguyễn Zalo',
            'avatar_url' => 'https://zalo.test/a.jpg',
        ]);
    }

    public function test_refresh_zalo_profile_returns_error_when_sidecar_has_no_profile_fields(): void
    {
        [$workspace, $agent] = $this->seedRoutingContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'ZALO_PERSONAL',
            'name' => 'Nick refresh empty',
            'status' => 'ACTIVE',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'owner_id' => $agent->id,
            'source' => 'ZALO_PERSONAL',
            'status' => 'ACTIVE',
            'full_name' => 'Original Name',
        ]);
        ExternalIdentity::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'provider' => 'ZALO_PERSONAL',
            'provider_account_id' => $account->id,
            'provider_user_id' => 'zalo-user-empty',
        ]);
        config(['services.zalo_sidecar.url' => 'http://sidecar.test', 'services.zalo_sidecar.token' => 'sidecar-token']);
        Http::fake([
            'http://sidecar.test/accounts/*/user/*' => Http::response(['ok' => true]),
        ]);

        $this->actingAs($agent)
            ->postJson(route('admin.contacts.refresh-profile', $contact))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Sidecar Zalo không trả về tên hoặc avatar mới.']);

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'full_name' => 'Original Name',
            'avatar_url' => null,
        ]);
    }

    /**
     * @return array{Workspace, User}
     */
    private function seedRoutingContext(): array
    {
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'slug' => 'test',
            'status' => 'ACTIVE',
        ]);
        $agent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'display_name' => 'Agent Test',
            'role' => 'support_agent',
            'status' => 'ACTIVE',
        ]);
        $pipeline = Pipeline::create([
            'workspace_id' => $workspace->id,
            'name' => 'Lead Pipeline',
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
