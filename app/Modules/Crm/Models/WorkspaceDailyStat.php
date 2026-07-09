<?php

namespace App\Modules\Crm\Models;

use App\Modules\Platform\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (workspace, date) of rolled-up stats.
 *
 * Written nightly by Crm\Jobs\AggregateDailyStatsJob. The JSONB
 * `contacts_created_by_source` is keyed by source code:
 *   {WEBSITE_FORM: 12, ZALO_MINIAPP: 3, TELEGRAM: 47, ...}
 *
 * Read by ops dashboards — never written by user-facing flows.
 */
class WorkspaceDailyStat extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'contacts_created_by_source' => 'array',
            'contacts_merged_count' => 'integer',
            'contacts_inbound_messages_count' => 'integer',
        ];
    }
}
