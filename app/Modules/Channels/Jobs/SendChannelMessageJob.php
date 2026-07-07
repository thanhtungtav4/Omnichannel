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
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendChannelMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly string $outboxMessageId) {}

    public function handle(ChannelAdapterRegistry $registry): void
    {
        $outbox = OutboxMessage::query()
            ->with(['channelAccount'])
            ->findOrFail($this->outboxMessageId);

        if (in_array($outbox->status, ['SENT', 'CANCELLED'], true)) {
            return;
        }

        $outbox->forceFill([
            'status' => 'SENDING',
            'attempts' => $outbox->attempts + 1,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        try {
            $adapter = $registry->for($outbox->channelAccount);
            $payload = $adapter->buildOutboundPayload($outbox->channelAccount, $outbox);
            $result = $adapter->sendOutbound($outbox->channelAccount, $payload);

            if ($result['ok']) {
                $this->markSent($outbox, $result);

                return;
            }

            $this->markFailedOrRetrying(
                $outbox,
                $result['error_code'] ?? 'PROVIDER_SEND_FAILED',
                $result['error_message'] ?? 'Provider send failed.',
                (bool) ($result['retryable'] ?? false),
                $result['response'] ?? null,
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
    ): void {
        $shouldRetry = $retryable && $outbox->attempts < $this->tries;
        $nextDelay = $this->backoff[min($outbox->attempts - 1, count($this->backoff) - 1)] ?? 3600;

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
