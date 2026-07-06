<?php

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use Illuminate\Support\Arr;

class MockAdapter implements ChannelAdapter
{
    public function normalizeInbound(ChannelAccount $account, array $payload): array
    {
        $eventId = (string) Arr::get($payload, 'event_id', hash('sha256', json_encode($payload)));
        $senderId = (string) Arr::get($payload, 'sender_id', 'mock-user');

        return [
            'provider_event_id' => $eventId,
            'idempotency_key' => "mock:{$account->id}:{$eventId}",
            'event_type' => 'mock.message',
            'provider_message_id' => (string) Arr::get($payload, 'message_id', $eventId),
            'provider_user_id' => $senderId,
            'provider_chat_id' => (string) Arr::get($payload, 'chat_id', $senderId),
            'sender_display_name' => (string) Arr::get($payload, 'sender_name', 'Demo Customer'),
            'sender_avatar_url' => null,
            'body_text' => (string) Arr::get($payload, 'text', 'Xin chào, tôi cần tư vấn.'),
            'message_type' => 'TEXT',
            'provider_timestamp' => now(),
            'raw_profile' => ['id' => $senderId, 'name' => Arr::get($payload, 'sender_name', 'Demo Customer')],
            'raw_payload' => $payload,
        ];
    }

    public function buildOutboundPayload(ChannelAccount $account, OutboxMessage $message): array
    {
        return $message->payload ?? [];
    }

    public function sendOutbound(ChannelAccount $account, array $payload): array
    {
        $messageId = (string) str()->uuid();

        return [
            'ok' => true,
            'response' => ['ok' => true, 'result' => ['message_id' => $messageId]],
            'provider_message_id' => $messageId,
        ];
    }
}
