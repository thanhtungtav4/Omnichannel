<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\Stage;
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

    /**
     * Sales opens a lead for a contact from the contact page (spec 15 § Cut 1).
     * Sourced as MANUAL — the contact's source (e.g. WEBSITE_FORM) reflects
     * where the customer came from, while the lead's source reflects the
     * sales action that opened it. They diverge by design.
     *
     * Defaults: owner = current user, pipeline = workspace's default LEAD
     * pipeline, stage = first stage of that pipeline.
     */
    public function createFromContact(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->workspace_id === $request->user()->workspace_id, 403);

        // Same role gate as updateStatus — viewer can't open leads either.
        if (! in_array($request->user()->role, ['owner', 'admin', 'support_lead', 'sales'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'title' => 'Bạn không có quyền mở lead cho khách này.',
            ]);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'value_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Resolve default pipeline + first stage. If the workspace has no
        // pipeline yet (cut-1 bootstrap case), create the lead without those
        // and let the operator drag it onto a stage later.
        $pipeline = Pipeline::query()
            ->where('workspace_id', $contact->workspace_id)
            ->where('type', 'LEAD')
            ->where('is_default', true)
            ->first();

        $stage = null;
        if ($pipeline) {
            $stage = Stage::query()
                ->where('pipeline_id', $pipeline->id)
                ->orderBy('sort_order')
                ->first();
        }

        $lead = Lead::create([
            'workspace_id' => $contact->workspace_id,
            'contact_id' => $contact->id,
            'owner_id' => $request->user()->id,
            'pipeline_id' => $pipeline?->id,
            'stage_id' => $stage?->id,
            'title' => $data['title'],
            'status' => 'NEW',
            'source' => 'MANUAL',
            'value_amount' => $data['value_amount'] ?? null,
            'value_currency' => 'VND',
            'last_activity_at' => now(),
        ]);

        return redirect()
            ->route('admin.leads')
            ->with('success', 'Đã mở lead cho khách.');
    }
}
