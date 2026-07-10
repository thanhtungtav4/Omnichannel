<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Jobs\SendChannelMessageJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Channels\Services\ChannelAdapterRegistry;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OutboundTelegramTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_reply_sends_telegram_message_and_marks_outbox_sent(): void
    {
        [$workspace, $agent, $telegram, $contact, $conversation] = $this->seedConversation();

        ExternalIdentity::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'provider' => 'TELEGRAM',
            'provider_account_id' => $telegram->id,
            'provider_user_id' => '5001',
            'provider_chat_id' => '7001',
            'display_name' => 'Telegram Customer',
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'https://api.telegram.org/bottest-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 321],
            ]),
        ]);

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), [
                'body' => 'Chào bạn, mình hỗ trợ ngay.',
            ])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && $request['chat_id'] === '7001'
                && $request['text'] === 'Chào bạn, mình hỗ trợ ngay.';
        });

        $message = Message::query()->where('direction', 'OUTBOUND')->firstOrFail();
        $outbox = OutboxMessage::query()->firstOrFail();

        $this->assertSame('SENT', $outbox->status);
        $this->assertSame('7001', $outbox->recipient_external_id);
        $this->assertSame(['ok' => true, 'result' => ['message_id' => 321]], $outbox->provider_response);
        $this->assertSame('SENT', $message->status);
        $this->assertSame('321', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);
    }

    public function test_agent_can_reply_with_an_image_via_send_photo(): void
    {
        [$workspace, $agent, $telegram, $contact, $conversation] = $this->seedConversation();

        ExternalIdentity::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'provider' => 'TELEGRAM',
            'provider_account_id' => $telegram->id,
            'provider_user_id' => '5001',
            'provider_chat_id' => '7001',
            'display_name' => 'Telegram Customer',
            'last_seen_at' => now(),
        ]);

        Storage::fake('local');
        Http::fake([
            'https://api.telegram.org/bottest-token/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 999],
            ]),
        ]);

        $file = File::image('promo.jpg', 200, 200);

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), [
                'body' => 'Bảng giá đây ạ',
                'image' => $file,
            ])
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendPhoto')
            && $request['chat_id'] === '7001'
            && $request['caption'] === 'Bảng giá đây ạ'
            && str_contains((string) $request['photo'], '/media/outbound/')
            && str_contains((string) $request['photo'], 'signature='));

        $message = Message::query()->where('direction', 'OUTBOUND')->firstOrFail();
        $this->assertSame('IMAGE', $message->message_type);
        $this->assertDatabaseHas('message_attachments', ['message_id' => $message->id]);
    }

    public function test_reply_requires_body_or_image(): void
    {
        [, $agent, , , $conversation] = $this->seedConversation();

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), [])
            ->assertStatus(422);
    }

    public function test_transient_telegram_send_failure_marks_outbox_retrying(): void
    {
        [$workspace, $agent, $telegram, $contact, $conversation] = $this->seedConversation();
        $message = Message::create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'channel_account_id' => $telegram->id,
            'direction' => 'OUTBOUND',
            'sender_type' => 'AGENT',
            'sender_id' => (string) $agent->id,
            'body_text' => 'Mình sẽ kiểm tra giúp bạn.',
            'message_type' => 'TEXT',
            'status' => 'QUEUED',
        ]);
        $outbox = OutboxMessage::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $telegram->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'provider' => 'TELEGRAM',
            'recipient_external_id' => '7001',
            'payload' => ['text' => 'Mình sẽ kiểm tra giúp bạn.'],
            'status' => 'QUEUED',
            'next_attempt_at' => now(),
        ]);

        Http::fake([
            'https://api.telegram.org/bottest-token/sendMessage' => Http::response([
                'ok' => false,
                'error_code' => 500,
                'description' => 'Internal server error',
            ], 500),
        ]);

        app(SendChannelMessageJob::class, ['outboxMessageId' => $outbox->id])
            ->handle(app(ChannelAdapterRegistry::class));

        $outbox->refresh();
        $message->refresh();

        $this->assertSame('RETRYING', $outbox->status);
        $this->assertSame(1, $outbox->attempts);
        $this->assertSame('500', $outbox->last_error_code);
        $this->assertSame('Internal server error', $outbox->last_error_message);
        $this->assertNotNull($outbox->next_attempt_at);
        $this->assertSame('QUEUED', $message->status);
    }

    /**
     * @return array{Workspace, User, ChannelAccount, Contact, Conversation}
     */
    private function seedConversation(): array
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
        $telegram = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'TELEGRAM',
            'name' => 'Telegram Test Bot',
            'status' => 'ACTIVE',
            'credentials' => ['bot_token' => 'test-token'],
            'webhook_secret' => 'telegram-secret',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'owner_id' => $agent->id,
            'full_name' => 'Telegram Customer',
            'status' => 'ACTIVE',
            'source' => 'TELEGRAM',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $telegram->id,
            'contact_id' => $contact->id,
            'owner_id' => $agent->id,
            'status' => 'ASSIGNED',
            'priority' => 'NORMAL',
            'last_message_at' => now(),
        ]);

        return [$workspace, $agent, $telegram, $contact, $conversation];
    }
}
