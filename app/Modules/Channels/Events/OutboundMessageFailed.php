<?php

namespace App\Modules\Channels\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An outbound send failed. Inbox listens to update the Message status and, on a
 * permanent failure, resurface the conversation as needing an agent.
 */
class OutboundMessageFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $messageId,
        public readonly string $conversationId,
        public readonly bool $permanent,
    ) {}
}
