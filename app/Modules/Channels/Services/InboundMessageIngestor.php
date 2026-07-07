<?php

namespace App\Modules\Channels\Services;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\WebhookEvent;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\Stage;
use App\Modules\Crm\Models\TimelineActivity;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Models\MessageAttachment;
use App\Modules\Routing\Services\AssignmentService;
use App\Modules\Routing\Services\PresenceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ponytail: known boundary debt. This service reaches across Channels -> Crm
 * (Contact/Lead/Identity/Timeline) + Inbox (Conversation/Message) + Routing
 * inside one DB transaction. Kept whole because the atomic ordering
 * (contact -> lead -> conversation -> message) is correctness-critical and the
 * transaction guarantees no orphans. Split into events
 * (Channels emits InboundMessageReceived; Crm/Inbox/Routing listen) only when a
 * real need appears — e.g. a module must react to inbound without editing this
 * file, or ingest fan-out grows. Until then, cross-module imports here are a
 * deliberate, contained exception, not the pattern to copy.
 */
class InboundMessageIngestor
{
    public function __construct(
        private readonly AssignmentService $assignmentService,
        private readonly ChannelAdapterRegistry $adapterRegistry,
        private readonly PresenceService $presence,
    ) {
    }

    /**
     * @return array{duplicate: bool, webhook_event: WebhookEvent, conversation?: Conversation, message?: Message, contact?: Contact, lead?: Lead}
     */
    public function ingest(ChannelAccount $account, array $payload, array $headers = []): array
    {
        $normalized = $this->adapterRegistry->for($account)->normalizeInbound($account, $payload);

        // Dedup: check-then-create. On Postgres, a unique violation aborts the
        // whole transaction, so we must NOT rely on catching it inside one.
        // A rare create-race is still caught by the unique index (savepoint).
        $existing = WebhookEvent::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('channel_account_id', $account->id)
            ->where('idempotency_key', $normalized['idempotency_key'])
            ->first();

        if ($existing) {
            return ['duplicate' => true, 'webhook_event' => $existing];
        }

        try {
            $webhookEvent = DB::transaction(fn () => WebhookEvent::create([
                'workspace_id' => $account->workspace_id,
                'channel_account_id' => $account->id,
                'provider' => $account->provider,
                'provider_event_id' => $normalized['provider_event_id'],
                'idempotency_key' => $normalized['idempotency_key'],
                'event_type' => $normalized['event_type'],
                'headers' => $headers,
                'payload' => $payload,
                'status' => 'PROCESSING',
            ]));
        } catch (UniqueConstraintViolationException) {
            // Lost the race: another request created it between our check and insert.
            $event = WebhookEvent::query()
                ->where('workspace_id', $account->workspace_id)
                ->where('channel_account_id', $account->id)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->firstOrFail();

            return ['duplicate' => true, 'webhook_event' => $event];
        }

        $result = DB::transaction(function () use ($account, $webhookEvent, $normalized) {
            $isGroup = ($normalized['is_group'] ?? false) === true;
            $isSelf = ($normalized['is_self'] ?? false) === true;

            // Conversation identity key:
            // - group: the group thread (all members share one conversation)
            // - self DM (reply typed in Zalo): the recipient = thread_id, NOT us
            // - normal DM: the customer's user id
            $identityKey = ($isGroup || $isSelf)
                ? ($normalized['thread_id'] ?? $normalized['provider_user_id'])
                : $normalized['provider_user_id'];

            // Display name for the conversation's contact:
            // - group: "[Nhóm] name"
            // - self DM: the sender name is OURS (the nick), not the customer's,
            //   so use a placeholder keyed by the customer id until they reply.
            // - normal inbound: the customer's own name.
            $displayName = match (true) {
                $isGroup => '[Nhóm] '.($normalized['group_name'] ?: $normalized['thread_id']),
                $isSelf => 'Khách '.$normalized['thread_id'],
                default => $normalized['sender_display_name'],
            };

            $identity = ExternalIdentity::query()
                ->where('workspace_id', $account->workspace_id)
                ->where('provider', $account->provider)
                ->where('provider_account_id', $account->id)
                ->where('provider_user_id', $identityKey)
                ->first();

            $contact = $identity?->contact;

            if (! $contact) {
                $contact = Contact::create([
                    'workspace_id' => $account->workspace_id,
                    'full_name' => $displayName,
                    'avatar_url' => $isGroup ? null : $normalized['sender_avatar_url'],
                    'source' => $account->provider,
                    'last_inbound_at' => $normalized['provider_timestamp'],
                ]);

                $identity = ExternalIdentity::create([
                    'workspace_id' => $account->workspace_id,
                    'contact_id' => $contact->id,
                    'provider' => $account->provider,
                    'provider_account_id' => $account->id,
                    'provider_user_id' => $identityKey,
                    'provider_chat_id' => $normalized['provider_chat_id'],
                    'display_name' => $displayName,
                    'avatar_url' => $isGroup ? null : $normalized['sender_avatar_url'],
                    'raw_profile' => $normalized['raw_profile'],
                    'last_seen_at' => $normalized['provider_timestamp'],
                ]);
            }

            // Only refresh the identity's name/avatar from a REAL customer message
            // (normal inbound). A self message carries our nick's name, and a group
            // message carries a member's name — neither should overwrite the
            // conversation contact's identity.
            $identityUpdate = [
                'provider_chat_id' => $normalized['provider_chat_id'],
                'raw_profile' => $normalized['raw_profile'],
                'last_seen_at' => $normalized['provider_timestamp'],
            ];
            if (! $isSelf && ! $isGroup) {
                $identityUpdate['display_name'] = $normalized['sender_display_name'];
                $identityUpdate['avatar_url'] = $normalized['sender_avatar_url'];
            }
            $identity->forceFill($identityUpdate)->save();

            $contactUpdate = [
                'last_inbound_at' => $normalized['provider_timestamp'],
                'source' => $contact->source ?: $account->provider,
            ];
            // For a real (non-self, non-group) DM, the sender name IS the customer.
            // Sync the contact name to it — this both fills placeholders and fixes
            // contacts whose name was wrongly set to our own nick when the thread
            // started with a self message.
            if (! $isSelf && ! $isGroup && ! empty($normalized['sender_display_name'])) {
                $contactUpdate['full_name'] = $normalized['sender_display_name'];
            }
            // Upgrade a group name once the sidecar resolves it (was "[Nhóm] <id>").
            if ($isGroup && ! empty($normalized['group_name'])) {
                $newGroupName = '[Nhóm] '.$normalized['group_name'];
                if ($contact->full_name !== $newGroupName) {
                    $contactUpdate['full_name'] = $newGroupName;
                }
            }
            $contact->forceFill($contactUpdate)->save();

            // Groups are not sales leads — skip lead creation for them.
            $lead = $isGroup ? null : Lead::query()
                ->where('workspace_id', $account->workspace_id)
                ->where('contact_id', $contact->id)
                ->whereIn('status', ['NEW', 'QUALIFYING', 'OPEN'])
                ->latest('updated_at')
                ->first();

            if (! $lead && ! $isGroup) {
                $pipeline = Pipeline::query()
                    ->where('workspace_id', $account->workspace_id)
                    ->where('type', 'LEAD')
                    ->where('is_default', true)
                    ->first();
                $stage = Stage::query()
                    ->where('workspace_id', $account->workspace_id)
                    ->where('pipeline_id', $pipeline?->id)
                    ->orderBy('sort_order')
                    ->first();

                $lead = Lead::create([
                    'workspace_id' => $account->workspace_id,
                    'contact_id' => $contact->id,
                    'owner_id' => $contact->owner_id,
                    'pipeline_id' => $pipeline?->id,
                    'stage_id' => $stage?->id,
                    'title' => 'Lead from '.$account->provider.' - '.$contact->full_name,
                    'status' => 'NEW',
                    'source' => $account->provider,
                    'last_activity_at' => $normalized['provider_timestamp'],
                ]);
            }

            // Reuse the most recent thread for this contact, INCLUDING a closed
            // one — a customer messaging again reopens the same conversation
            // instead of spawning a fresh ticket every time.
            $conversation = Conversation::query()
                ->where('workspace_id', $account->workspace_id)
                ->where('channel_account_id', $account->id)
                ->where('contact_id', $contact->id)
                ->latest('last_message_at')
                ->first();

            // Reopening a closed thread: restore the owner's active-conversation
            // count that close() decremented, so the workload counter stays true.
            if ($conversation && $conversation->status === 'CLOSED' && ! $isSelf) {
                $conversation->forceFill(['closed_at' => null])->save();
                if ($conversation->owner_id) {
                    $this->presence->conversationAssigned($conversation->workspace_id, $conversation->owner_id);
                }
            }

            if (! $conversation) {
                $conversation = Conversation::create([
                    'workspace_id' => $account->workspace_id,
                    'channel_account_id' => $account->id,
                    'contact_id' => $contact->id,
                    'owner_id' => $contact->owner_id,
                    'status' => 'OPEN',
                    'subject' => $contact->full_name,
                    'is_group' => $isGroup,
                    'provider_thread_id' => $isGroup ? ($normalized['thread_id'] ?? null) : null,
                    'last_message_at' => $normalized['provider_timestamp'],
                    'last_customer_message_at' => $normalized['provider_timestamp'],
                    'first_response_due_at' => Carbon::parse($normalized['provider_timestamp'])->addMinutes(5),
                    'next_response_due_at' => Carbon::parse($normalized['provider_timestamp'])->addMinutes(10),
                ]);
            }

            // Backfill group flag on a pre-existing conversation (created before
            // this field existed, or before the group fix). Otherwise a group
            // reply would fall back to a single member -> wrong recipient.
            if ($isGroup && (! $conversation->is_group || ! $conversation->provider_thread_id)) {
                $conversation->forceFill([
                    'is_group' => true,
                    'provider_thread_id' => $normalized['thread_id'] ?? $conversation->provider_thread_id,
                ])->save();
            }

            // In a group, prefix the sender name so you can tell who said what
            // (inbound only — our own outbound doesn't need a name prefix).
            $bodyText = ($isGroup && ! $isSelf)
                ? ($normalized['sender_display_name'].': '.$normalized['body_text'])
                : $normalized['body_text'];

            // A self message = a reply typed in the Zalo app -> store as OUTBOUND.
            $message = Message::create([
                'workspace_id' => $account->workspace_id,
                'conversation_id' => $conversation->id,
                'channel_account_id' => $account->id,
                'provider_message_id' => $normalized['provider_message_id'],
                'provider_message_seq' => $normalized['provider_message_seq'] ?? null,
                'direction' => $isSelf ? 'OUTBOUND' : 'INBOUND',
                'sender_type' => $isSelf ? 'AGENT' : 'CUSTOMER',
                'sender_id' => $normalized['provider_user_id'],
                'body_text' => $bodyText,
                'message_type' => $normalized['message_type'],
                'status' => $isSelf ? 'SENT' : 'RECEIVED',
                'sent_at' => $isSelf ? $normalized['provider_timestamp'] : null,
                'raw_payload' => $normalized['raw_payload'],
                'created_at' => $normalized['provider_timestamp'],
                'updated_at' => $normalized['provider_timestamp'],
            ]);

            // Persist media URL (image/video/file). ponytail: store the provider
            // CDN URL directly; mirror to S3 later if the URLs expire.
            if (! empty($normalized['attachment_url'])) {
                MessageAttachment::create([
                    'workspace_id' => $account->workspace_id,
                    'message_id' => $message->id,
                    'metadata' => ['url' => $normalized['attachment_url'], 'type' => $normalized['message_type']],
                ]);
            }

            $conversation->forceFill([
                // Self reply -> waiting for customer; customer message -> waiting for agent.
                'status' => $isSelf ? 'WAITING_CUSTOMER' : 'WAITING_AGENT',
                'last_message_id' => $message->id,
                'last_message_at' => $normalized['provider_timestamp'],
                'last_customer_message_at' => $isSelf ? $conversation->last_customer_message_at : $normalized['provider_timestamp'],
                'last_agent_message_at' => $isSelf ? $normalized['provider_timestamp'] : $conversation->last_agent_message_at,
                'next_response_due_at' => Carbon::parse($normalized['provider_timestamp'])->addMinutes(10),
            ])->save();

            $account->forceFill([
                'last_webhook_at' => now(),
                'status' => 'ACTIVE',
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            TimelineActivity::create([
                'workspace_id' => $account->workspace_id,
                'subject_type' => 'crm.contact',
                'subject_id' => $contact->id,
                'module' => 'inbox',
                'type' => 'INBOUND_MESSAGE',
                'title' => 'Inbound '.$account->provider.' message',
                'body' => $normalized['body_text'],
                'metadata' => ['conversation_id' => $conversation->id, 'message_id' => $message->id],
                'occurred_at' => $normalized['provider_timestamp'],
            ]);

            $webhookEvent->forceFill([
                'status' => 'PROCESSED',
                'processed_at' => now(),
            ])->save();

            return compact('conversation', 'message', 'contact', 'lead');
        });

        // Assign runs AFTER the ingest transaction commits, so a failure here
        // must not lose the message. Swallow + log: the conversation is left
        // WAITING_AGENT and the minutely SLA sweep re-attempts assignment.
        try {
            $this->assignmentService->assign($result['conversation'], $result['contact']->owner, 'AUTO_STICKY_OWNER');
        } catch (\Throwable $e) {
            Log::warning('Auto-assign failed after ingest; left for SLA sweep retry.', [
                'conversation_id' => $result['conversation']->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['duplicate' => false, 'webhook_event' => $webhookEvent] + $result;
    }
}
