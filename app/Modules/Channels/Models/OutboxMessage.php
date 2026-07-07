<?php

namespace App\Modules\Channels\Models;

use App\Modules\Platform\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxMessage extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'provider_response' => 'array',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    // conversation_id / message_id are plain UUID columns — Channels never
    // navigates into Inbox models. Result is reported back via events
    // (OutboundMessageDelivered / OutboundMessageFailed). Boundary: one-way
    // Inbox -> Channels only.
}
