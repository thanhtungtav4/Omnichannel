<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_page_exposes_queue_thread_and_outbox_state(): void
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
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'owner_id' => $agent->id,
            'full_name' => 'Telegram Customer',
            'status' => 'ACTIVE',
            'source' => 'TELEGRAM',
        ]);
        ExternalIdentity::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'provider' => 'TELEGRAM',
            'provider_account_id' => $telegram->id,
            'provider_user_id' => '5001',
            'provider_chat_id' => '7001',
            'display_name' => 'Telegram Customer',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $telegram->id,
            'contact_id' => $contact->id,
            'owner_id' => $agent->id,
            'status' => 'WAITING_AGENT',
            'priority' => 'HIGH',
            'last_message_at' => now(),
        ]);
        Message::create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'channel_account_id' => $telegram->id,
            'provider_message_id' => 'inbound-1',
            'direction' => 'INBOUND',
            'sender_type' => 'CUSTOMER',
            'body_text' => 'Can you advise me today?',
            'message_type' => 'TEXT',
            'status' => 'RECEIVED',
        ]);
        $outbound = Message::create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'channel_account_id' => $telegram->id,
            'direction' => 'OUTBOUND',
            'sender_type' => 'AGENT',
            'sender_id' => (string) $agent->id,
            'body_text' => 'I am checking it now.',
            'message_type' => 'TEXT',
            'status' => 'FAILED',
        ]);
        OutboxMessage::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $telegram->id,
            'conversation_id' => $conversation->id,
            'message_id' => $outbound->id,
            'provider' => 'TELEGRAM',
            'recipient_external_id' => '7001',
            'payload' => ['text' => 'I am checking it now.'],
            'status' => 'FAILED',
            'attempts' => 5,
            'last_error_code' => '500',
            'last_error_message' => 'Provider failed.',
        ]);

        $this->actingAs($agent)
            ->get(route('admin.inbox', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/inbox')
                ->where('stats.open', 1)
                ->where('stats.waitingAgent', 1)
                ->where('stats.failedOutbox', 1)
                ->has('conversations', 1)
                ->where('conversations.0.id', $conversation->id)
                ->where('conversations.0.channel', 'TELEGRAM')
                ->where('activeConversation.id', $conversation->id)
                ->where('activeConversation.contact.name', 'Telegram Customer')
                ->has('activeConversation.messages', 2)
                ->where('activeConversation.messages.1.outboxStatus', 'FAILED')
                ->where('activeConversation.messages.1.outboxError', 'Provider failed.')
            );
    }
}
