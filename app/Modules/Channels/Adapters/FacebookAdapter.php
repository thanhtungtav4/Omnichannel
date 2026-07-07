<?php

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Facebook Messenger adapter (spec 10 Task 7).
 * Webhook: Graph messaging events. Send: Graph /me/messages.
 * A Messenger webhook POST wraps events as entry[].messaging[]; the controller
 * unwraps one messaging event per normalize call.
 */
class FacebookAdapter implements ChannelAdapter
{
    public function normalizeInbound(ChannelAccount $account, array $payload): array
    {
        // payload = a single "messaging" event (unwrapped by the controller).
        $senderId = (string) Arr::get($payload, 'sender.id', 'unknown');
        $messageId = (string) (Arr::get($payload, 'message.mid') ?: hash('sha256', json_encode($payload)));
        $text = (string) Arr::get($payload, 'message.text', '');
        $tsMs = (int) Arr::get($payload, 'timestamp', now()->getTimestampMs());

        return [
            'provider_event_id' => $messageId,
            'idempotency_key' => "facebook:{$account->id}:mid:{$messageId}",
            'event_type' => 'message',
            'provider_message_id' => $messageId,
            'provider_user_id' => $senderId,
            'provider_chat_id' => $senderId,
            'sender_display_name' => (string) Arr::get($payload, 'sender.name', 'Facebook user'),
            'sender_avatar_url' => null,
            'body_text' => $text !== '' ? $text : '[unsupported Facebook message]',
            'message_type' => $text !== '' ? 'TEXT' : 'UNSUPPORTED',
            'provider_timestamp' => Carbon::createFromTimestampMs($tsMs),
            'raw_profile' => Arr::get($payload, 'sender', []),
            'raw_payload' => $payload,
        ];
    }

    public function buildOutboundPayload(ChannelAccount $account, OutboxMessage $message): array
    {
        return array_merge($message->payload ?? [], [
            'recipient_external_id' => $message->recipient_external_id,
            'text' => (string) Arr::get($message->payload ?? [], 'text', ''),
        ]);
    }

    public function sendOutbound(ChannelAccount $account, array $payload): array
    {
        $pageToken = Arr::get($account->credentials ?? [], 'page_access_token');
        if (! $pageToken) {
            return ['ok' => false, 'error_code' => 'NO_PAGE_TOKEN', 'error_message' => 'Facebook page access token missing.', 'retryable' => false];
        }

        $recipient = Arr::get($payload, 'recipient_external_id') ?? Arr::get($payload, 'recipientId');
        $text = Arr::get($payload, 'text') ?? Arr::get($payload, 'body_text');

        try {
            $res = Http::timeout(15)->post('https://graph.facebook.com/v21.0/me/messages', [
                'recipient' => ['id' => $recipient],
                'message' => ['text' => $text],
                'messaging_type' => 'RESPONSE',
                'access_token' => $pageToken,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error_code' => 'FACEBOOK_UNREACHABLE', 'error_message' => $e->getMessage(), 'retryable' => true];
        }

        $body = $res->json();
        if (! $res->successful() || Arr::has($body, 'error')) {
            return [
                'ok' => false,
                'error_code' => (string) Arr::get($body, 'error.code', $res->status()),
                'error_message' => (string) Arr::get($body, 'error.message', 'Facebook send failed.'),
                'retryable' => $res->serverError(),
            ];
        }

        return [
            'ok' => true,
            'response' => $body,
            'provider_message_id' => (string) Arr::get($body, 'message_id'),
        ];
    }
}
