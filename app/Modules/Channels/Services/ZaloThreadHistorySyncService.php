<?php

namespace App\Modules\Channels\Services;

use App\Modules\Channels\Jobs\SyncZaloThreadHistoryJob;
use App\Modules\Inbox\Models\Conversation;
use Illuminate\Support\Carbon;

/**
 * Auto-trigger Zalo history pull when an agent opens a stale thread.
 *
 * Why this exists: Zalo Personal only pushes messages through zca-js's
 * listener.onMessage in real time. If the listener dropped (session expire /
 * network blip / sidecar restart), anything that arrived in the gap window is
 * lost — zca-js does NOT replay old_messages on demand, only on fresh
 * (re)connect. The fix is to pull history for the open thread via
 * `listener.requestOldMessages(threadType, lastMsgId)` whenever an agent opens
 * a thread that looks stale.
 *
 * Heuristic:
 *   - Provider must be ZALO_PERSONAL (OA uses a different push path; Shopee /
 *     Telegram have their own backfill via /webhooks).
 *   - Conversation must have a last_message_at older than STALE_THRESHOLD
 *     (5min). Fresh threads mean realtime listener is keeping up — no need.
 *   - Debounce: don't refire if last_history_sync_at is within DEBOUNCE
 *     seconds (defense against multiple agents opening the same thread in
 *     close succession; also catches stale jobs queued but not yet run).
 *
 * Dispatches SyncZaloThreadHistoryJob async — the controller doesn't wait for
 * the sidecar round-trip (1-5s) so the Inbox page renders immediately.
 * Inbound messages pulled by the sidecar arrive via the existing
 * /webhooks/zalo/{account} path and InboundMessageIngestor dedups via the
 * unique index on (channel, provider_message_id, direction).
 */
class ZaloThreadHistorySyncService
{
    /** A thread is "stale" if its last message is older than this. */
    public const STALE_THRESHOLD_MINUTES = 5;

    /** Minimum gap between sync attempts for the same conversation. */
    public const DEBOUNCE_SECONDS = 30;

    /**
     * Decide whether to sync + dispatch the job. Pure-ish: the only side
     * effect is the dispatch call.
     */
    public function maybeTrigger(Conversation $conversation): void
    {
        if (! $this->shouldSync($conversation)) {
            return;
        }

        SyncZaloThreadHistoryJob::dispatch($conversation->id);
    }

    /**
     * Public so tests + ops can introspect the decision without dispatching.
     */
    public function shouldSync(Conversation $conversation): bool
    {
        // Provider must be ZALO_PERSONAL — OA / Shopee / Telegram have their
        // own backfill paths and use a different sync mechanism.
        $account = $conversation->channelAccount;
        if ($account === null || $account->provider !== 'ZALO_PERSONAL') {
            return false;
        }

        // Debounce: if we already synced recently for this conversation, skip.
        // Covers the case where multiple agents open the same thread within
        // seconds, AND the case where the job is queued behind others.
        if ($conversation->last_history_sync_at !== null
            && $conversation->last_history_sync_at->gt(Carbon::now()->subSeconds(self::DEBOUNCE_SECONDS))) {
            return false;
        }

        // Heuristic: only stale threads. No last_message_at means the
        // conversation never received anything via the realtime listener —
        // we have no cursor to anchor a per-thread history pull, so skip.
        // (Cold-start full history requires reconnecting the nick, which is
        // a heavier operation handled by the Setup dialog "Sync lich su"
        // button, not this auto-trigger.)
        if ($conversation->last_message_at === null
            || $conversation->last_message_at->gt(Carbon::now()->subMinutes(self::STALE_THRESHOLD_MINUTES))) {
            return false;
        }

        return true;
    }
}