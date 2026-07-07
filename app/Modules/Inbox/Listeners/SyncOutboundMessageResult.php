<?php

namespace App\Modules\Inbox\Listeners;

use App\Modules\Channels\Events\OutboundMessageDelivered;
use App\Modules\Channels\Events\OutboundMessageFailed;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;

/**
 * Inbox reacts to Channels' outbound-send outcome. Channels reports the result
 * via events; Inbox owns the Message + Conversation rows and updates them here.
 */
class SyncOutboundMessageResult
{
    public function delivered(OutboundMessageDelivered $event): void
    {
        $message = Message::find($event->messageId);
        if (! $message) {
            return;
        }
        $message->forceFill([
            'provider_message_id' => $event->providerMessageId ?? $message->provider_message_id,
            'status' => 'SENT',
            'sent_at' => now(),
        ])->save();
    }

    public function failed(OutboundMessageFailed $event): void
    {
        Message::find($event->messageId)?->forceFill([
            'status' => $event->permanent ? 'FAILED' : 'QUEUED',
        ])->save();

        // Permanent failure: the reply never reached the customer, but the
        // conversation was optimistically flipped to WAITING_CUSTOMER when the
        // agent hit send. Flip it back so the thread resurfaces as needing an
        // agent. (Retries stay WAITING_CUSTOMER.)
        if ($event->permanent) {
            Conversation::find($event->conversationId)
                ?->forceFill(['status' => 'WAITING_AGENT'])
                ->save();
        }
    }
}
