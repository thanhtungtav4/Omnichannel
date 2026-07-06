<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\Stage;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\EntityLink;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Routing\Models\AgentPresence;
use App\Modules\Routing\Models\RoutingQueue;
use App\Modules\Routing\Models\RoutingQueueMember;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $workspace = Workspace::query()->firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'CRM Demo Workspace', 'status' => 'ACTIVE'],
        );

        $owner = User::query()->updateOrCreate(['email' => 'owner@example.com'], [
            'workspace_id' => $workspace->id,
            'name' => 'Owner CRM',
            'display_name' => 'Owner',
            'password' => 'password',
            'role' => 'owner',
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
            'last_seen_at' => now(),
        ]);

        $supportLead = User::query()->updateOrCreate(['email' => 'lead@example.com'], [
            'workspace_id' => $workspace->id,
            'name' => 'Support Lead',
            'display_name' => 'Support Lead',
            'password' => 'password',
            'role' => 'support_lead',
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
            'last_seen_at' => now()->subMinutes(2),
        ]);

        $supportAgent = User::query()->updateOrCreate(['email' => 'agent@example.com'], [
            'workspace_id' => $workspace->id,
            'name' => 'Support Agent',
            'display_name' => 'Agent Linh',
            'password' => 'password',
            'role' => 'support_agent',
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
            'last_seen_at' => now()->subMinutes(4),
        ]);

        $sales = User::query()->updateOrCreate(['email' => 'sales@example.com'], [
            'workspace_id' => $workspace->id,
            'name' => 'Sales User',
            'display_name' => 'Sales Minh',
            'password' => 'password',
            'role' => 'sales',
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
            'last_seen_at' => now()->subMinutes(12),
        ]);

        foreach ([$owner, $supportLead, $supportAgent, $sales] as $user) {
            AgentPresence::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $user->id],
                [
                    'status' => in_array($user->role, ['owner', 'support_lead', 'support_agent'], true) ? 'ONLINE' : 'AWAY',
                    'active_conversation_count' => $user->is($supportLead) ? 1 : 0,
                    'last_seen_at' => $user->last_seen_at,
                ],
            );
        }

        $pipeline = Pipeline::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'type' => 'LEAD', 'is_default' => true],
            ['name' => 'Default Lead Pipeline', 'sort_order' => 1],
        );

        $newStage = Stage::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'pipeline_id' => $pipeline->id, 'sort_order' => 1],
            ['name' => 'New inquiry', 'status_group' => 'OPEN', 'color_token' => 'secondary'],
        );
        Stage::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'pipeline_id' => $pipeline->id, 'sort_order' => 2],
            ['name' => 'Consulting', 'status_group' => 'OPEN', 'color_token' => 'default'],
        );

        $telegram = ChannelAccount::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'provider' => 'TELEGRAM'],
            [
                'name' => 'Telegram Bot Demo',
                'status' => 'ACTIVE',
                'credentials' => ['bot_token' => 'write-only-demo-token'],
                'settings' => ['bot_username' => 'crm_demo_bot'],
                'webhook_secret' => 'telegram-demo-secret',
                'webhook_url' => '/webhooks/telegram/demo',
                'last_webhook_at' => now()->subMinutes(4),
                'last_health_check_at' => now()->subMinutes(2),
            ],
        );

        $zalo = ChannelAccount::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'provider' => 'ZALO_OA'],
            [
                'name' => 'Zalo OA Demo',
                'status' => 'DEGRADED',
                'credentials' => ['oa_id' => 'demo-oa', 'access_token' => 'write-only-demo-token'],
                'settings' => ['token_expires_at' => now()->addDays(7)->toIso8601String()],
                'webhook_url' => '/webhooks/zalo/demo',
                'last_webhook_at' => now()->subMinutes(18),
                'last_health_check_at' => now()->subMinutes(5),
                'last_error_code' => 'TOKEN_EXPIRES_SOON',
                'last_error_message' => 'Access token expires in 7 days.',
            ],
        );

        $queue = RoutingQueue::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Support tư vấn'],
            [
                'status' => 'ACTIVE',
                'mode' => 'STICKY_THEN_EVEN',
                'timeout_seconds' => 300,
                'max_active_per_agent' => 5,
                'requires_online' => true,
            ],
        );

        foreach ([$supportLead, $supportAgent, $owner] as $index => $user) {
            RoutingQueueMember::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'routing_queue_id' => $queue->id, 'user_id' => $user->id],
                ['sort_order' => $index + 1, 'status' => 'ACTIVE', 'last_assigned_at' => now()->subMinutes(20 - ($index * 5))],
            );
        }

        $contacts = [
            ['name' => 'Nguyen Thanh Hoa', 'provider' => 'ZALO_OA', 'account' => $zalo, 'owner' => $supportLead, 'text' => 'Mình muốn được tư vấn gói CRM cho team sale 8 người.', 'status' => 'WAITING_AGENT'],
            ['name' => 'Tran Quoc Anh', 'provider' => 'TELEGRAM', 'account' => $telegram, 'owner' => $supportAgent, 'text' => 'Bên mình cần sync tin nhắn Zalo về một chỗ, báo giá giúp nhé.', 'status' => 'ASSIGNED'],
            ['name' => 'Le Mai', 'provider' => 'TELEGRAM', 'account' => $telegram, 'owner' => null, 'text' => 'Cho mình hỏi tích hợp Telegram group được không?', 'status' => 'WAITING_AGENT'],
        ];

        foreach ($contacts as $index => $seed) {
            $contact = Contact::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'full_name' => $seed['name']],
                [
                    'owner_id' => $seed['owner']?->id,
                    'phone' => '09000000'.($index + 1),
                    'email' => 'customer'.($index + 1).'@example.com',
                    'source' => $seed['provider'],
                    'last_inbound_at' => now()->subMinutes(30 - ($index * 8)),
                ],
            );

            ExternalIdentity::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'provider' => $seed['provider'],
                    'provider_account_id' => $seed['account']->id,
                    'provider_user_id' => 'demo-user-'.$index,
                ],
                [
                    'contact_id' => $contact->id,
                    'provider_chat_id' => 'demo-chat-'.$index,
                    'display_name' => $seed['name'],
                    'last_seen_at' => now()->subMinutes(30 - ($index * 8)),
                ],
            );

            $lead = Lead::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'contact_id' => $contact->id],
                [
                    'owner_id' => $seed['owner']?->id,
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $newStage->id,
                    'title' => 'Tư vấn CRM - '.$seed['name'],
                    'status' => 'NEW',
                    'source' => $seed['provider'],
                    'value_amount' => 15000000 + ($index * 3000000),
                    'last_activity_at' => now()->subMinutes(30 - ($index * 8)),
                ],
            );

            $conversation = Conversation::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'channel_account_id' => $seed['account']->id],
                [
                    'owner_id' => $seed['owner']?->id,
                    'routing_queue_id' => $queue->id,
                    'status' => $seed['status'],
                    'priority' => $index === 0 ? 'HIGH' : 'NORMAL',
                    'subject' => $seed['name'],
                    'last_message_at' => now()->subMinutes(30 - ($index * 8)),
                    'last_customer_message_at' => now()->subMinutes(30 - ($index * 8)),
                    'first_response_due_at' => now()->subMinutes(25 - ($index * 8)),
                    'next_response_due_at' => $index === 0 ? now()->subMinutes(2) : now()->addMinutes(8),
                ],
            );

            $message = Message::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'conversation_id' => $conversation->id, 'provider_message_id' => 'demo-message-'.$index, 'direction' => 'INBOUND'],
                [
                    'channel_account_id' => $seed['account']->id,
                    'sender_type' => 'CUSTOMER',
                    'sender_id' => 'demo-user-'.$index,
                    'body_text' => $seed['text'],
                    'message_type' => 'TEXT',
                    'status' => 'RECEIVED',
                    'raw_payload' => ['seed' => true],
                    'created_at' => now()->subMinutes(30 - ($index * 8)),
                    'updated_at' => now()->subMinutes(30 - ($index * 8)),
                ],
            );

            $conversation->forceFill(['last_message_id' => $message->id])->save();

            EntityLink::query()->firstOrCreate([
                'workspace_id' => $workspace->id,
                'source_type' => 'inbox.conversation',
                'source_id' => $conversation->id,
                'target_type' => 'crm.lead',
                'target_id' => $lead->id,
                'relation' => 'SALES_CONTEXT',
            ], [
                'metadata' => ['seed' => true],
                'created_by_id' => $owner->id,
                'created_at' => now(),
            ]);
        }

        $failedConversation = Conversation::query()->where('workspace_id', $workspace->id)->first();
        if ($failedConversation) {
            $failedMessage = Message::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'conversation_id' => $failedConversation->id, 'provider_message_id' => 'demo-outbound-failed', 'direction' => 'OUTBOUND'],
                [
                    'channel_account_id' => $failedConversation->channel_account_id,
                    'sender_type' => 'AGENT',
                    'sender_id' => (string) $supportLead->id,
                    'body_text' => 'Cảm ơn bạn, mình sẽ gửi thông tin chi tiết.',
                    'message_type' => 'TEXT',
                    'status' => 'FAILED',
                    'created_at' => now()->subMinutes(5),
                    'updated_at' => now()->subMinutes(5),
                ],
            );

            OutboxMessage::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'message_id' => $failedMessage->id],
                [
                    'channel_account_id' => $failedConversation->channel_account_id,
                    'conversation_id' => $failedConversation->id,
                    'provider' => $failedConversation->channelAccount->provider,
                    'payload' => ['text' => $failedMessage->body_text],
                    'status' => 'FAILED',
                    'attempts' => 3,
                    'last_error_code' => 'PROVIDER_TIMEOUT',
                    'last_error_message' => 'Provider did not respond before timeout.',
                ],
            );
        }
    }
}
