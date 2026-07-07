<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Services\AdminDashboardService;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\Lead;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Routing\Models\AgentPresence;
use App\Modules\Routing\Models\RoutingQueue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function __construct(private readonly AdminDashboardService $dashboard)
    {
    }

    public function overview(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        return Inertia::render('admin/overview', $this->dashboard->overview($workspaceId));
    }

    public function inbox(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $displayTz = config('app.display_timezone');
        $conversationQuery = Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->with([
                'contact.identities',
                'contact.leads',
                // Recent notes for the right rail — pinned first, then newest.
                // Not pinned-only, so a note just typed in the composer shows up.
                'contact.notes' => fn ($q) => $q->orderByDesc('pinned')->latest()->limit(10),
                'owner',
                'channelAccount',
            ]);
        $conversation = $request->query('conversation')
            ? (clone $conversationQuery)->whereKey($request->query('conversation'))->first()
            : null;
        $conversation ??= $conversationQuery->latest('last_message_at')->first();

        $pageSize = 50;
        $threadMessages = collect();
        $hasMore = false;
        $outboxByMessageId = collect();

        if ($conversation) {
            // Paginate by id only. Message ids are UUIDv7 (monotonic, unique),
            // so a single-column cursor is deterministic — created_at has many
            // ties from backfill inserts and can't be a reliable cursor.
            $total = $conversation->messages()->count();
            $threadMessages = $conversation->messages()
                ->with('attachments')
                ->orderByDesc('id')
                ->limit($pageSize)
                ->get()
                ->reverse() // display oldest-first (by id)
                ->values();
            $hasMore = $total > $threadMessages->count();

            $outboxByMessageId = OutboxMessage::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('message_id', $threadMessages->pluck('id'))
                ->get()
                ->keyBy('message_id');

        }

        return Inertia::render('admin/inbox', [
            'stats' => [
                'open' => Conversation::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('status', '!=', 'CLOSED')
                    ->count(),
                'waitingAgent' => Conversation::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'WAITING_AGENT')
                    ->count(),
                'unassigned' => Conversation::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereNull('owner_id')
                    ->where('status', '!=', 'CLOSED')
                    ->count(),
                'failedOutbox' => OutboxMessage::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'FAILED')
                    ->count(),
            ],
            'conversations' => $this->dashboard->conversationList($workspaceId, 50),
            'activeConversation' => $conversation ? [
                'id' => $conversation->id,
                'subject' => $conversation->subject,
                'status' => $conversation->status,
                'priority' => $conversation->priority,
                'channel' => $conversation->channelAccount?->provider,
                'contact' => $conversation->contact ? [
                    'id' => $conversation->contact->id,
                    'name' => $conversation->contact->full_name,
                    'avatarUrl' => $conversation->contact->avatar_url,
                    'phone' => $conversation->contact->phone,
                    'email' => $conversation->contact->email,
                    'source' => $conversation->contact->source,
                    'status' => $conversation->contact->status,
                    'tags' => $conversation->contact->tags ?? [],
                    'lastInboundAt' => $conversation->contact->last_inbound_at?->diffForHumans(),
                    'identities' => $conversation->contact->identities->map(fn ($identity) => [
                        'id' => $identity->id,
                        'provider' => $identity->provider,
                        'displayName' => $identity->display_name,
                        'providerUserId' => $identity->provider_user_id,
                    ]),
                    // Open leads for this contact — the HubSpot right-rail "deals".
                    'leads' => $conversation->contact->leads
                        ->whereNotIn('status', ['WON', 'LOST'])
                        ->map(fn ($lead) => [
                            'id' => $lead->id,
                            'title' => $lead->title,
                            'status' => $lead->status,
                        ])->values(),
                    // Recent CSKH notes (pinned first) — quick context without
                    // opening the full record.
                    'notes' => $conversation->contact->notes->map(fn ($note) => [
                        'id' => $note->id,
                        'body' => $note->body,
                        'pinned' => (bool) $note->pinned,
                    ])->values(),
                    // Other conversations this contact has had (channel history).
                    'otherConversations' => $conversation->contact->conversations()
                        ->where('id', '!=', $conversation->id)
                        ->with('channelAccount')
                        ->latest('last_message_at')
                        ->limit(8)
                        ->get()
                        ->map(fn ($c) => [
                            'id' => $c->id,
                            'channel' => $c->channelAccount?->provider,
                            'status' => $c->status,
                            'lastMessageAt' => $c->last_message_at?->diffForHumans(),
                        ]),
                ] : null,
                'owner' => $conversation->owner ? [
                    'id' => $conversation->owner->id,
                    'name' => $conversation->owner->display_name ?: $conversation->owner->name,
                    // Online = ONLINE status seen within the stale window (90s).
                    'online' => AgentPresence::query()
                        ->where('user_id', $conversation->owner->id)
                        ->where('status', 'ONLINE')
                        ->where('last_seen_at', '>=', now()->subSeconds(90))
                        ->exists(),
                ] : null,
                'isGroup' => (bool) $conversation->is_group,
                'hasMoreMessages' => $hasMore,
                'messages' => $threadMessages->map(fn ($message) => [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'senderType' => $message->sender_type,
                    'senderId' => $message->sender_id,
                    'body' => $message->body_text,
                    'messageType' => $message->message_type,
                    'attachmentUrl' => $message->attachments->first()?->metadata['url'] ?? null,
                    'status' => $message->status,
                    'outboxStatus' => $outboxByMessageId->get($message->id)?->status,
                    'outboxError' => $outboxByMessageId->get($message->id)?->last_error_message,
                    'timeLabel' => $message->created_at?->timezone($displayTz)->format('H:i'),
                    'dateIso' => $message->created_at?->timezone($displayTz)->toDateString(),
                ])->values(),
            ] : null,
            'agents' => User::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('role', ['support_lead', 'support_agent', 'admin', 'owner'])
                ->orderBy('name')
                ->get(['id', 'name', 'display_name', 'role']),
        ]);
    }

    public function contacts(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        return Inertia::render('admin/contacts', [
            'contacts' => Contact::query()
                ->where('workspace_id', $workspaceId)
                ->with('owner')
                ->withCount('identities')
                ->latest('last_inbound_at')
                ->get()
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'name' => $contact->full_name,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'source' => $contact->source,
                    'status' => $contact->status,
                    'owner' => $contact->owner?->display_name ?: $contact->owner?->name,
                    'identities' => $contact->identities_count,
                    'lastInboundAt' => $contact->last_inbound_at?->diffForHumans(),
                ]),
            'leads' => Lead::query()
                ->where('workspace_id', $workspaceId)
                ->with('contact', 'owner')
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (Lead $lead) => [
                    'id' => $lead->id,
                    'title' => $lead->title,
                    'status' => $lead->status,
                    'source' => $lead->source,
                    'valueAmount' => $lead->value_amount,
                    'contact' => $lead->contact?->full_name,
                    'contactId' => $lead->contact_id,
                    'owner' => $lead->owner?->display_name ?: $lead->owner?->name,
                    'lastActivityAt' => $lead->last_activity_at?->diffForHumans(),
                ]),
        ]);
    }

    /** Older messages for infinite scroll-up (before a given message id). */
    public function messagesOlder(Request $request, Conversation $conversation): \Illuminate\Http\JsonResponse
    {
        abort_unless($conversation->workspace_id === $this->workspaceId($request), 403);
        $displayTz = config('app.display_timezone');
        $beforeId = $request->query('before');
        $before = $beforeId ? Message::find($beforeId) : null;

        $pageSize = 50;
        $query = $conversation->messages();
        if ($before) {
            // Single-column id cursor (UUIDv7 monotonic) — deterministic.
            $query->where('id', '<', $before->id);
        }
        $older = $query
            ->with('attachments')
            ->orderByDesc('id')
            ->limit($pageSize)
            ->get()
            ->reverse()
            ->values();
        $hasMore = $older->count() === $pageSize;

        $outbox = OutboxMessage::query()
            ->whereIn('message_id', $older->pluck('id'))
            ->get()->keyBy('message_id');

        return response()->json([
            'hasMore' => $hasMore,
            'messages' => $older
                ->sortBy(fn ($m) => $m->created_at?->getTimestampMs() ?? 0)
                ->values()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'direction' => $m->direction,
                    'senderType' => $m->sender_type,
                    'senderId' => $m->sender_id,
                    'body' => $m->body_text,
                    'messageType' => $m->message_type,
                    'attachmentUrl' => $m->attachments->first()?->metadata['url'] ?? null,
                    'status' => $m->status,
                    'outboxStatus' => $outbox->get($m->id)?->status,
                    'outboxError' => $outbox->get($m->id)?->last_error_message,
                    'timeLabel' => $m->created_at?->timezone($displayTz)->format('H:i'),
                    'dateIso' => $m->created_at?->timezone($displayTz)->toDateString(),
                ]),
        ]);
    }

    /** Lead pipeline as a kanban grouped by status. */
    public function leads(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        $leads = Lead::query()
            ->where('workspace_id', $workspaceId)
            ->with(['contact', 'owner'])
            ->latest('last_activity_at')
            ->get()
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'title' => $lead->title,
                'status' => $lead->status,
                'source' => $lead->source,
                'valueAmount' => $lead->value_amount,
                'contact' => $lead->contact?->full_name,
                'contactId' => $lead->contact_id,
                'owner' => $lead->owner?->display_name ?: $lead->owner?->name,
                'lastActivityAt' => $lead->last_activity_at?->diffForHumans(),
            ]);

        return Inertia::render('admin/leads', [
            'columns' => ['NEW', 'QUALIFYING', 'OPEN', 'WON', 'LOST'],
            'leadsByStatus' => $leads->groupBy('status'),
        ]);
    }

    /** Contact detail: profile + their conversations + leads (flow hub). */
    public function contactShow(Request $request, Contact $contact): Response
    {
        $workspaceId = $this->workspaceId($request);
        abort_unless($contact->workspace_id === $workspaceId, 403);

        $contact->load(['owner', 'identities', 'notes.author']);

        return Inertia::render('admin/contact-show', [
            'notes' => $contact->notes
                ->sortByDesc(fn ($n) => [$n->pinned, $n->created_at])
                ->values()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'body' => $n->body,
                    'pinned' => (bool) $n->pinned,
                    'author' => $n->author?->display_name ?: $n->author?->name,
                    'createdAt' => $n->created_at?->diffForHumans(),
                ]),
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->full_name,
                'avatarUrl' => $contact->avatar_url,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'source' => $contact->source,
                'status' => $contact->status,
                'tags' => $contact->tags ?? [],
                'owner' => $contact->owner?->display_name ?: $contact->owner?->name,
                'lastInboundAt' => $contact->last_inbound_at?->diffForHumans(),
                // Whether a Zalo identity exists — the "refresh Zalo profile" button.
                'hasZalo' => $contact->identities->contains('provider', 'ZALO_PERSONAL'),
                'identities' => $contact->identities->map(fn ($i) => [
                    'provider' => $i->provider,
                    'displayName' => $i->display_name,
                    'providerUserId' => $i->provider_user_id,
                ]),
            ],
            'conversations' => $contact->conversations()
                ->with('channelAccount')
                ->latest('last_message_at')
                ->get()
                ->map(fn (Conversation $c) => [
                    'id' => $c->id,
                    'channel' => $c->channelAccount?->provider,
                    'status' => $c->status,
                    'lastMessageAt' => $c->last_message_at?->diffForHumans(),
                ]),
            'leads' => $contact->leads()
                ->latest()
                ->get()
                ->map(fn (Lead $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'status' => $l->status,
                    'source' => $l->source,
                    'valueAmount' => $l->value_amount,
                    'lastActivityAt' => $l->last_activity_at?->diffForHumans(),
                ]),
        ]);
    }

    public function channels(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        return Inertia::render('admin/channels', [
            'canManage' => in_array($request->user()->role, ['owner', 'admin'], true),
            'canDelete' => $request->user()->role === 'owner',
            'webhookBase' => url('/webhooks'),
            'channels' => ChannelAccount::query()->where('workspace_id', $workspaceId)->latest()->get()->map(fn (ChannelAccount $account) => [
                'id' => $account->id,
                'provider' => $account->provider,
                'name' => $account->name,
                'status' => $account->status,
                'webhookUrl' => $account->webhook_url,
                // The public callback URL to paste into the provider dashboard.
                'callbackUrl' => url('/webhooks/'.match ($account->provider) {
                    'FACEBOOK' => 'facebook',
                    'TELEGRAM' => 'telegram',
                    default => 'zalo',
                }.'/'.$account->id),
                'verifyToken' => $account->webhook_secret,
                'hasReceivedWebhook' => $account->last_webhook_at !== null,
                'lastWebhookAt' => $account->last_webhook_at?->diffForHumans(),
                'lastHealthCheckAt' => $account->last_health_check_at?->diffForHumans(),
                'lastErrorCode' => $account->last_error_code,
                'lastErrorMessage' => $account->last_error_message,
            ]),
        ]);
    }

    public function routing(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        return Inertia::render('admin/routing', [
            'queues' => RoutingQueue::query()
                ->where('workspace_id', $workspaceId)
                ->with(['members.user'])
                ->get()
                ->map(fn (RoutingQueue $queue) => [
                    'id' => $queue->id,
                    'name' => $queue->name,
                    'mode' => $queue->mode,
                    'status' => $queue->status,
                    'timeoutSeconds' => $queue->timeout_seconds,
                    'maxActivePerAgent' => $queue->max_active_per_agent,
                    'requiresOnline' => $queue->requires_online,
                    'members' => $queue->members->map(fn ($member) => [
                        'id' => $member->id,
                        'name' => $member->user?->display_name ?: $member->user?->name,
                        'status' => $member->status,
                        'lastAssignedAt' => $member->last_assigned_at?->diffForHumans(),
                    ]),
                ]),
        ]);
    }

    private function workspaceId(Request $request): string
    {
        if (! $request->user()->workspace_id) {
            $workspace = Workspace::query()->firstOrCreate(
                ['slug' => 'default'],
                ['name' => 'CRM Demo Workspace', 'status' => 'ACTIVE'],
            );

            $request->user()->forceFill([
                'workspace_id' => $workspace->id,
                'display_name' => $request->user()->display_name ?: $request->user()->name,
                'status' => 'ACTIVE',
            ])->save();
        }

        return (string) $request->user()->workspace_id;
    }
}
