<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily rollup stats per workspace (spec 15 § Cross-cutting observability).
 *
 * Written nightly by App\Modules\Crm\Jobs\AggregateDailyStatsJob, which
 * aggregates audit_logs for the previous UTC day. The dashboard reads
 * this table for "what got created today" type widgets; we keep it
 * scoped to ingestion + merge because that's what the spec asked for.
 *
 * Unique on (workspace_id, stat_date) so re-running the aggregator
 * for the same day is idempotent — the job uses updateOrCreate().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_daily_stats', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->date('stat_date');
            // Per-source contact create counts: {WEBSITE_FORM: 12, ZALO_MINIAPP: 3, ...}.
            $t->jsonb('contacts_created_by_source')->nullable();
            // Total merge operations (for ops sanity).
            $t->unsignedInteger('contacts_merged_count')->default(0);
            $t->unsignedInteger('contacts_inbound_messages_count')->default(0);
            $t->timestamps();

            $t->unique(['workspace_id', 'stat_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_daily_stats');
    }
};
