<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two new assignment reasons:
 *  - MANUAL_CLAIM: an agent picks up an unassigned conversation by replying.
 *  - AUTO_RETRY:   the SLA sweep re-assigns a conversation stuck WAITING_AGENT.
 *
 * Postgres CHECK constraints are immutable, so drop and re-create.
 */
return new class extends Migration
{
    private array $values = [
        'AUTO_STICKY_OWNER', 'AUTO_EVEN', 'AUTO_QUEUE_ORDER',
        'MANUAL_TRANSFER', 'TIMEOUT_REASSIGN', 'ADMIN_OVERRIDE',
        'MANUAL_CLAIM', 'AUTO_RETRY',
    ];

    public function up(): void
    {
        $this->reset($this->values);
    }

    public function down(): void
    {
        $this->reset([
            'AUTO_STICKY_OWNER', 'AUTO_EVEN', 'AUTO_QUEUE_ORDER',
            'MANUAL_TRANSFER', 'TIMEOUT_REASSIGN', 'ADMIN_OVERRIDE',
        ]);
    }

    private function reset(array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement('ALTER TABLE conversation_assignments DROP CONSTRAINT IF EXISTS conversation_assignments_reason_check');
        DB::statement("ALTER TABLE conversation_assignments ADD CONSTRAINT conversation_assignments_reason_check CHECK (reason IN ({$list}))");
    }
};
