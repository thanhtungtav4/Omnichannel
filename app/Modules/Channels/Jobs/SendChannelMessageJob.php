<?php

namespace App\Modules\Channels\Jobs;

use App\Modules\Channels\Events\OutboundMessageDelivered;
use App\Modules\Channels\Events\OutboundMessageFailed;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Channels\Services\ChannelAdapterRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendChannelMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Total send attempts before giving up (the retry budget). Kept separate
    // from $tries so the framework's single attempt and our own release()-based
    // retry don't fight over the same number.
    private const MAX_ATTEMPTS = 5;

    // One framework attempt. All retry/backoff is driven by our own release()
    // in markFailedOrRetrying(), keyed off the outbox row's attempts counter —
    // so there is exactly ONE retry mechanism, not two competing ones.
    public int $tries = 1;

    // Cap total lifetime so a permanently stuck job can't live forever even if
    // release() keeps rescheduling (defense in depth alongside the attempts cap).
    public int $maxExceptions = 1;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly string $outboxMessageId) {}

    /**
     * Serialize sends per outbox row: two workers can never process the same
     * message at once (double-send guard on top of the DB claim below).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->outboxMessageId))->expireAfter(180)->dontRelease()];
    }

    public function handle(ChannelAdapterRegistry $registry): void
    {
        // Atomically claim the row: lock it, bail if another worker/attempt
        // already finished or is mid-send, then flip to SENDING and bump the
        // attempt counter in one transaction. This is the cross-server guard
        // (WithoutOverlapping only covers a single worker process).
        $outbox = DB::transaction(function () {
            $row = OutboxMessage::query()
                ->lockForUpdate()
                ->find($this->outboxMessageId);

            if ($row === null) {
                return null;
            }

            if (in_array($row->status, ['SENT', 'CANCELLED', 'SENDING'], true)) {
                return null;
            }

            $row->forceFill([
                'status' => 'SENDING',
                'attempts' => $row->attempts + 1,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return $row;
        });

        // Nothing to do: already delivered, cancelled, or being sent elsewhere.
        if ($outbox === null) {
            return;
        }

        $outbox->loadMissing('channelAccount');

        try {
            $adapter = $registry->for($outbox->channelAccount);
            $payload = $adapter->buildOutboundPayload($outbox->channelAccount, $outbox);
            $result = $adapter->sendOutbound($outbox->channelAccount, $payload);

            if ($result['ok']) {
                $this->markSent($outbox, $result);

                return;
            }

            // RATE_LIMITED providers may signal a custom retry_after via
            // _retry_after_seconds (Shopee, TikTok, Zalo Personal all return
            // this from their adapters). Use it instead of the static backoff
            // when present.
            $customRetryAfter = null;
            if (($result['error_code'] ?? null) === 'RATE_LIMITED'
                && isset($result['_retry_after_seconds'])
                && is_int($result['_retry_after_seconds'])
                && $result['_retry_after_seconds'] > 0) {
                $customRetryAfter = $result['_retry_after_seconds'];
            }

            $this->markFailedOrRetrying(
                $outbox,
                $result['error_code'] ?? 'PROVIDER_SEND_FAILED',
                $result['error_message'] ?? 'Provider send failed.',
                (bool) ($result['retryable'] ?? false),
                $result['response'] ?? null,
                $customRetryAfter,
            );
        } catch (Throwable $exception) {
            $this->markFailedOrRetrying(
                $outbox,
                'PROVIDER_SEND_EXCEPTION',
                $exception->getMessage(),
                true,
                null,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markSent(OutboxMessage $outbox, array $result): void
    {
        $outbox->forceFill([
            'status' => 'SENT',
            'provider_response' => $result['response'] ?? null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        // Inbox owns the Message row — tell it we delivered, don't touch it here.
        OutboundMessageDelivered::dispatch(
            $outbox->message_id,
            $result['provider_message_id'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $providerResponse
     */
    private function markFailedOrRetrying(
        OutboxMessage $outbox,
        string $errorCode,
        string $errorMessage,
        bool $retryable,
        ?array $providerResponse,
        ?int $customRetryAfterSeconds = null,
    ): void {
        $shouldRetry = $retryable && $outbox->attempts < self::MAX_ATTEMPTS;
        $backoffIndex = min($outbox->attempts - 1, count($this->backoff) - 1);
        $staticDelay = $this->backoff[$backoffIndex] ?? 3600;

        // Custom retry_after (from RATE_LIMITED) takes precedence over the
        // static backoff — but we floor it at 5s so a flaky provider can't
        // pin our queue to a tight loop, and cap it at 1h so we don't lose
        // messages forever when a provider sends a huge value.
        $nextDelay = $customRetryAfterSeconds !== null
            ? max(5, min($customRetryAfterSeconds, 3600))
            : $staticDelay;

        $outbox->forceFill([
            'status' => $shouldRetry ? 'RETRYING' : 'FAILED',
            'next_attempt_at' => $shouldRetry ? now()->addSeconds($nextDelay) : null,
            'provider_response' => $providerResponse,
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
        ])->save();

        // Inbox owns Message + Conversation — it decides how to reflect the
        // failure (message status, and resurfacing the thread on a permanent
        // failure). Channels just reports the outcome.
        OutboundMessageFailed::dispatch(
            $outbox->message_id,
            $outbox->conversation_id,
            ! $shouldRetry,
        );

        if ($shouldRetry && $this->job) {
            $this->release($nextDelay);
        }
    }
}
