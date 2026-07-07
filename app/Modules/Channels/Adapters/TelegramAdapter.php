<?php

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class TelegramAdapter implements ChannelAdapter
{
    public function normalizeInbound(ChannelAccount $account, array $payload): array
    {
        $message = Arr::get($payload, 'message', []);
        $from = Arr::get($message, 'from', []);
        $chat = Arr::get($message, 'chat', []);
        $eventId = (string) Arr::get($payload, 'update_id', hash('sha256', json_encode($payload)));
        $messageId = (string) Arr::get($message, 'message_id', $eventId);
        $text = Arr::get($message, 'text') ?: Arr::get($message, 'caption') ?: '[Nội dung không hỗ trợ]';
        $timestamp = Carbon::createFromTimestamp((int) Arr::get($message, 'date', time()));

        // Telegram group/supergroup: the conversation is the CHAT, not the sender.
        $chatType = (string) Arr::get($chat, 'type', 'private');
        $isGroup = in_array($chatType, ['group', 'supergroup'], true);
        $chatId = (string) Arr::get($chat, 'id', 'unknown');
        $senderId = (string) Arr::get($from, 'id', $chatId);
        $senderName = trim((string) (Arr::get($from, 'first_name', '').' '.Arr::get($from, 'last_name', '')))
            ?: Arr::get($from, 'username', 'Telegram user');

        return [
            'provider_event_id' => $eventId,
            'idempotency_key' => "telegram:{$account->id}:update:{$eventId}",
            'event_type' => 'message',
            'provider_message_id' => $messageId,
            'is_group' => $isGroup,
            'thread_id' => $chatId,
            'group_name' => $isGroup ? Arr::get($chat, 'title') : null,
            // provider_user_id/chat_id = the CHAT so replies go to chat.id
            // (both group and DM use chat.id as the send target for Telegram).
            'provider_user_id' => $isGroup ? $chatId : $senderId,
            'provider_chat_id' => $chatId,
            'sender_display_name' => $senderName,
            'sender_avatar_url' => null,
            'body_text' => $text,
            'message_type' => Arr::has($message, 'text') ? 'TEXT' : (Arr::has($message, 'photo') ? 'IMAGE' : 'UNSUPPORTED'),
            'provider_timestamp' => $timestamp,
            'raw_profile' => $from,
            'raw_payload' => $payload,
        ];
    }

    public function buildOutboundPayload(ChannelAccount $account, OutboxMessage $message): array
    {
        return [
            'chat_id' => $message->recipient_external_id ?: Arr::get($message->payload ?? [], 'chat_id'),
            'text' => (string) Arr::get($message->payload ?? [], 'text', ''),
            'image_url' => Arr::get($message->payload ?? [], 'image_url'),
        ];
    }

    public function sendOutbound(ChannelAccount $account, array $payload): array
    {
        $token = Arr::get($account->credentials ?? [], 'bot_token');
        if (! is_string($token) || $token === '') {
            return [
                'ok' => false,
                'error_code' => 'MISSING_BOT_TOKEN',
                'error_message' => 'Telegram bot token is missing.',
                'retryable' => false,
            ];
        }

        if (empty($payload['chat_id'])) {
            return [
                'ok' => false,
                'error_code' => 'MISSING_CHAT_ID',
                'error_message' => 'Telegram recipient chat_id is missing.',
                'retryable' => false,
            ];
        }

        // sendPhoto when an image URL is present (Telegram fetches it itself),
        // else plain sendMessage.
        $imageUrl = Arr::get($payload, 'image_url');
        if (is_string($imageUrl) && $imageUrl !== '') {
            $response = Http::asJson()->timeout(20)->post(
                "https://api.telegram.org/bot{$token}/sendPhoto",
                array_filter([
                    'chat_id' => $payload['chat_id'],
                    'photo' => $imageUrl,
                    'caption' => $payload['text'] ?? '',
                ]),
            );
        } else {
            $response = Http::asJson()->timeout(15)->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                ['chat_id' => $payload['chat_id'], 'text' => $payload['text']],
            );
        }
        $body = $response->json();

        if ($response->successful() && Arr::get($body, 'ok') === true) {
            return [
                'ok' => true,
                'response' => $body,
                'provider_message_id' => (string) Arr::get($body, 'result.message_id'),
            ];
        }

        $errorCode = (string) (Arr::get($body, 'error_code') ?: $response->status());

        return [
            'ok' => false,
            'response' => is_array($body) ? $body : null,
            'error_code' => $errorCode,
            'error_message' => (string) (Arr::get($body, 'description') ?: 'Telegram sendMessage request failed.'),
            'retryable' => $response->serverError() || $response->status() === 429,
        ];
    }
}
