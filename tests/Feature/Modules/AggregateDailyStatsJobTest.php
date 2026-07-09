<?php

namespace Tests\Feature\Modules;

use App\Modules\Crm\Jobs\AggregateDailyStatsJob;
use App\Modules\Crm\Models\WorkspaceDailyStat;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AggregateDailyStatsJob (spec 15 § Cross-cutting observability).
 *
 * Verifies the JSONB source buckets + merge count + zero-row completeness.
 * Idempotency: re-running for the same date overwrites the row.
 */
class AggregateDailyStatsJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
    }

    private function ingestRow(string $source, Carbon $when, ?string $actorId = null): AuditLog
    {
        return AuditLog::create([
            'workspace_id' => $this->workspace->id,
            'actor_id' => $actorId,
            'module' => 'crm',
            'action' => 'contact.ingested',
            'subject_type' => 'contact',
            'subject_id' => '00000000-0000-0000-0000-000000000001',
            'metadata' => ['source' => $source, 'source_event_id' => 'evt-'.$source],
            'created_at' => $when,
        ]);
    }

    public function test_aggregates_contact_ingested_by_source(): void
    {
        $today = Carbon::parse('2026-07-09 12:00:00', 'UTC');
        $this->ingestRow('WEBSITE_FORM', $today);
        $this->ingestRow('WEBSITE_FORM', $today);
        $this->ingestRow('WEBSITE_FORM', $today->copy()->subHour());
        $this->ingestRow('ZALO_MINIAPP', $today);
        // Outside day boundary — must NOT count.
        $this->ingestRow('TELEGRAM', $today->copy()->subDay()->subHour());

        (new AggregateDailyStatsJob('2026-07-09'))->handle();

        $row = WorkspaceDailyStat::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('stat_date', '2026-07-09')
            ->firstOrFail();

        $this->assertSame(
            ['WEBSITE_FORM' => 3, 'ZALO_MINIAPP' => 1],
            $row->contacts_created_by_source,
        );
        $this->assertSame(0, $row->contacts_merged_count);
    }

    public function test_counts_merge_audit_rows(): void
    {
        $today = Carbon::parse('2026-07-09 10:00:00', 'UTC');
        AuditLog::create([
            'workspace_id' => $this->workspace->id,
            'module' => 'crm',
            'action' => 'contacts.merged',
            'subject_type' => 'contact',
            'subject_id' => '00000000-0000-0000-0000-000000000099',
            'metadata' => ['winner_id' => 'x', 'loser_ids' => ['a', 'b']],
            'created_at' => $today,
        ]);

        (new AggregateDailyStatsJob('2026-07-09'))->handle();

        $row = WorkspaceDailyStat::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('stat_date', '2026-07-09')
            ->firstOrFail();

        $this->assertSame(1, $row->contacts_merged_count);
    }

    public function test_idempotent_on_rerun(): void
    {
        $today = Carbon::parse('2026-07-09 10:00:00', 'UTC');
        $this->ingestRow('WEBSITE_FORM', $today);

        // First run.
        (new AggregateDailyStatsJob('2026-07-09'))->handle();
        // Second run — same date. updateOrCreate should overwrite, not duplicate.
        (new AggregateDailyStatsJob('2026-07-09'))->handle();

        $this->assertSame(
            1,
            WorkspaceDailyStat::query()
                ->where('workspace_id', $this->workspace->id)
                ->where('stat_date', '2026-07-09')
                ->count(),
        );
    }

    public function test_zero_row_for_workspace_with_no_activity(): void
    {
        // Quiet day — workspace exists but had nothing happen.
        (new AggregateDailyStatsJob('2026-07-09'))->handle();

        $row = WorkspaceDailyStat::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('stat_date', '2026-07-09')
            ->firstOrFail();

        $this->assertNull($row->contacts_created_by_source);
        $this->assertSame(0, $row->contacts_merged_count);
    }

    public function test_for_yesterday_helper(): void
    {
        // Stub "now" so the test is deterministic regardless of when
        // the test runs. CarbonImmutable::setTestNow sets the global
        // "now" Carbon returns.
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        try {
            $job = AggregateDailyStatsJob::forYesterday();
            $this->assertSame('2026-07-09', $job->forDate);
        } finally {
            Carbon::setTestNow(null);
        }
    }
}
