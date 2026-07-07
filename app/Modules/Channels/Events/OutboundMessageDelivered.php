<?php

namespace App\Modules\Channels\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Channels delivered an outbound message to the provider. Inbox listens to
 * update its Message row — Channels never touches Inbox models directly.
 */
class OutboundMessageDelivered
{
    use Dispatchable;

    public function __construct(
        public readonly string $messageId,
        public readonly ?string $providerMessageId,
    ) {}
}
