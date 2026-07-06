<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_contacted_at' => 'datetime',
            'last_inbound_at' => 'datetime',
        ];
    }

    /**
     * Setting `phone` also sets the canonical `phone_normalized` dedup key
     * (spec 10). Keeps 0xxx / 84xxx / +84xxx / spaced numbers matchable.
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => [
                'phone' => $value,
                'phone_normalized' => self::normalizePhone($value),
            ],
        );
    }

    /**
     * Canonicalize a Vietnamese phone to 84XXXXXXXXX. Returns null if there is
     * no usable number. Non-VN / short numbers keep their stripped digits.
     */
    public static function normalizePhone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw); // strip spaces, +, -, ()

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '84')) {
            return $digits; // already +84 / 84 form
        }

        if (str_starts_with($digits, '0')) {
            return '84'.substr($digits, 1); // 09xxxxxxxx -> 849xxxxxxxx
        }

        return $digits; // non-VN or short: keep stripped digits
        // ponytail: no 840-ambiguity handling; add if such inputs actually appear.
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(\App\Modules\Inbox\Models\Conversation::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ContactNote::class)->latest();
    }
}
