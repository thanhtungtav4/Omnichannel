<?php

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Channels\Services\SdkRateLimiter;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Zalo personal (zca-js) adapter. Inbound events arrive from the Node sidecar
 * (sidecar/), outbound sends are proxied to the sidecar's /send endpoint. The
 * anti-block rate limiter gates every send (spec 10 Task 5c).
 */
class ZaloPersonalAdapter implements ChannelAdapter
{
    public function __construct(private readonly SdkRateLimiter $rateLimiter) {}

    public function normalizeInbound(ChannelAccount $account, array $payload): array
    {
        $message = Arr::get($payload, 'message', []);
        $sender = Arr::get($payload, 'sender', []);
        $eventName = (string) Arr::get($payload, 'event_name', 'user_send_text');
        $messageId = (string) (Arr::get($message, 'msg_id') ?: Arr::get($message, 'message_id') ?: hash('sha256', json_encode($payload)));
        $seq = Arr::get($message, 'seq'); // Zalo msgIdNum Snowflake -> thread sort key
        $timestamp = Carbon::createFromTimestampMs((int) Arr::get($payload, 'timestamp', now()->getTimestampMs()));

        $isGroup = Arr::get($payload, 'thread_type') === 'GROUP';
        $isSelf = (bool) Arr::get($payload, 'is_self', false);
        $threadId = (string) (Arr::get($payload, 'thread_id') ?: Arr::get($sender, 'id', 'unknown'));
        $senderId = (string) Arr::get($sender, 'id', 'unknown');

        return [
            'provider_event_id' => $messageId,
            'idempotency_key' => "zalo_personal:{$account->id}:{$eventName}:{$messageId}",
            'event_type' => $eventName,
            'provider_message_id' => $messageId,
            'provider_message_seq' => $seq !== null ? (int) $seq : null,
            // For a group, the conversation is keyed by the group thread; the
            // sender is the member who spoke. For DMs both equal the user id.
            'is_group' => $isGroup,
            'is_self' => $isSelf, // reply typed in the Zalo app -> OUTBOUND
            'thread_id' => $threadId,
            'group_name' => Arr::get($payload, 'group_name'),
            'provider_user_id' => $senderId,
            'provider_chat_id' => $threadId,
            'sender_display_name' => (string) Arr::get($sender, 'name', 'Zalo user'),
            'sender_avatar_url' => Arr::get($sender, 'avatar'),
            'body_text' => (string) (Arr::get($message, 'text') ?: Arr::get($message, 'content') ?: '[unsupported Zalo message]'),
            // Media type + url resolved by the sidecar (parseContent).
            'message_type' => (string) (Arr::get($message, 'message_type') ?: (Arr::get($message, 'text') ? 'TEXT' : 'UNSUPPORTED')),
            'attachment_url' => Arr::get($message, 'attachment_url'),
            'provider_timestamp' => $timestamp,
            'raw_profile' => $sender,
            'raw_payload' => $payload,
        ];
    }

    public function buildOutboundPayload(ChannelAccount $account, OutboxMessage $message): array
    {
        // is_group comes from the conversation (the source of truth), NOT the
        // payload snapshot — the flag may have been backfilled after the reply
        // was queued. recipient lives on the outbox column.
        $conversation = $message->conversation;
        $isGroup = (bool) ($conversation?->is_group ?? Arr::get($message->payload ?? [], 'is_group', false));

        // If it is a group, always target the group thread, not a member.
        $recipient = $isGroup && $conversation?->provider_thread_id
            ? $conversation->provider_thread_id
            : $message->recipient_external_id;

        return array_merge($message->payload ?? [], [
            'recipient_external_id' => $recipient,
            'message_id' => $message->message_id,
            'is_group' => $isGroup,
        ]);
    }

    public function sendOutbound(ChannelAccount $account, array $payload): array
    {
        $gate = $this->rateLimiter->check($account, 'MESSAGE');
        if (! $gate['allowed']) {
            return [
                'ok' => false,
                'error_code' => 'RATE_LIMITED_'.($gate['reason'] ?? 'UNKNOWN'),
                'error_message' => 'Anti-block limit reached for this Zalo nick.',
                'retryable' => true, // can retry later when the window/day clears
            ];
        }

        $base = rtrim((string) config('services.zalo_sidecar.url', env('ZALO_SIDECAR_URL', 'http://127.0.0.1:4501')), '/');
        $token = (string) config('services.zalo_sidecar.token', env('ZALO_SIDECAR_TOKEN', ''));

        try {
            $res = Http::withHeaders(['x-sidecar-token' => $token])
                ->timeout(15)
                ->post("{$base}/accounts/{$account->id}/send", [
                    'recipientUid' => Arr::get($payload, 'recipient_external_id') ?? Arr::get($payload, 'recipientUid'),
                    'text' => Arr::get($payload, 'text') ?? Arr::get($payload, 'body_text'),
                    'messageId' => Arr::get($payload, 'message_id'),
                    'isGroup' => (bool) Arr::get($payload, 'is_group', false),
                    'attachmentPath' => Arr::get($payload, 'image_path'), // local file for zca-js
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error_code' => 'SIDECAR_UNREACHABLE', 'error_message' => $e->getMessage(), 'retryable' => true];
        }

        if (! $res->successful() || $res->json('ok') !== true) {
            return [
                'ok' => false,
                'error_code' => (string) ($res->json('error') ?? 'SIDECAR_SEND_FAILED'),
                'error_message' => (string) ($res->json('message') ?? 'Sidecar rejected the send.'),
                'retryable' => true,
            ];
        }

        $this->rateLimiter->record($account, 'MESSAGE');

        return [
            'ok' => true,
            'response' => $res->json(),
            'provider_message_id' => $res->json('providerMessageId'),
        ];
    }
}
