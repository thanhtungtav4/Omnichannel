<?php

namespace App\Modules\Inbox\Models;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Crm\Models\Contact;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'last_message_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
            'last_agent_message_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'next_response_due_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function messages(): HasMany
    {
        // No default order here — callers order explicitly. A relation-level
        // order silently overrides orderBy() in queries and broke id-cursor
        // pagination. Use ->latest('id') / ->orderBy(...) at the call site.
        return $this->hasMany(Message::class);
    }

    /**
     * The denormalized last message (via last_message_id). Eager-loadable in the
     * queue list so we don't run a per-row subquery for the preview.
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }
}
