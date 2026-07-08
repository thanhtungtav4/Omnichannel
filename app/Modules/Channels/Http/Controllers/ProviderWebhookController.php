<?php

namespace App\Modules\Channels\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\WebhookEvent;
use App\Modules\Channels\Services\InboundMessageIngestor;
use App\Modules\Inbox\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProviderWebhookController extends Controller
{
    public function telegram(Request $request, ChannelAccount $channelAccount, InboundMessageIngestor $ingestor): JsonResponse
    {
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        // hash_equals: constant-time compare so the secret can't be guessed by
        // measuring response timing.
        if ($channelAccount->webhook_secret && ! hash_equals((string) $channelAccount->webhook_secret, $secret)) {
            return response()->json(['error' => ['code' => 'INVALID_WEBHOOK_SECRET', 'message' => 'Invalid webhook secret.']], 401);
        }

        // Only ingest actual messages. Telegram also sends my_chat_member,
        // callback_query, chat_member, etc. which have no message/sender and
        // would create junk contacts + "chat not found" reply failures.
        $payload = $request->all();
        if (! $request->has('message') && ! $request->has('edited_message')) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }
        // Normalize edited_message to message shape.
        if ($request->has('edited_message')) {
            $payload['message'] = $payload['edited_message'];
        }

        $result = $ingestor->ingest($channelAccount, $payload, $request->headers->all());

        return response()->json(['ok' => true, 'duplicate' => $result['duplicate']]);
    }

    public function zalo(Request $request, ChannelAccount $channelAccount, InboundMessageIngestor $ingestor): JsonResponse
    {
        // ZALO_OA events come from Zalo's servers, signed HMAC-SHA256 over the
        // raw body with the OA app_secret (spec 05). Verify the signature.
        if ($channelAccount->provider === 'ZALO_OA') {
            $appSecret = (string) data_get($channelAccount->credentials, 'app_secret', '');
            if ($appSecret === '') {
                return response()->json(['error' => ['code' => 'MISSING_APP_SECRET', 'message' => 'OA app secret not configured.']], 401);
            }
            $sig = (string) $request->header('X-Zalo-Signature', '');
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);
            if (! hash_equals($expected, $sig)) {
                return response()->json(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Invalid Zalo signature.']], 401);
            }
        } else {
            // ZALO_PERSONAL events arrive from the Node sidecar, authenticated by
            // a shared token stored as the channel account's webhook_secret.
            $secret = (string) $request->header('X-Sidecar-Token', '');
            if ($channelAccount->webhook_secret && ! hash_equals((string) $channelAccount->webhook_secret, $secret)) {
                return response()->json(['error' => ['code' => 'INVALID_WEBHOOK_SECRET', 'message' => 'Invalid webhook secret.']], 401);
            }
        }

        $result = $ingestor->ingest($channelAccount, $request->all(), $request->headers->all());

        return response()->json(['ok' => true, 'duplicate' => $result['duplicate']]);
    }

    /**
     * Facebook Messenger webhook verification (GET) - echoes hub.challenge when
     * the verify token matches the account's webhook_secret.
     */
    public function facebookVerify(Request $request, ChannelAccount $channelAccount): mixed
    {
        // Facebook sends hub.mode / hub.verify_token / hub.challenge. PHP turns
        // dots in query keys into underscores, so read both forms.
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $verify = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        if ($mode === 'subscribe' && $verify === $channelAccount->webhook_secret) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * Facebook Messenger events (POST). Verifies X-Hub-Signature-256 against the
     * app secret, then ingests each messaging event in the entry[] batch.
     */
    public function facebook(Request $request, ChannelAccount $channelAccount, InboundMessageIngestor $ingestor): JsonResponse
    {
        $appSecret = data_get($channelAccount->credentials, 'app_secret');
        if ($appSecret) {
            $sig = (string) $request->header('X-Hub-Signature-256');
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);
            if (! hash_equals($expected, $sig)) {
                return response()->json(['error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Invalid Facebook signature.']], 401);
            }
        }

        $duplicate = true;
        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                if (! isset($event['message'])) {
                    continue; // skip delivery/read receipts for now
                }
                $result = $ingestor->ingest($channelAccount, $event, $request->headers->all());
                $duplicate = $duplicate && $result['duplicate'];
            }
        }

        return response()->json(['ok' => true, 'duplicate' => $duplicate]);
    }

    /**
     * Shopee Chat VN webhook (specs/11_SHOPEE_CHAT_VN.md, W3 G1.2).
     *
     * HMAC verification happens in VerifyShopeeSignature middleware (mounted
     * on the route). This method handles three branches:
     *
     *   1. Edit (version > 1): update the existing Message row in place,
     *      persisting a new WebhookEvent record for the audit log.
     *   2. Unsupported type (product/order/voucher/combo): persist as
     *      WebhookEvent with status IGNORED. Cut 1 doesn't render these.
     *   3. Normal text/image/video/sticker: hand off to InboundMessageIngestor.
     *
     * All branches return 200 quickly (Shopee retries on non-2xx).
     */
    public function shopee(Request $request, ChannelAccount $channelAccount, InboundMessageIngestor $ingestor): JsonResponse
    {
        $payload = $request->all();
        $messageId = (string) ($payload['message_id'] ?? '');
        $rawType = (string) ($payload['message_type'] ?? '');
        $version = (int) ($payload['version'] ?? 1);
        $isEdit = $version > 1;

        // ---- Branch 1: edit (update existing message in place) ----
        if ($isEdit && $messageId !== '') {
            $existing = Message::query()
                ->where('channel_account_id', $channelAccount->id)
                ->where('provider_message_id', $messageId)
                ->first();

            if ($existing) {
                $content = (array) ($payload['content'] ?? []);
                $bodyText = match ($rawType) {
                    'text' => (string) ($content['text'] ?? ''),
                    'image', 'video' => (string) ($content['caption'] ?? ''),
                    default => $existing->body_text,
                };

                DB::transaction(function () use ($existing, $bodyText, $payload, $channelAccount, $messageId, $version, $request) {
                    $existing->forceFill([
                        'body_text' => $bodyText,
                        'raw_payload' => $payload,
                        'updated_at' => Carbon::now(),
                    ])->save();

                    WebhookEvent::create([
                        'workspace_id' => $channelAccount->workspace_id,
                        'channel_account_id' => $channelAccount->id,
                        'provider' => $channelAccount->provider,
                        'provider_event_id' => $messageId.':edit:'.$version,
                        'idempotency_key' => "shopee:{$channelAccount->id}:msg:{$messageId}:v{$version}",
                        'event_type' => 'message_edit',
                        'headers' => $request->headers->all(),
                        'payload' => $payload,
                        'status' => 'PROCESSED',
                        'processed_at' => now(),
                    ]);
                });

                return response()->json(['ok' => true, 'edit' => true]);
            }

            // No existing message — fall through to ingest which will create one.
            // This handles the rare case of receiving an edit before the original.
        }

        // ---- Branch 2: unsupported message type ----
        // Persist as IGNORED so admins can spot content we silently drop.
        // Don't pollute Inbox with non-renderable rows.
        if (! in_array($rawType, ['text', 'image', 'video', 'sticker'], true)) {
            if ($messageId === '') {
                Log::warning('Shopee webhook with unsupported type and no message_id', [
                    'channel_account_id' => $channelAccount->id,
                    'payload' => $payload,
                ]);
                return response()->json(['ok' => true, 'ignored' => 'no_message_id'], 200);
            }

            WebhookEvent::create([
                'workspace_id' => $channelAccount->workspace_id,
                'channel_account_id' => $channelAccount->id,
                'provider' => $channelAccount->provider,
                'provider_event_id' => $messageId.':ignored',
                'idempotency_key' => "shopee:{$channelAccount->id}:msg:{$messageId}:ignored",
                'event_type' => 'unsupported',
                'headers' => $request->headers->all(),
                'payload' => $payload,
                'status' => 'IGNORED',
                'processed_at' => now(),
            ]);

            return response()->json(['ok' => true, 'ignored' => $rawType]);
        }

        // ---- Branch 3: normal ingest ----
        $result = $ingestor->ingest($channelAccount, $payload, $request->headers->all());

        return response()->json(['ok' => true, 'duplicate' => $result['duplicate']]);
    }
}
