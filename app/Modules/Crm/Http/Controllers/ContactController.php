<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Events\ContactArchived;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactNote;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);
        $data = $this->validated($request);

        $contact = Contact::create([
            'workspace_id' => $workspaceId,
            'owner_id' => $request->user()->id,
            'source' => 'MANUAL',
            'status' => 'ACTIVE',
            ...$data,
        ]);

        return redirect()->route('admin.contacts.show', $contact)
            ->with('success', 'Contact created.');
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->workspace_id === $this->workspaceId($request), 403);
        $contact->update($this->validated($request, $contact));

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->workspace_id === $this->workspaceId($request), 403);
        // owner/admin only — deleting a contact cascades its identities & links.
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);

        // Fire the archive event BEFORE the row is hard-deleted so the
        // MiniApp listener can still load the contact's identities and
        // queue a "we removed your data" template (spec 15 § C4).
        ContactArchived::dispatch(
            $contact->id,
            (string) $contact->workspace_id,
        );

        $contact->delete();

        return redirect()->route('admin.contacts')->with('success', 'Contact deleted.');
    }

    /**
     * Pull a Zalo contact's real name + avatar from the sidecar (getUserInfo)
     * and update the contact + its Zalo identity. Fixes contacts whose name is
     * still the raw UID because the thread started with an outbound message.
     */
    public function refreshProfile(Request $request, Contact $contact): RedirectResponse|JsonResponse
    {
        abort_unless($contact->workspace_id === $this->workspaceId($request), 403);

        $identity = ExternalIdentity::query()
            ->where('workspace_id', $contact->workspace_id)
            ->where('contact_id', $contact->id)
            ->where('provider', 'ZALO_PERSONAL')
            ->first();

        if (! $identity) {
            return $this->zaloProfileResponse($request, false, 'Liên hệ này không có định danh Zalo.', 422);
        }

        $base = rtrim((string) config('services.zalo_sidecar.url', env('ZALO_SIDECAR_URL', 'http://127.0.0.1:4501')), '/');
        $token = (string) config('services.zalo_sidecar.token', env('ZALO_SIDECAR_TOKEN', ''));

        // Defence in depth: these ids come from webhook-ingested data, not the
        // request, but reject path separators anyway so a poisoned identity
        // can't rewrite the sidecar URL path (traversal / SSRF).
        $accountId = (string) $identity->provider_account_id;
        $userId = (string) $identity->provider_user_id;
        if (preg_match('#[/\\\\]#', $accountId.$userId)) {
            return $this->zaloProfileResponse($request, false, 'Định danh Zalo không hợp lệ.', 422);
        }

        try {
            $res = Http::withHeaders(['x-sidecar-token' => $token])
                ->timeout(15)
                ->get("{$base}/accounts/".rawurlencode($accountId).'/user/'.rawurlencode($userId));
        } catch (\Throwable $e) {
            return $this->zaloProfileResponse($request, false, 'Không kết nối được sidecar Zalo.', 502);
        }

        if (! $res->successful() || $res->json('ok') !== true) {
            $error = (string) ($res->json('error') ?: $res->json('message') ?: 'UNKNOWN');

            return $this->zaloProfileResponse($request, false, 'Không lấy được hồ sơ Zalo: '.$error, 502);
        }

        $name = $res->json('displayName') ?: $res->json('profile.displayName') ?: $res->json('profile.zaloName');
        $avatar = $res->json('avatar') ?: $res->json('profile.avatar');

        if (blank($name) && blank($avatar)) {
            return $this->zaloProfileResponse($request, false, 'Sidecar Zalo không trả về tên hoặc avatar mới.', 422);
        }

        $contactUpdate = [];
        if (! blank($name)) {
            $contactUpdate['full_name'] = (string) $name;
        }
        if (! blank($avatar)) {
            $contactUpdate['avatar_url'] = (string) $avatar;
        }
        $contact->forceFill($contactUpdate)->save();

        $identityUpdate = [];
        if (! blank($name)) {
            $identityUpdate['display_name'] = (string) $name;
        }
        if (! blank($avatar)) {
            $identityUpdate['avatar_url'] = (string) $avatar;
        }
        $identity->forceFill($identityUpdate)->save();

        return $this->zaloProfileResponse($request, true, 'Đã cập nhật hồ sơ Zalo.', 200, [
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->full_name,
                'avatarUrl' => $contact->avatar_url,
            ],
        ]);
    }

    private function zaloProfileResponse(Request $request, bool $ok, string $message, int $status = 200, array $extra = []): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
                ...$extra,
            ], $ok ? 200 : $status);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }

    /** Set the tags on a contact (free-form, agent-controlled). */
    public function updateTags(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->workspace_id === $this->workspaceId($request), 403);
        $data = $request->validate([
            'tags' => ['present', 'array', 'max:20'],
            'tags.*' => ['nullable', 'string', 'max:30'], // empty strings become null via middleware
        ]);

        // Trim, drop blanks, de-dup (case-insensitive), cap the list.
        $tags = collect($data['tags'])
            ->map(fn ($t) => trim((string) $t))
            ->filter()
            ->unique(fn ($t) => mb_strtolower($t))
            ->take(20)
            ->values()
            ->all();

        $contact->forceFill(['tags' => $tags])->save();

        // Workspace-scope vocabulary sync (mockup §3.5): any new tag added to
        // a contact auto-joins the workspace's vocabulary so other agents see
        // it as a suggestion next time. We don't delete vocabulary entries on
        // tag removal (admins curate the vocabulary separately).
        $settings = app(WorkspaceSettings::class);
        $vocab = $settings->get($contact->workspace, 'tags.vocabulary', []);
        if (! is_array($vocab)) {
            $vocab = [];
        }
        $merged = array_values(array_unique(array_merge($vocab, $tags)));
        if (count($merged) !== count($vocab)) {
            $settings->set($contact->workspace, 'tags.vocabulary', $merged);
        }

        return back()->with('success', 'Đã cập nhật tag.');
    }

    /**
     * Workspace-scope tag vocabulary. Returns the list of allowed tags that
     * any contact in this workspace can choose from. The vocabulary grows
     * automatically as agents add new tags via updateTags(). Admins curate
     * it via the /settings surface (cut 2).
     */
    public function vocabulary(Request $request, Workspace $workspace): JsonResponse
    {
        abort_unless($workspace->id === $this->workspaceId($request), 403);
        $settings = app(WorkspaceSettings::class);
        $vocab = $settings->get($workspace, 'tags.vocabulary', []);

        return response()->json(['vocabulary' => is_array($vocab) ? $vocab : []]);
    }

    /** Add a CSKH note to a contact. */
    public function storeNote(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->workspace_id === $this->workspaceId($request), 403);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        $contact->notes()->create([
            'workspace_id' => $contact->workspace_id,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
            'pinned' => (bool) ($data['pinned'] ?? false),
        ]);

        return back()->with('success', 'Đã thêm ghi chú.');
    }

    /** Delete a contact note (author or owner/admin). */
    public function destroyNote(Request $request, ContactNote $note): RedirectResponse
    {
        abort_unless($note->workspace_id === $this->workspaceId($request), 403);
        abort_unless(
            $note->author_id === $request->user()->id
                || in_array($request->user()->role, ['owner', 'admin', 'support_lead'], true),
            403,
        );

        $note->delete();

        return back()->with('success', 'Đã xoá ghi chú.');
    }

    /** Edit a contact note (author or owner/admin/support_lead). */
    public function updateNote(Request $request, ContactNote $note): RedirectResponse
    {
        abort_unless($note->workspace_id === $this->workspaceId($request), 403);
        abort_unless(
            $note->author_id === $request->user()->id
                || in_array($request->user()->role, ['owner', 'admin', 'support_lead'], true),
            403,
        );

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        $note->forceFill([
            'body' => $data['body'],
            'pinned' => (bool) ($data['pinned'] ?? false),
        ])->save();

        return back()->with('success', 'Đã cập nhật ghi chú.');
    }

    /**
     * Change contact status (ACTIVE / ARCHIVED / BLOCKED). Operators archive
     * spam; owners flip to BLOCKED. Anyone with view+update perm can change.
     */
    public function updateStatus(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->workspace_id === $this->workspaceId($request), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'ARCHIVED', 'BLOCKED'])],
        ]);

        $contact->forceFill(['status' => $data['status']])->save();

        return back()->with('success', 'Đã cập nhật trạng thái.');
    }

    /**
     * Reassign owner. Requires the owner-target user to actually be a member
     * of the same workspace and ACTIVE — otherwise an attacker who guessed a
     * user id could plant contacts on someone outside the tenant.
     */
    public function updateOwner(Request $request, Contact $contact): RedirectResponse
    {
        abort_unless($contact->workspace_id === $this->workspaceId($request), 403);

        $data = $request->validate([
            'owner_id' => ['nullable', 'integer'],
        ]);

        if ($data['owner_id'] !== null) {
            $exists = User::query()
                ->where('workspace_id', $contact->workspace_id)
                ->where('id', $data['owner_id'])
                ->where('status', 'ACTIVE')
                ->exists();
            if (! $exists) {
                // ValidationException handles both JSON 422 (XHR) and
                // redirect-back-with-errors (Inertia form) for free — no
                // need to branch on request type.
                throw ValidationException::withMessages([
                    'owner_id' => 'Owner không hợp lệ hoặc không ở workspace này.',
                ]);
            }
        }

        $contact->forceFill(['owner_id' => $data['owner_id']])->save();

        return back()->with('success', 'Đã cập nhật owner.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Contact $contact = null): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'ARCHIVED', 'BLOCKED'])],
        ]);
    }

    private function workspaceId(Request $request): string
    {
        // Tenant is pinned by ResolveWorkspace from the request subdomain; the
        // signed-in user is guaranteed to belong to it by workspace.member.
        return (string) app(CurrentWorkspace::class)->id();
    }
}
