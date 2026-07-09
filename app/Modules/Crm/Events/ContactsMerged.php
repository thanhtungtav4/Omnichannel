<?php

namespace App\Modules\Crm\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Two (or more) contacts were merged (spec 15 § C5).
 *
 * Fired by Crm\Services\ContactMerger::merge AFTER the merge commits
 * but before the audit_logs row. Carries the winner's post-merge id
 * (always a fresh value) plus the original loser ids so listeners
 * (Mini App notification, future analytics, future webhooks) can
 * react without re-querying.
 *
 * Note: the event fires AFTER losers are hard-deleted, so listeners
 * that need the losers' data must read from the audit_logs row.
 */
class ContactsMerged
{
    use Dispatchable;

    public function __construct(
        public readonly string $winnerId,
        public readonly string $workspaceId,
        /** @var array<int, string> */
        public readonly array $loserIds,
    ) {}

    public static function fromMerge(string $winnerId, string $workspaceId, array $loserIds): self
    {
        return new self($winnerId, $workspaceId, array_values(array_unique($loserIds)));
    }
}
