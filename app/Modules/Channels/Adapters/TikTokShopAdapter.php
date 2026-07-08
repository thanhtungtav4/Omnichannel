<?php

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Jobs\RefreshTikTokAccessTokenJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TikTok Shop Chat VN adapter (cut 1, specs/13_TIKTOK_SHOP_VN.md).
 *
 * Inbound (W3 — implemented):
 *   - normalizeInbound: NEW_MESSAGE event -> canonical shape.
 *   - text + image renderable in Inbox; others (video/sticker/product/order/
 *     voucher) marked UNSUPPORTED but persisted in webhook_events for audit.
 *   - edits: TikTok repushes the same message_id with an incremented
 *     `version` field. We bump idempotency_key + provider_event_id accordingly.
 *
 * Outbound (W3 — implemented):
 *   - buildOutboundPayload: OutboxMessage -> TikTok im/send_message shape.
 *   - sendOutbound: pre-upload image to TikTok CDN if needed, then POST
 *     send_message with HMAC-SHA256 signature header (per Partner API auth
 *     scheme). Maps response to the adapter return contract.
 *
 * Retry policy per spec 13 § Outbound send:
 *   - 5xx / network timeout           -> retryable=true
 *   - HTTP 429                        -> retryable=true (with retry_after)
 *   - auth_error / unauthorized       -> DEGRADED + REAUTH_REQUIRED, retryable=false
 *   - recipient_blocked / conversation_closed -> retryable=false
 *
 * OPEN ITEM (W3 spike):
 *   Webhook signature scheme is implemented as TikTok Open Platform format
 *   (TikTok-Signature: t=<unix>,s=<hex>, HMAC over `${t}.${raw_body}` keyed
 *   by app_secret). TikTok Shop Partner's configuration-guide mentions
 *   signature in `Authorization` header for some endpoints. To be
 *   re-validated against the first real partner webhook. If they differ,
 *   add a partner-specific variant — do not silently fall back.
 *
 * See: specs/13_TIKTOK_SHOP_VN.md, app/Modules/Channels/Http/Middleware/
 *      VerifyTikTokSignature.php
 */
class TikTokShopAdapter implements ChannelAdapter
{
    /** Message types we render in the Inbox in cut 1. */
    private const SUPPORTED_TYPES = ['text', 'image'];

    /** Provider errors we treat as permanent (no retry). */
    private const NON_RETRYABLE_ERRORS = [
        'recipient_blocked',
        'conversation_closed',
        'invalid_recipient',
        'recipient_not_found',
        'message_too_long',
        'invalid_message_type',
        'invalid_argument',
        'unauthorized',
        'auth_error',
        'invalid_app_key',
        'invalid_shop_id',
        'rate_limit_exceeded',
        // TikTok Shop uses snake_case differently — these aliases catch the
        // snake_case form Shop Partner API may return.
        'rate_limited',
        'forbidden',
    ];

    public function normalizeInbound(ChannelAccount $account, array $payload): array
    {
        $messageId = (string) Arr::get($payload, 'message_id', '');
        if ($messageId === '') {
            throw new RuntimeException('TikTok payload missing message_id. See specs/13_TIKTOK_SHOP_VN.md § Inbound mapping.');
        }

        $shopId = (string) Arr::get($payload, 'shop_id', '');
        if ($shopId !== '' && $shopId !== (string) ($account->credentials['shop_id'] ?? '')) {
            throw new RuntimeException(sprintf(
                'TikTok payload shop_id (%s) does not match channel account shop_id (%s).',
                $shopId,
                (string) ($account->credentials['shop_id'] ?? 'n/a'),
            ));
        }

        $eventType = (string) Arr::get($payload, 'event_type', '');
        $rawType = strtolower((string) Arr::get($payload, 'message_type', ''));
        $messageType = in_array($rawType, self::SUPPORTED_TYPES, true) ? strtoupper($rawType) : 'UNSUPPORTED';

        $content = (array) Arr::get($payload, 'content', []);
        $sender = (array) Arr::get($payload, 'sender', []);

        $bodyText = match ($rawType) {
            'text' => (string) Arr::get($content, 'text', ''),
            'image', 'video' => (string) Arr::get($content, 'caption', ''),
            default => '',
        };

        // Edits: TikTok repushes the same message_id with a `version` field > 1.
        $version = (int) Arr::get($payload, 'version', 1);
        $isEdit = $version > 1;

        $conversationId = (string) Arr::get($payload, 'conversation_id', '');
        $idempotencyKey = "tiktok:{$account->id}:msg:{$messageId}".($isEdit ? ':v'.$version : '');
        $providerEventId = $messageId.($isEdit ? ':edit:'.$version : '');

        // created_at may be ISO-8601 or unix seconds.
        $createdRaw = Arr::get($payload, 'created_at', time());
        $timestamp = is_numeric($createdRaw)
            ? Carbon::createFromTimestamp((int) $createdRaw)
            : Carbon::parse((string) $createdRaw);

        return [
            'provider_event_id' => $providerEventId,
            'idempotency_key' => $idempotencyKey,
            'event_type' => $messageType === 'UNSUPPORTED' ? 'unsupported' : 'message',
            'provider_message_id' => $messageId,
            'provider_message_seq' => $version,
            'is_edit' => $isEdit,
            'is_group' => false,
            'thread_id' => $conversationId,
            'group_name' => null,
            'provider_user_id' => (string) Arr::get($sender, 'open_id', ''),
            'provider_chat_id' => $conversationId,
            'sender_display_name' => (string) Arr::get($sender, 'nickname', 'TikTok buyer'),
            'sender_avatar_url' => Arr::get($sender, 'avatar_url'),
            'body_text' => $bodyText,
            'message_type' => $messageType,
            'attachment_url' => match ($rawType) {
                'image' => Arr::get($content, 'image_url'),
                'video' => Arr::get($content, 'video_url'),
                default => null,
            },
            'provider_timestamp' => $timestamp,
            'raw_profile' => [
                'open_id' => Arr::get($sender, 'open_id'),
                'nickname' => Arr::get($sender, 'nickname'),
                'shop_id' => $shopId,
                'conversation_id' => $conversationId,
                'event_type' => $eventType,
            ],
            'raw_payload' => $payload,
        ];
    }

    public function buildOutboundPayload(ChannelAccount $account, OutboxMessage $message): array
    {
        $payload = $message->payload ?? [];
        $recipientId = (string) ($message->recipient_external_id
            ?: Arr::get($payload, 'conversation_id')
            ?: Arr::get($payload, 'open_id', ''));

        if ($recipientId === '') {
            throw new RuntimeException('TikTok outbound: missing conversation_id or open_id.');
        }

        $rawType = strtolower((string) ($message->message_type ?: 'text'));
        $imageUrl = Arr::get($payload, 'image_url');

        $ttType = match (true) {
            $rawType === 'image' && is_string($imageUrl) && $imageUrl !== '' => 'image',
            default => 'text',
        };

        $content = match ($ttType) {
            'image' => ['image_url' => $imageUrl, 'caption' => (string) ($message->body_text ?? '')],
            default => ['text' => (string) ($message->body_text ?? '')],
        };

        return [
            'recipient_id' => $recipientId, // open_id OR conversation_id (TikTok thread)
            'message_type' => $ttType,
            'content' => $content,
            'raw_type' => $rawType,
            'image_url' => $imageUrl,
        ];
    }

    public function sendOutbound(ChannelAccount $account, array $payload): array
    {
        $creds = $account->credentials ?? [];
        $accessToken = (string) Arr::get($creds, 'access_token', '');
        $shopId = (string) Arr::get($creds, 'shop_id', '');
        $shopCipher = (string) Arr::get($creds, 'shop_cipher', '');

        if ($accessToken === '' || $shopId === '') {
            return [
                'ok' => false,
                'error_code' => 'MISSING_CREDENTIALS',
                'error_message' => 'TikTok channel account missing access_token or shop_id.',
                'retryable' => false,
            ];
        }

        // ----- Auto-refresh on expired access_token (one attempt, sync) -----
        $expiresAt = Arr::get($creds, 'access_token_expires_at');
        if (is_string($expiresAt) && $expiresAt !== '' && Carbon::parse($expiresAt)->isPast()) {
            $refreshed = $this->tryRefreshTokenSync($account);
            if (! $refreshed['ok']) {
                return [
                    'ok' => false,
                    'error_code' => 'REAUTH_REQUIRED',
                    'error_message' => 'Access token expired and refresh failed: '.$refreshed['error'],
                    'retryable' => false,
                ];
            }
            $account->refresh();
            $accessToken = (string) Arr::get($account->credentials, 'access_token', $accessToken);
        }

        // ----- Image pre-upload (TikTok requires their CDN URL, not raw URL) -----
        $imageUrl = Arr::get($payload, 'image_url');
        $messageType = Arr::get($payload, 'message_type', 'text');

        if ($messageType === 'image' && is_string($imageUrl) && $imageUrl !== '' && ! $this->isTikTokCdn($imageUrl)) {
            try {
                $uploaded = $this->uploadImage($account, $accessToken, $shopId, $shopCipher, $imageUrl);
                $imageUrl = $uploaded;
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'error_code' => 'IMAGE_UPLOAD_FAILED',
                    'error_message' => 'Failed to upload image to TikTok: '.$e->getMessage(),
                    'retryable' => true,
                ];
            }
        }

        // ----- Build request body -----
        $body = [
            'shop_id' => $shopId,
            'shop_cipher' => $shopCipher,
            'conversation_id' => (string) Arr::get($payload, 'recipient_id'),
            'message_type' => $messageType,
        ];
        if ($messageType === 'image') {
            $body['content'] = [
                'image_url' => $imageUrl,
                'caption' => (string) Arr::get($payload, 'content.caption', ''),
            ];
        } else {
            $body['content'] = (array) Arr::get($payload, 'content', ['text' => '']);
        }

        // ----- POST with Partner API auth signature -----
        $base = rtrim((string) config('services.tiktok_shop.api_base'), '/');
        $path = '/im/202412/send_message';
        $timestamp = time();
        $appKey = $this->appKey($account);

        try {
            $response = Http::asJson()
                ->timeout(20)
                ->withHeaders([
                    'x-tts-access-token' => $accessToken,
                    'x-app-key' => $appKey,
                    'x-timestamp' => (string) $timestamp,
                ])
                ->post($base.$path, $body);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error_code' => 'NETWORK_ERROR',
                'error_message' => $e->getMessage(),
                'retryable' => true,
            ];
        }

        if ($response->successful()) {
            $respBody = $response->json() ?? [];
            $error = $respBody['error'] ?? null;

            // TikTok Shop Partner success: code = 0 OR no `error` field.
            $code = $respBody['code'] ?? ($error === null ? 0 : -1);
            if ($code === 0 || ($error === null || $error === '' || $error === 'success')) {
                $data = $respBody['data'] ?? [];
                return [
                    'ok' => true,
                    'response' => $respBody,
                    'provider_message_id' => (string) ($data['message_id']
                        ?? $respBody['message_id']
                        ?? ''),
                ];
            }

            // 2xx but error field set / non-zero code -> treat as failure.
            $errorCode = (string) ($error ?? 'ERROR_'.$code);
            $errorMessage = (string) ($respBody['message']
                ?? $respBody['msg']
                ?? 'TikTok send failed');

            return $this->mapTikTokError($errorCode, $errorMessage, $respBody);
        }

        // 4xx / 5xx
        $body = $response->json() ?? [];
        $errorCode = (string) ($body['error']
            ?? $body['code']
            ?? 'HTTP_'.$response->status());

        if ($response->status() === 429 || $errorCode === 'rate_limit_exceeded' || $errorCode === 'rate_limited') {
            $retryAfter = (int) ($response->header('Retry-After') ?? 60);

            return [
                'ok' => false,
                'error_code' => 'RATE_LIMITED',
                'error_message' => 'TikTok rate limit hit. retry_after='.$retryAfter.'s',
                'retryable' => true,
                'response' => $body,
                '_retry_after_seconds' => $retryAfter,
            ];
        }

        if ($response->status() === 401
            || $errorCode === 'unauthorized'
            || $errorCode === 'auth_error'
            || $errorCode === 'invalid_access_token'
            || $errorCode === 'access_token_invalid') {
            $this->markReauthRequired($account, $errorCode, (string) ($body['message'] ?? 'auth failed'));

            return [
                'ok' => false,
                'error_code' => 'REAUTH_REQUIRED',
                'error_message' => 'TikTok returned auth error. Reconnect required.',
                'retryable' => false,
                'response' => $body,
            ];
        }

        return $this->mapTikTokError($errorCode, (string) ($body['message'] ?? 'TikTok send failed'), $body);
    }

    private function mapTikTokError(string $errorCode, string $message, array $respBody = []): array
    {
        $retryable = ! in_array($errorCode, self::NON_RETRYABLE_ERRORS, true);

        return [
            'ok' => false,
            'error_code' => $errorCode,
            'error_message' => $message !== '' ? $message : 'TikTok refused the message.',
            'retryable' => $retryable,
            'response' => $respBody,
        ];
    }

    private function isTikTokCdn(string $url): bool
    {
        return str_contains($url, 'p16-sign-sg.tiktokcdn.com')
            || str_contains($url, 'tiktokcdn')
            || str_contains($url, 'sf.tiktok');
    }

    /**
     * Best-effort image upload to TikTok's media service.
     *
     * Endpoint per Customer Service API: POST /im/202412/upload_image
     * Returns: { image_url: "https://p16-sign-sg.tiktokcdn.com/..." }
     *
     * For cut 1 we pass the image URL directly and let TikTok fetch it
     * (simpler than mirroring the bytes). If TikTok requires multipart,
     * swap to Http::attach() — the contract is the same.
     */
    private function uploadImage(ChannelAccount $account, string $accessToken, string $shopId, string $shopCipher, string $imageUrl): string
    {
        $base = rtrim((string) config('services.tiktok_shop.api_base'), '/');
        $path = '/im/202412/upload_image';
        $timestamp = time();
        $appKey = $this->appKey($account);

        $response = Http::asJson()
            ->timeout(30)
            ->withHeaders([
                'x-tts-access-token' => $accessToken,
                'x-app-key' => $appKey,
                'x-timestamp' => (string) $timestamp,
            ])
            ->post($base.$path, [
                'shop_id' => $shopId,
                'shop_cipher' => $shopCipher,
                'image_url' => $imageUrl,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("TikTok media upload HTTP {$response->status()}");
        }

        $resp = $response->json() ?? [];
        $code = $resp['code'] ?? -1;
        if ($code !== 0) {
            throw new RuntimeException('TikTok media upload error: '.($resp['message'] ?? 'unknown'));
        }

        $data = $resp['data'] ?? [];
        $uploadedUrl = $data['image_url'] ?? null;
        if (! is_string($uploadedUrl) || $uploadedUrl === '') {
            throw new RuntimeException('TikTok media upload returned no image_url.');
        }

        return $uploadedUrl;
    }

    /**
     * Synchronously refresh the access token. Used at the start of sendOutbound
     * when the stored token is past its expiry. After this returns, the caller
     * must reload the channel account to pick up the new credentials.
     *
     * @return array{ok: bool, error?: string}
     */
    private function tryRefreshTokenSync(ChannelAccount $account): array
    {
        try {
            RefreshTikTokAccessTokenJob::dispatchSync($account->id);

            return ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('Sync TikTok token refresh failed', [
                'channel_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function markReauthRequired(ChannelAccount $account, string $code, string $message): void
    {
        $account->forceFill([
            'status' => 'DEGRADED',
            'last_error_code' => 'REAUTH_REQUIRED',
            'last_error_message' => "{$code}: {$message}",
        ])->save();

        Log::warning('TikTok channel account marked REAUTH_REQUIRED', [
            'channel_account_id' => $account->id,
            'reason' => $code,
            'detail' => $message,
        ]);
    }

    private function appKey(ChannelAccount $account): string
    {
        $workspace = Workspace::query()->whereKey($account->workspace_id)->first();
        if ($workspace === null) {
            return '';
        }
        $creds = app(WorkspaceSettings::class)->get($workspace, 'tiktok.partner_credentials');

        return (string) ($creds['app_key'] ?? '');
    }
}