<?php

namespace App\Modules\Admin\Services;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Channels\Models\WebhookEvent;
use App\Modules\Crm\Models\Lead;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Routing\Models\AgentPresence;
use App\Modules\Routing\Models\RoutingQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(string $workspaceId): array
    {
        $openConversations = Conversation::query()->where('workspace_id', $workspaceId)->where('status', '!=', 'CLOSED')->count();
        $waitingAgent = Conversation::query()->where('workspace_id', $workspaceId)->where('status', 'WAITING_AGENT')->count();
        $slaBreached = Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', '!=', 'CLOSED')
            ->where('next_response_due_at', '<', now())
            ->count();

        return [
            'stats' => [
                ['label' => 'Open conversations', 'value' => $openConversations, 'hint' => 'Active customer threads'],
                ['label' => 'Waiting agent', 'value' => $waitingAgent, 'hint' => 'Needs support response'],
                ['label' => 'SLA breaches', 'value' => $slaBreached, 'hint' => 'Past next response target'],
                ['label' => 'Active leads', 'value' => Lead::query()->where('workspace_id', $workspaceId)->whereIn('status', ['NEW', 'QUALIFYING', 'OPEN'])->count(), 'hint' => 'Open sales opportunities'],
            ],
            'channels' => ChannelAccount::query()
                ->where('workspace_id', $workspaceId)
                ->latest()
                ->get()
                ->map(fn (ChannelAccount $account) => [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'name' => $account->name,
                    'status' => $account->status,
                    'lastWebhookAt' => $account->last_webhook_at?->diffForHumans(),
                    'lastHealthCheckAt' => $account->last_health_check_at?->diffForHumans(),
                    'lastError' => $account->last_error_message,
                ]),
            'queues' => RoutingQueue::query()
                ->where('workspace_id', $workspaceId)
                ->withCount('members')
                ->get()
                ->map(fn (RoutingQueue $queue) => [
                    'id' => $queue->id,
                    'name' => $queue->name,
                    'mode' => $queue->mode,
                    'status' => $queue->status,
                    'members' => $queue->members_count,
                    'maxActive' => $queue->max_active_per_agent,
                    'requiresOnline' => $queue->requires_online,
                ]),
            'agents' => AgentPresence::query()
                ->where('workspace_id', $workspaceId)
                ->with('user')
                ->get()
                ->map(fn (AgentPresence $presence) => [
                    'id' => $presence->user_id,
                    'name' => $presence->user?->display_name ?: $presence->user?->name,
                    'status' => $presence->status,
                    'active' => $presence->active_conversation_count,
                    'lastSeenAt' => $presence->last_seen_at?->diffForHumans(),
                ]),
            'recentConversations' => $this->conversationList($workspaceId, 6),
            'failedEvents' => WebhookEvent::query()->where('workspace_id', $workspaceId)->where('status', 'FAILED')->count(),
            'failedOutbox' => OutboxMessage::query()->where('workspace_id', $workspaceId)->where('status', 'FAILED')->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conversationList(string $workspaceId, int $limit = 20): array
    {
        return Conversation::query()
            ->where('workspace_id', $workspaceId)
            // Eager-load the denormalized last message (via last_message_id) —
            // one join instead of a per-row "latest message" subquery.
            ->with(['contact', 'owner', 'channelAccount', 'lastMessage'])
            // Unread = inbound messages newer than the agent's last reply (or all
            // inbound if the agent never replied). One correlated subquery, no
            // N+1. ponytail: true "read receipts" per agent would need a
            // last_read_at column; this SLA-style heuristic is enough for a badge.
            ->withCount(['messages as unread_count' => function ($q) {
                $q->where('direction', 'INBOUND')
                    ->whereColumn('messages.created_at', '>', DB::raw('COALESCE(conversations.last_agent_message_at, \'-infinity\'::timestamptz)'));
            }])
            ->latest('last_message_at')
            ->limit($limit)
            ->get()
            ->map(function (Conversation $conversation) {
                $lastMessage = $conversation->lastMessage;
                $dueAt = $conversation->next_response_due_at;

                return [
                    'id' => $conversation->id,
                    'subject' => $conversation->subject,
                    'status' => $conversation->status,
                    'priority' => $conversation->priority,
                    'channel' => $conversation->channelAccount?->provider,
                    'channelName' => $conversation->channelAccount?->name,
                    'contact' => [
                        'id' => $conversation->contact?->id,
                        'name' => $conversation->contact?->full_name,
                        'avatarUrl' => $conversation->contact?->avatar_url,
                        'phone' => $conversation->contact?->phone,
                        'email' => $conversation->contact?->email,
                    ],
                    'owner' => $conversation->owner ? [
                        'id' => $conversation->owner->id,
                        'name' => $conversation->owner->display_name ?: $conversation->owner->name,
                    ] : null,
                    'lastMessage' => $lastMessage?->body_text,
                    'lastDirection' => $lastMessage?->direction,
                    'lastMessageStatus' => $lastMessage?->status,
                    'lastMessageAt' => $conversation->last_message_at?->diffForHumans(),
                    // Unanswered = the last message is from the customer and no agent
                    // reply followed. Drives the unread dot in the queue.
                    'isUnanswered' => in_array($conversation->status, ['OPEN', 'WAITING_AGENT'], true)
                        && $lastMessage?->direction === 'INBOUND',
                    // Number of customer messages awaiting a reply — drives the
                    // count badge. Capped display at 99+ on the frontend.
                    'unreadCount' => (int) $conversation->unread_count,
                    'slaState' => $dueAt && $dueAt->isPast() ? 'BREACHED' : ($dueAt && $dueAt->lessThan(Carbon::now()->addMinutes(5)) ? 'DUE_SOON' : 'OK'),
                ];
            })
            ->all();
    }
}
