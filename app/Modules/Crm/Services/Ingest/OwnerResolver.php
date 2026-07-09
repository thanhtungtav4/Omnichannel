<?php

namespace App\Modules\Crm\Services\Ingest;

use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;

/**
 * Decide who owns a freshly-ingested contact.
 *
 * Strategy precedence (highest first):
 *   1. Caller-supplied `owner_id` in the payload. Used by the manual "New
 *      contact" dialog (current user) and by Mini App flows that pin a
 *      campaign owner at submit time.
 *   2. Per-source override from `workspace_settings.ingest.source_overrides`
 *      (e.g. assign all WEBSITE_FORM leads to a sales pool).
 *   3. Workspace default `workspace_settings.ingest.default_owner_strategy`.
 *   4. UNASSIGNED (returns null) for sources with no routing.
 *
 * v1 only resolves #1 and #4 — the rest of the strategy machine ships with
 * Cut 4's sales assignment hook. Returning null here is correct: the
 * contact lands without an owner and the routing engine / kanban UI can
 * pick it up.
 */
final class OwnerResolver
{
    public function __construct(private readonly WorkspaceSettings $settings) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolve(array $payload, Workspace $workspace): ?int
    {
        // #1 explicit override (caller pinned the owner)
        if (array_key_exists('owner_id', $payload) && $payload['owner_id'] !== null) {
            $id = (int) $payload['owner_id'];
            if ($id > 0) {
                return $id;
            }
        }

        // #2/#3 — read the strategy table. v1 just notes the source so future
        // strategy implementations can branch on it; today the table doesn't
        // exist yet, so this is a no-op. Left intentionally so the call
        // surface is stable when strategy resolution lands in C4.
        $strategy = $this->settings->get($workspace, 'ingest.owner_strategy', []);
        if (! is_array($strategy) || empty($strategy)) {
            return null;
        }
        $source = (string) ($payload['source'] ?? '');
        $overrides = $strategy['source_overrides'] ?? [];
        if (isset($overrides[$source]['owner_id'])) {
            return (int) $overrides[$source]['owner_id'];
        }

        return null;
    }
}