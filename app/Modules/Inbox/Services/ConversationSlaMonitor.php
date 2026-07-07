<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Inbox\Models\Conversation;
use App\Modules\Routing\Services\AssignmentService;

/**
 * Periodic sweep (run every minute from the scheduler) that keeps two flows
 * from silently stalling:
 *
 *  - SLA breach: a conversation past its next_response_due_at gets stamped
 *    sla_breached_at ONCE so the cockpit shows an on-screen badge. Stamping
 *    (not re-stamping) keeps the sweep idempotent.
 *
 *  - Stuck assignment: a conversation left WAITING_AGENT (no eligible agent at
 *    ingest, or its owner went offline) is retried against the queue. If an
 *    agent came online since, it now gets picked up instead of rotting.
 *
 * ponytail: single-workspace-friendly full scan by index; add a per-workspace
 * cursor / chunking only when the open-conversation count makes a minutely
 * full scan measurably expensive.
 */
class ConversationSlaMonitor
{
    public function __construct(private readonly AssignmentService $assignmentService) {}

    /**
     * @return array{breached: int, reassigned: int}
     */
    public function sweep(): array
    {
        return [
            'breached' => $this->flagBreaches(),
            'reassigned' => $this->retryStuckAssignments(),
        ];
    }

    private function flagBreaches(): int
    {
        return Conversation::query()
            ->whereNull('sla_breached_at')
            ->whereNotNull('next_response_due_at')
            ->where('next_response_due_at', '<', now())
            ->whereNotIn('status', ['CLOSED', 'SPAM', 'WAITING_CUSTOMER'])
            ->update(['sla_breached_at' => now()]);
    }

    private function retryStuckAssignments(): int
    {
        $count = 0;

        Conversation::query()
            ->where('status', 'WAITING_AGENT')
            ->whereNull('owner_id')
            ->orderBy('last_message_at')
            ->limit(200) // ponytail: cap per run; next tick continues the tail
            ->get()
            ->each(function (Conversation $conversation) use (&$count) {
                if ($this->assignmentService->assign($conversation, null, 'AUTO_RETRY')) {
                    $count++;
                }
            });

        return $count;
    }
}
