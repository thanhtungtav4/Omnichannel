<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-workspace configuration entry. `value` is encrypted at the application
 * layer via Crypt; the DB stores opaque ciphertext.
 *
 * Use the WorkspaceSettings service for typed get/set; do not query this
 * model directly from feature code.
 */
class WorkspaceSetting extends Model
{
    use HasUuids;

    protected $table = 'workspace_settings';

    protected $guarded = [];

    protected $hidden = ['value'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}