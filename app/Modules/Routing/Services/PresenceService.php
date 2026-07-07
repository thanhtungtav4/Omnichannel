<?php

namespace App\Modules\Routing\Services;

use App\Modules\Routing\Models\AgentPresence;

/**
 * Owns the agent workload counter (active_conversation_count). Other modules
 * (Inbox, Channels) must go through this service instead of touching the
 * AgentPresence model directly — Routing is the single writer of presence
 * state, per the module-boundary rules in AGENTS.md.
 */
class PresenceService
{
    /** An agent picked up / was assigned one more conversation. */
    public function conversationAssigned(string $workspaceId, int|string $userId): void
    {
        AgentPresence::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->increment('active_conversation_count');
    }

    /** A conversation left the agent's active load (closed / transferred away). */
    public function conversationReleased(string $workspaceId, int|string $userId): void
    {
        AgentPresence::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('active_conversation_count', '>', 0)
            ->decrement('active_conversation_count');
    }
}
