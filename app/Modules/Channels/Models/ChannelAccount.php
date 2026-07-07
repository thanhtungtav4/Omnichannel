<?php

namespace App\Modules\Channels\Models;

use App\Modules\Channels\Casts\EncryptedJsonArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChannelAccount extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'credentials' => EncryptedJsonArray::class,
            'webhook_secret' => 'encrypted',
            'settings' => 'array',
            'last_webhook_at' => 'datetime',
            'last_health_check_at' => 'datetime',
        ];
    }
}
