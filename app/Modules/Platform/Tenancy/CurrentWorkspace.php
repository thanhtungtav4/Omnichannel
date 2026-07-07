<?php

namespace App\Modules\Platform\Tenancy;

use App\Modules\Platform\Models\Workspace;

/**
 * Request-scoped holder for the active tenant workspace.
 *
 * Registered as a singleton so the tenant global scope, controllers, jobs, and
 * webhook handlers all read/write the same current workspace. Set once per
 * request by ResolveWorkspace middleware, or manually in out-of-request
 * contexts (queued jobs, webhook ingest) via set()/forId().
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function forId(string $workspaceId): void
    {
        $this->workspace = Workspace::query()->findOrFail($workspaceId);
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?string
    {
        return $this->workspace?->id;
    }

    public function has(): bool
    {
        return $this->workspace !== null;
    }

    /** Run a callback with a temporary workspace, restoring the previous one. */
    public function run(?Workspace $workspace, callable $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
        }
    }
}
