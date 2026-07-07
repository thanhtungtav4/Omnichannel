<?php

namespace App\Modules\Channels\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\InboundMessageIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
