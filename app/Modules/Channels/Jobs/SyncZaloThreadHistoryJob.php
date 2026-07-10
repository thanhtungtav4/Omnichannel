<?php

namespace App\Modules\Channels\Jobs;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Background job that asks the Zalo Personal sidecar to pull any messages
 * older than the latest one we know about for this conversation. Sidecar
 * calls zca-js `listener.requestOldMessages(threadType, lastMsgId)` which
 * forwards older messages back to /webhooks/zalo/{account} for normal ingest.
 *
 * WHY A JOB (not a sync controller call): the agent opens the thread and
 * expects the Inbox to render in <500ms. The sidecar round-trip + zca-js
 * backfill can take 1-5s. Doing this async means the UI never blocks on
 * network for the provider.
 *
 * Dedup: InboundMessageIngestor's unique index on (channel_account_id,
 * provider_message_id, direction) drops anything already stored, so we don't
 * have to track "did the pull actually return new messages". A no-op pull is
 * harmless — only the wasted HTTP call to sidecar.
 *
 * Failure handling: tries=1 + no release. If the sidecar is unreachable we
 * log a warning and let the next thread-open retry it. We do NOT want to
 * retry-loop on a dead sidecar — that pins a queue worker on every thread
 * open. The next agent open will re-evaluate and trigger again.
 */
class SyncZaloThreadHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $conversationId) {}

    public function handle(): void
    {
        $conversation = Conversation::query()
            ->withoutWorkspaceScope()
            ->with('channelAccount')
            ->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        // Re-check heuristic at job runtime. The conversation may have received
        // a new realtime message between dispatch and handle — in that case the
        // pull is unnecessary (realtime already covered it) and we shouldn't
        // burn a sidecar call. Cheap early bail.
        if ($conversation->last_message_at !== null
            && $conversation->last_message_at->gt(now()->subMinutes(5))) {
            return;
        }

        $account = $conversation->channelAccount;
        if (! $account instanceof ChannelAccount || $account->provider !== 'ZALO_PERSONAL') {
            return;
        }

        // Anchor: latest message with a real provider id. Skip system notes
        // (no provider_message_id) and skip self-typed notes; both would give
        // a bad cursor.
        $latest = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('provider_message_id')
            ->where('provider_message_id', '!=', '')
            ->latest('id')
            ->first();

        if ($latest === null) {
            return;
        }

        $base = rtrim((string) config('services.zalo_sidecar.url', env('ZALO_SIDECAR_URL', 'http://127.0.0.1:4501')), '/');
        $token = (string) config('services.zalo_sidecar.token', env('ZALO_SIDECAR_TOKEN', ''));

        $payload = [
            'lastMsgId' => $latest->provider_message_id,
            'threadType' => $conversation->is_group ? 'GROUP' : 'USER',
        ];

        try {
            $response = app(HttpClient::class)
                ->withHeaders(['x-sidecar-token' => $token])
                ->timeout(5)
                ->post("{$base}/accounts/{$account->id}/sync", $payload);
        } catch (Throwable $e) {
            Log::warning('Zalo sidecar history sync unreachable', [
                'conversation_id' => $conversation->id,
                'channel_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('Zalo sidecar history sync HTTP failed', [
                'conversation_id' => $conversation->id,
                'channel_account_id' => $account->id,
                'sidecar_status' => $response->status(),
                'sidecar_body' => $response->body(),
            ]);

            return;
        }

        // Only stamp tracking columns on success — if the sidecar rejected,
        // we want the next thread-open to retry rather than be debounced away.
        $conversation->forceFill([
            'last_history_sync_at' => now(),
            'last_history_sync_msg_id' => $latest->provider_message_id,
        ])->save();
    }
}