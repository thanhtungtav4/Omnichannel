<?php

namespace App\Modules\Platform\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query to the CurrentWorkspace. If no workspace is set (CLI,
 * seeders, platform-admin console) the scope is a no-op — those contexts are
 * trusted and must scope explicitly. Escape via Model::withoutWorkspaceScope().
 */
class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(CurrentWorkspace::class)->id();

        if ($workspaceId === null) {
            return;
        }

        $builder->where($model->getTable().'.workspace_id', $workspaceId);
    }
}
