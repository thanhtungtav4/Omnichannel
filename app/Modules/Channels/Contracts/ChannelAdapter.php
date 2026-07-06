<?php

namespace App\Modules\Channels\Contracts;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;

interface ChannelAdapter
{
    /**
     * Normalize one provider inbound payload into the internal message contract.
     *
     * @return array{
     *     provider_event_id: string,
     *     idempotency_key: string,
     *     event_type: string,
     *     provider_message_id: string,
     *     provider_user_id: string,
     *     provider_chat_id: string,
     *     sender_display_name: string,
     *     sender_avatar_url: string|null,
     *     body_text: string,
     *     message_type: string,
     *     provider_timestamp: \Illuminate\Support\Carbon,
     *     raw_profile: array<string, mixed>,
     *     raw_payload: array<string, mixed>
     * }
     */
    public function normalizeInbound(ChannelAccount $account, array $payload): array;

    /**
     * @return array<string, mixed>
     */
    public function buildOutboundPayload(ChannelAccount $account, OutboxMessage $message): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, response?: array<string, mixed>|null, provider_message_id?: string|null, error_code?: string|null, error_message?: string|null, retryable?: bool}
     */
    public function sendOutbound(ChannelAccount $account, array $payload): array;
}
