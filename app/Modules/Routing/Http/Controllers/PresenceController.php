<?php

namespace App\Modules\Routing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Routing\Models\AgentPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    /**
     * Heartbeat: the agent's browser calls this every ~20s while the CRM is open.
     * Marks them ONLINE + records last_seen_at so the assignment engine only
     * routes to agents who are actually present. A scheduled sweep flips agents
     * with a stale heartbeat to OFFLINE (see console.php).
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();

        AgentPresence::query()->updateOrCreate(
            ['workspace_id' => $user->workspace_id, 'user_id' => $user->id],
            ['status' => 'ONLINE', 'last_seen_at' => now()],
        );

        $user->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /** Explicit "go offline" (e.g. agent clicks away or closes the tab). */
    public function offline(Request $request): JsonResponse
    {
        $user = $request->user();

        AgentPresence::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('user_id', $user->id)
            ->update(['status' => 'OFFLINE', 'last_seen_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
