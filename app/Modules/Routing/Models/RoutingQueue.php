<?php

namespace App\Modules\Routing\Models;

use App\Modules\Platform\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutingQueue extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['requires_online' => 'boolean'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(RoutingQueueMember::class)->orderBy('sort_order');
    }
}
