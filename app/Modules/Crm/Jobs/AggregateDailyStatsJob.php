<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Models\WorkspaceDailyStat;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Aggregate yesterday's (or any past date's) ingestion + merge activity
 * per workspace into workspace_daily_stats (spec 15 § Cross-cutting
 * observability).
 *
 * Scheduled to run daily at 00:00 UTC by routes/console.php. Idempotent:
 * updateOrCreate on (workspace_id, stat_date) means re-running for the
 * same day overwrites. Accepts --date=YYYY-MM-DD override for backfill.
 *
 * Cheaper than grepping audit_logs on every dashboard render — a small
 * pre-aggregated row per workspace per day.
 */
class AggregateDailyStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $forDate) {}

    public static function forYesterday(): self
    {
        return new self(Carbon::yesterday()->utc()->toDateString());
    }

    public function handle(): void
    {
        $date = Carbon::parse($this->forDate)->utc()->toDateString();
        $dayStart = Carbon::parse($date.' 00:00:00', 'UTC');
        $dayEnd = $dayStart->copy()->addDay();

        // Get every workspace that had ANY activity in the day. Workspaces
        // with zero activity still get a zero-row so the dashboard's
        // "did we forget to run the job?" alarm doesn't false-positive.
        $activeWorkspaces = AuditLog::query()
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->select('workspace_id')
            ->distinct()
            ->pluck('workspace_id');

        // Include all workspaces so the table is complete even on quiet days.
        $allWorkspaces = Workspace::query()->pluck('id');

        $workspaceIds = $allWorkspaces
            ->merge($activeWorkspaces)
            ->unique()
            ->values();

        foreach ($workspaceIds as $workspaceId) {
            $this->aggregateFor($workspaceId, $dayStart, $dayEnd, $date);
        }
    }

    private function aggregateFor(string $workspaceId, Carbon $dayStart, Carbon $dayEnd, string $date): void
    {
        // contacts_created_by_source — one bucket per source.
        $createdBySource = AuditLog::query()
            ->where('workspace_id', $workspaceId)
            ->where('module', 'crm')
            ->where('action', 'contact.ingested')
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->selectRaw('metadata->>\'source\' AS source, COUNT(*) AS cnt')
            ->groupBy('source')
            ->pluck('cnt', 'source')
            ->all();

        // Filter out NULL / empty source buckets (defensive — ingest rows
        // always have a source, but audit log allows none).
        $createdBySource = array_filter(
            $createdBySource,
            fn ($count, $source) => $source !== null && $source !== '' && $count > 0,
            ARRAY_FILTER_USE_BOTH,
        );

        $mergedCount = (int) AuditLog::query()
            ->where('workspace_id', $workspaceId)
            ->where('module', 'crm')
            ->where('action', 'contacts.merged')
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->count();

        WorkspaceDailyStat::query()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'stat_date' => $date],
            [
                'contacts_created_by_source' => $createdBySource ?: null,
                'contacts_merged_count' => $mergedCount,
            ],
        );
    }
}
