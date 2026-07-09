<?php

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class ZaloOaAdapter implements ChannelAdapter
{
    public function normalizeInbound(ChannelAccount $account, array $payload): array
    {
        $provider = strtoupper($account->provider);
        $message = Arr::get($payload, 'message', []);
        $sender = Arr::get($payload, 'sender', []);
        $eventName = (string) Arr::get($payload, 'event_name', 'user_send_text');
        $messageId = (string) (Arr::get($message, 'msg_id') ?: Arr::get($message, 'message_id') ?: hash('sha256', json_encode($payload)));
        $timestamp = Carbon::createFromTimestampMs((int) Arr::get($payload, 'timestamp', now()->getTimestampMs()));

        return [
            'provider_event_id' => $messageId,
            'idempotency_key' => strtolower($provider).":{$account->id}:{$eventName}:{$messageId}",
            'event_type' => $eventName,
            'provider_message_id' => $messageId,
            'provider_user_id' => (string) Arr::get($sender, 'id', Arr::get($payload, 'user_id', 'unknown')),
            'provider_chat_id' => (string) Arr::get($sender, 'id', Arr::get($payload, 'user_id', 'unknown')),
            'sender_display_name' => (string) Arr::get($sender, 'name', 'Zalo user'),
            'sender_avatar_url' => Arr::get($sender, 'avatar'),
            'body_text' => (string) (Arr::get($message, 'text') ?: Arr::get($message, 'content') ?: '[unsupported Zalo message]'),
            'message_type' => Arr::get($message, 'text') ? 'TEXT' : 'UNSUPPORTED',
            'provider_timestamp' => $timestamp,
            'raw_profile' => $sender,
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

    /**
     * Send a text message via the Zalo OA message API (spec 05).
     * Endpoint: https://openapi.zalo.me/v3.0/oa/message/cs
     */
    public function sendOutbound(ChannelAccount $account, array $payload): array
    {
        $accessToken = Arr::get($account->credentials ?? [], 'access_token');
        if (! $accessToken) {
            return ['ok' => false, 'error_code' => 'NO_ACCESS_TOKEN', 'error_message' => 'Zalo OA access token missing.', 'retryable' => false];
        }

        $recipient = Arr::get($payload, 'recipient_external_id') ?? Arr::get($payload, 'recipientUid');
        $text = Arr::get($payload, 'text') ?? Arr::get($payload, 'body_text');

        try {
            $res = Http::withHeaders(['access_token' => $accessToken])
                ->timeout(15)
                ->post('https://openapi.zalo.me/v3.0/oa/message/cs', [
                    'recipient' => ['user_id' => $recipient],
                    'message' => ['text' => $text],
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error_code' => 'ZALO_OA_UNREACHABLE', 'error_message' => $e->getMessage(), 'retryable' => true];
        }

        $body = $res->json();
        $errorCode = (int) Arr::get($body, 'error', 0);

        // Zalo returns error=0 on success. Token expired/invalid -> mark degraded + retryable.
        if ($errorCode !== 0 || ! $res->successful()) {
            $tokenError = in_array($errorCode, [-124, -216, -240], true); // access token invalid/expired family
            if ($tokenError) {
                $account->forceFill(['status' => 'DEGRADED', 'last_error_code' => (string) $errorCode, 'last_error_message' => (string) Arr::get($body, 'message', 'Token invalid.')])->save();
            }

            return [
                'ok' => false,
                'error_code' => (string) $errorCode,
                'error_message' => (string) Arr::get($body, 'message', 'Zalo OA send failed.'),
                'retryable' => $tokenError, // retry only after token refresh
            ];
        }

        return [
            'ok' => true,
            'response' => $body,
            'provider_message_id' => (string) (Arr::get($body, 'data.message_id') ?: Arr::get($body, 'data.msg_id')),
        ];
    }

    /**
     * Send a template message via the Zalo OA Notification Service API.
     * Used by the Mini App re-engagement path (spec 15 § C4): CRM → Mini
     * App for lead-status-changed, contact-archived, and other transactional
     * notifications tied to a domain event.
     *
     * Template IDs live in workspace_settings.miniapp.templates (mapping
     * `template_code → { oa_template_id, default_params }`); this method
     * takes the resolved OA template id directly so the notifier owns the
     * lookup. The caller is responsible for tracking which template codes
     * the OA has approved.
     *
     * Endpoint: https://openapi.zalo.me/v3.0/oa/message/template
     *
     * @param  array<string, mixed>  $templateData  template-specific data (per Zalo OA template schema)
     * @return array{ok: bool, error_code?: string, error_message?: string, retryable?: bool, response?: array, provider_message_id?: string}
     */
    public function sendTemplateMessage(ChannelAccount $account, string $oaUserId, string $oaTemplateId, array $templateData = []): array
    {
        $accessToken = Arr::get($account->credentials ?? [], 'access_token');
        if (! $accessToken) {
            return ['ok' => false, 'error_code' => 'NO_ACCESS_TOKEN', 'error_message' => 'Zalo OA access token missing.', 'retryable' => false];
        }

        try {
            $res = Http::withHeaders(['access_token' => $accessToken])
                ->timeout(15)
                ->post('https://openapi.zalo.me/v3.0/oa/message/template', [
                    'recipient' => ['user_id' => $oaUserId],
                    'template_id' => $oaTemplateId,
                    'template_data' => $templateData,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error_code' => 'ZALO_OA_UNREACHABLE', 'error_message' => $e->getMessage(), 'retryable' => true];
        }

        $body = $res->json();
        $errorCode = (int) Arr::get($body, 'error', 0);

        if ($errorCode !== 0 || ! $res->successful()) {
            $tokenError = in_array($errorCode, [-124, -216, -240], true);
            if ($tokenError) {
                $account->forceFill(['status' => 'DEGRADED', 'last_error_code' => (string) $errorCode, 'last_error_message' => (string) Arr::get($body, 'message', 'Token invalid.')])->save();
            }

            return [
                'ok' => false,
                'error_code' => (string) $errorCode,
                'error_message' => (string) Arr::get($body, 'message', 'Zalo OA template send failed.'),
                // Template sends are mostly synchronous Zalo errors (template
                // not approved, user blocked OA, etc.) — most are not
                // retryable. Token errors are.
                'retryable' => $tokenError,
            ];
        }

        return [
            'ok' => true,
            'response' => $body,
            'provider_message_id' => (string) (Arr::get($body, 'data.message_id') ?: Arr::get($body, 'data.msg_id')),
        ];
    }
}
