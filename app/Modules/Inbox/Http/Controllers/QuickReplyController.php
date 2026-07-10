<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Http\Requests\QuickReplyRequest;
use App\Modules\Inbox\Models\QuickReply;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin CRUD for quick_replies (canned responses). Owner/admin only —
 * gated in QuickReplyRequest::authorize(). WorkspaceScope keeps the list
 * tenant-local automatically.
 */
class QuickReplyController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);

        return Inertia::render('admin/settings/quick-replies', [
            'quickReplies' => QuickReply::query()
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get()
                ->map(fn (QuickReply $q) => [
                    'id' => $q->id,
                    'shortcut' => $q->shortcut,
                    'label' => $q->label,
                    'text' => $q->text,
                    'sortOrder' => $q->sort_order,
                ]),
        ]);
    }

    public function store(QuickReplyRequest $request): RedirectResponse
    {
        QuickReply::create($request->validated());

        return back();
    }

    public function update(QuickReplyRequest $request, QuickReply $quickReply): RedirectResponse
    {
        $quickReply->update($request->validated());

        return back();
    }

    public function destroy(Request $request, QuickReply $quickReply): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);

        $quickReply->delete();

        return back();
    }
}
