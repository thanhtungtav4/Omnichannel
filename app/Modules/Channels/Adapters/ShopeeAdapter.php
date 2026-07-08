<?php

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Jobs\RefreshShopeeAccessTokenJob;
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
 * Shopee Chat VN adapter (cut 1, specs/11_SHOPEE_CHAT_VN.md).
 *
 * Inbound (W3): see normalizeInbound() above — text/image/video/sticker
 * mapped to canonical shape; product/order/voucher/combo marked UNSUPPORTED;
 * edits detected via version > 1.
 *
 * Outbound (W4):
 *   - buildOutboundPayload: OutboxMessage → Shopee API request shape.
 *   - sendOutbound: pre-upload image if needed, then POST send_message with
 *     HMAC-SHA256 signature. Maps response to the adapter return contract:
 *       { ok: bool, response?: array, provider_message_id?: string,
 *         error_code?: string, error_message?: string, retryable?: bool }
 *
 * Retry policy per spec 11:
 *   - 5xx / network timeout → retryable=true
 *   - HTTP 429 → retryable=true (the SendChannelMessageJob's release() honors
 *     retry_after via $this->release($retryAfter) when error_code starts with
 *     "RATE_LIMITED"; this adapter doesn't have to do it).
 *   - auth_error / unauthorized → mark channel DEGRADED + REAUTH_REQUIRED,
 *     retryable=false. Admin must reconnect.
 *   - buyer_blocked / shop_blocked / recipient_not_found → retryable=false.
 *   - token expired → trigger RefreshShopeeAccessTokenJob + retry once.
 */
class ShopeeAdapter implements ChannelAdapter
{
    /** Message types we render in the Inbox (mirror of inbound rule). */
    private const SUPPORTED_TYPES = ['text', 'image', 'video', 'sticker'];

    /** Provider errors we treat as permanent (no retry). */
    private const NON_RETRYABLE_ERRORS = [
        'buyer_blocked',
        'shop_blocked',
        'recipient_not_found',
        'invalid_recipient',
        'message_too_long',
        'invalid_message_type',
        'invalid_argument',
        'unauthorized',
        'auth_error',
        'invalid_partner_id',
        'invalid_shop_id',
    ];

    public function normalizeInbound(ChannelAccount $account, array $payload): array
    {
        $messageId = (string) Arr::get($payload, 'message_id', '');
        if ($messageId === '') {
            throw new RuntimeException('Shopee payload missing message_id.');
        }

        $shopId = (int) Arr::get($payload, 'shop_id', 0);
        if ($shopId !== (int) ($account->credentials['shop_id'] ?? 0)) {
            throw new RuntimeException(sprintf(
                'Shopee payload shop_id (%d) does not match channel account shop_id (%s).',
                $shopId,
                (string) ($account->credentials['shop_id'] ?? 'n/a'),
            ));
        }

        $rawType = (string) Arr::get($payload, 'message_type', '');
        $messageType = in_array($rawType, self::SUPPORTED_TYPES, true) ? strtoupper($rawType) : 'UNSUPPORTED';
        $content = (array) Arr::get($payload, 'content', []);

        $bodyText = match ($rawType) {
            'text' => (string) Arr::get($content, 'text', ''),
            'image' => (string) Arr::get($content, 'caption', ''),
            'video' => (string) Arr::get($content, 'caption', ''),
            'sticker' => '[Sticker]',
            default => '',
        };

        $version = (int) Arr::get($payload, 'version', 1);
        $isEdit = $version > 1;

        $idempotencyKey = "shopee:{$account->id}:msg:{$messageId}:v{$version}";
        $timestamp = Carbon::createFromTimestamp((int) Arr::get($payload, 'created_timestamp', time()));

        return [
            'provider_event_id' => $messageId.($isEdit ? ':edit:'.$version : ''),
            'idempotency_key' => $idempotencyKey,
            'event_type' => $messageType === 'UNSUPPORTED' ? 'unsupported' : 'message',
            'provider_message_id' => $messageId,
            'provider_message_seq' => $version,
            'is_edit' => $isEdit,
            'is_group' => false,
            'thread_id' => (string) Arr::get($payload, 'conversation_id', ''),
            'group_name' => null,
            'provider_user_id' => (string) Arr::get($payload, 'buyer_id', ''),
            'provider_chat_id' => (string) Arr::get($payload, 'conversation_id', ''),
            'sender_display_name' => (string) Arr::get($payload, 'buyer_name', 'Shopee buyer'),
            'sender_avatar_url' => Arr::get($payload, 'buyer_portrait_url'),
            'body_text' => $bodyText,
            'message_type' => $messageType,
            'attachment_url' => match ($rawType) {
                'image' => Arr::get($content, 'image_url'),
                'video' => Arr::get($content, 'video_url'),
                default => null,
            },
            'provider_timestamp' => $timestamp,
            'raw_profile' => [
                'buyer_id' => Arr::get($payload, 'buyer_id'),
                'buyer_name' => Arr::get($payload, 'buyer_name'),
                'shop_id' => $shopId,
                'conversation_id' => Arr::get($payload, 'conversation_id'),
            ],
            'raw_payload' => $payload,
        ];
    }

    public function buildOutboundPayload(ChannelAccount $account, OutboxMessage $message): array
    {
        $payload = $message->payload ?? [];
        $recipientId = (string) ($message->recipient_external_id
            ?: Arr::get($payload, 'conversation_id')
            ?: Arr::get($payload, 'chat_id', ''));

        if ($recipientId === '') {
            throw new RuntimeException('Shopee outbound: missing conversation_id / chat_id.');
        }

        $rawType = strtolower((string) ($message->message_type ?: 'text'));
        $imageUrl = Arr::get($payload, 'image_url');

        // text OR image with URL — Shopee uses different message_type for image.
        $shopeeType = match (true) {
            $rawType === 'image' && is_string($imageUrl) && $imageUrl !== '' => 'image',
            default => 'text',
        };

        $content = match ($shopeeType) {
            'image' => ['image_url' => $imageUrl, 'caption' => (string) ($message->body_text ?? '')],
            default => ['text' => (string) ($message->body_text ?? '')],
        };

        return [
            'recipient_id' => $recipientId, // conversation_id (Shopee thread)
            'message_type' => $shopeeType,
            'content' => $content,
            'raw_type' => $rawType,
            'image_url' => $imageUrl,
        ];
    }

    public function sendOutbound(ChannelAccount $account, array $payload): array
    {
        $creds = $account->credentials ?? [];
        $accessToken = (string) Arr::get($creds, 'access_token', '');
        $shopId = (int) Arr::get($creds, 'shop_id', 0);

        if ($accessToken === '' || $shopId === 0) {
            return [
                'ok' => false,
                'error_code' => 'MISSING_CREDENTIALS',
                'error_message' => 'Shopee channel account missing access_token or shop_id.',
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

        // ----- Image pre-upload (Shopee requires their CDN URL, not raw URL) -----
        $imageUrl = Arr::get($payload, 'image_url');
        $messageType = Arr::get($payload, 'message_type', 'text');

        if ($messageType === 'image' && is_string($imageUrl) && $imageUrl !== '' && ! $this->isShopeeCdn($imageUrl)) {
            try {
                $uploaded = $this->uploadImage($account, $accessToken, $shopId, $imageUrl);
                $imageUrl = $uploaded;
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'error_code' => 'IMAGE_UPLOAD_FAILED',
                    'error_message' => 'Failed to upload image to Shopee: '.$e->getMessage(),
                    'retryable' => true,
                ];
            }
        }

        // ----- Build request body -----
        $body = [
            'shop_id' => $shopId,
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

        // ----- Sign + POST -----
        $base = rtrim((string) config('services.shopee.api_base'), '/');
        $path = '/seller_chat/send_message';
        $timestamp = time();
        $sign = $this->sign($path, $timestamp, $shopId, $accessToken);

        try {
            $response = Http::asForm()
                ->timeout(20)
                ->withHeaders(['Authorization' => 'Bearer '.$accessToken])
                ->post($base.$path.'?partner_id='.$this->partnerId($account).'&timestamp='.$timestamp.'&sign='.$sign, $body);
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

            // Shopee's success has no `error` field and an empty `msg` or success indicator.
            if ($error === null || $error === '' || $error === 'success') {
                return [
                    'ok' => true,
                    'response' => $respBody,
                    'provider_message_id' => (string) ($respBody['message_id']
                        ?? $respBody['data']['message_id']
                        ?? ''),
                ];
            }

            // 2xx but error field set → treat as failure with the same error_code semantics.
            return $this->mapShopeeError($error, $respBody['message'] ?? '');
        }

        // 4xx / 5xx
        $body = $response->json() ?? [];
        $errorCode = (string) ($body['error'] ?? 'HTTP_'.$response->status());

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 60);
            return [
                'ok' => false,
                'error_code' => 'RATE_LIMITED',
                'error_message' => 'Shopee rate limit hit. retry_after='.$retryAfter.'s',
                'retryable' => true,
                'response' => $body,
                // SendChannelMessageJob's retry honors this via $this->release($seconds).
                // NOTE: this code path currently retries via the job's static backoff;
                // custom retry_after is captured for logging only. Cut 2 may wire
                // dynamic retry_after into the job.
                '_retry_after_seconds' => $retryAfter,
            ];
        }

        if ($response->status() === 401 || $errorCode === 'unauthorized' || $errorCode === 'auth_error') {
            $this->markReauthRequired($account, $errorCode, (string) ($body['message'] ?? 'auth failed'));

            return [
                'ok' => false,
                'error_code' => 'REAUTH_REQUIRED',
                'error_message' => 'Shopee returned auth error. Reconnect required.',
                'retryable' => false,
                'response' => $body,
            ];
        }

        return $this->mapShopeeError($errorCode, (string) ($body['message'] ?? 'Shopee send failed'));
    }

    private function mapShopeeError(string $errorCode, string $message): array
    {
        $retryable = ! in_array($errorCode, self::NON_RETRYABLE_ERRORS, true);

        return [
            'ok' => false,
            'error_code' => $errorCode,
            'error_message' => $message !== '' ? $message : 'Shopee refused the message.',
            'retryable' => $retryable,
        ];
    }

    private function isShopeeCdn(string $url): bool
    {
        return str_contains($url, 'cf.shopee') || str_contains($url, 'seller.shopee');
    }

    /**
     * Sign a Shopee partner-authenticated request.
     * Format: HMAC-SHA256 over `${path}|${timestamp}|${partner_id}|${access_token_or_shop_id}`
     * (Shopee uses access_token for seller-chat endpoints, shop_id for shop-level).
     */
    private function sign(string $path, int $timestamp, int $shopId, string $accessToken): string
    {
        $partnerKey = $this->partnerKey();
        if ($partnerKey === '') {
            return '';
        }

        return hash_hmac('sha256', $path.'|'.$timestamp.'|'.$shopId.'|'.$accessToken, $partnerKey);
    }

    private function partnerId(ChannelAccount $account): int
    {
        $workspace = Workspace::query()->whereKey($account->workspace_id)->first();
        if ($workspace === null) {
            return 0;
        }
        $creds = app(WorkspaceSettings::class)->get($workspace, 'shopee.partner_credentials');

        return (int) ($creds['partner_id'] ?? 0);
    }

    private function partnerKey(): string
    {
        // partner_key is loaded from the first available workspace_settings in
        // cut 1 — when SendChannelMessageJob runs, the workspace context is the
        // channel account's workspace. For signing we look it up via the
        // current request scope. If you sign outside that context, set the
        // key globally in config/services.php (cut 2: introduce a per-call
        // signer that takes Workspace explicitly).
        $workspaceId = (string) request()->route('workspace_id') ?? '';
        // Default: use current tenant from request host.
        if ($workspaceId === '') {
            $workspaceId = (string) (app(\App\Modules\Platform\Tenancy\CurrentWorkspace::class)->id() ?? '');
        }
        if ($workspaceId === '') {
            return '';
        }

        $creds = app(WorkspaceSettings::class)->get(
            Workspace::query()->whereKey($workspaceId)->firstOrNew([]),
            'shopee.partner_credentials',
        );

        return (string) ($creds['partner_key'] ?? '');
    }

    /**
     * Best-effort image upload to Shopee's media service. Shopee accepts a
     * remote URL OR a multipart file. For cut 1 we pass the URL directly and
     * let Shopee fetch it (simpler than mirroring the bytes).
     *
     * Endpoint per Open Platform: POST /api/v2/media/upload_image
     * Returns: { image_url: "https://cf.shopee.vn/..." }
     */
    private function uploadImage(ChannelAccount $account, string $accessToken, int $shopId, string $imageUrl): string
    {
        $base = rtrim((string) config('services.shopee.api_base'), '/');
        $path = '/media/upload_image';
        $timestamp = time();
        $sign = $this->sign($path, $timestamp, $shopId, $accessToken);

        $response = Http::asForm()
            ->timeout(30)
            ->withHeaders(['Authorization' => 'Bearer '.$accessToken])
            ->post($base.$path.'?partner_id='.$this->partnerId($account).'&timestamp='.$timestamp.'&sign='.$sign, [
                'shop_id' => $shopId,
                'image_url' => $imageUrl,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Shopee media upload HTTP {$response->status()}");
        }

        $body = $response->json() ?? [];
        $uploadedUrl = $body['image_url'] ?? $body['data']['image_url'] ?? null;
        if (! is_string($uploadedUrl) || $uploadedUrl === '') {
            throw new RuntimeException('Shopee media upload returned no image_url.');
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
            RefreshShopeeAccessTokenJob::dispatchSync($account->id);

            return ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('Sync Shopee token refresh failed', [
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

        Log::warning('Shopee channel account marked REAUTH_REQUIRED', [
            'channel_account_id' => $account->id,
            'reason' => $code,
            'detail' => $message,
        ]);
    }
}