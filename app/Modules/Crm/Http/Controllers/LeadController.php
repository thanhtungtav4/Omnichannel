<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /** Move a lead to another pipeline status (kanban drag). */
    public function updateStatus(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless($lead->workspace_id === $request->user()->workspace_id, 403);

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
