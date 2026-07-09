<?php

namespace App\Modules\Crm\Models;

use App\Modules\Platform\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per ingestion attempt keyed by (workspace, source, source_event_id).
 * Powers the dedup check at the top of ContactIngestor::ingest() and the
 * audit trail for "where did this contact come from".
 *
 * C3 will add a sibling `contact_ingest_failures` table for the
 * non-happy-path records (validation 422s, unknown source, …) so ops can
 * retry/debug without grepping logs.
 */
class ContactIngestEvent extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        // No DB-level FK on contact_id — see migration note. The
        // BelongsToWorkspace trait still scopes queries to the current
        // tenant at the Eloquent layer.
        return $this->belongsTo(Contact::class);
    }
}