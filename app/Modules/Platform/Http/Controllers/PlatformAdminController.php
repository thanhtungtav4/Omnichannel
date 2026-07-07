<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\CreateWorkspaceWithOwner;
use App\Modules\Platform\Http\Requests\StoreWorkspaceRequest;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAdminController extends Controller
{
    public function index(): Response
    {
        // withCount('users') gives operators an at-a-glance tenant size without
        // an N+1. Ordered newest-first so a just-created tenant is on top.
        $workspaces = Workspace::query()
            ->withCount('users')
            ->latest()
            ->get(['id', 'name', 'slug', 'status', 'created_at'])
            ->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'status' => $w->status,
                'url' => 'https://'.$w->slug.'.'.config('tenant.domain'),
                'users_count' => $w->users_count,
                'created_at' => $w->created_at?->toIso8601String(),
            ]);

        return Inertia::render('platform/workspaces', [
            'workspaces' => $workspaces,
            'tenantDomain' => config('tenant.domain'),
        ]);
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspaceWithOwner $action): RedirectResponse
    {
        $workspace = $action->handle([
            'name' => $request->string('name')->toString(),
            'slug' => $request->string('slug')->toString(),
            'owner_name' => $request->string('owner_name')->toString(),
            'owner_email' => $request->string('owner_email')->toString(),
            'owner_password' => $request->string('owner_password')->toString(),
        ]);

        return back()->with('status', "Workspace {$workspace->slug} created.");
    }
}
