<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class LeadController extends Controller
{
    /** Move a lead to another pipeline status (kanban drag). */
    public function updateStatus(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless($lead->workspace_id === $request->user()->workspace_id, 403);
        // Only sales-capable roles move leads through the pipeline. A read-only
        // viewer/support_agent must not be able to flip a lead to WON/LOST.
        // Redirect back with an error (not abort) so Inertia shows a flash toast
        // instead of a raw JSON 403.
        if (! in_array($request->user()->role, ['owner', 'admin', 'support_lead', 'sales'], true)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Bạn không có quyền thay đổi trạng thái lead.']);

            return back();
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['NEW', 'QUALIFYING', 'OPEN', 'WON', 'LOST', 'ARCHIVED'])],
        ]);

        $lead->update([
            'status' => $data['status'],
            'last_activity_at' => now(),
        ]);

        return back()->with('success', 'Lead moved.');
    }
}
