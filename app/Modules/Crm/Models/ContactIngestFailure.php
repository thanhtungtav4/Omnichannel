<?php

namespace App\Modules\Crm\Models;

use App\Modules\Platform\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit record for public ingest calls that failed BEFORE reaching
 * ContactIngestor (validation error, unknown source for the token,
 * bad HMAC, expired token, malformed JSON).
 *
 * Successful ingests do NOT write a row here — they go through
 * `contact_ingest_events` + `audit_logs`. This table is purely an
 * ops debugging surface so we can replay / inspect what clients sent.
 */
class ContactIngestFailure extends Model
{
    use BelongsToWorkspace, HasUuids;

    /**
     * The table uses `received_at` + `resolved_at` instead of Laravel's
     * default created_at / updated_at columns. Disabling timestamps makes
     * Eloquent skip the auto-stamp on save.
     */
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}