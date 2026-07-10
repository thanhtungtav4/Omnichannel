<?php

namespace App\Modules\Inbox\Models;

use App\Modules\Platform\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Agent-editable canned response. Workspace-scoped; filtered by the "/xxx"
 * shortcut in the inbox composer.
 */
class QuickReply extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
