<?php

namespace App\Modules\Platform\Actions;

use App\Models\User;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Provisions a new tenant: a workspace plus its first owner user. Called only
 * from the platform admin console. Owner belongs to the workspace (not a
 * platform admin) and can then invite the rest of their team.
 */
class CreateWorkspaceWithOwner
{
    /**
     * @param  array{name:string,slug:string,owner_name:string,owner_email:string,owner_password:string}  $data
     */
    public function handle(array $data): Workspace
    {
        return DB::transaction(function () use ($data): Workspace {
            $workspace = Workspace::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'status' => 'ACTIVE',
            ]);

            User::create([
                'workspace_id' => $workspace->id,
                'name' => $data['owner_name'],
                'display_name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
                'role' => 'owner',
                'status' => 'ACTIVE',
                'email_verified_at' => now(),
            ]);

            return $workspace;
        });
    }
}
