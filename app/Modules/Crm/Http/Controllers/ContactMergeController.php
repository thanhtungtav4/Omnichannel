<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Services\ContactMerger;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Contact merge (spec 15 § C5).
 *
 * Two surfaces:
 *   - settings.merges Inertia page (index): full UI with picker +
 *     preview + commit.
 *   - JSON endpoints for programmatic access (controller SPA, future
 *     bulk-merge jobs).
 *
 * Owner-only per spec 08 (`crm.contacts.merge` permission). Today we
 * gate by role == 'owner'; the permission table can replace the
 * inline check when RBAC ships its permission rows.
 */
class ContactMergeController extends Controller
{
    public function __construct(private readonly ContactMerger $merger) {}

    /** Settings page — Inertia. */
    public function index(Request $request): Response
    {
        $this->authorizeMerge($request);
        $workspaceId = $this->workspaceId($request);

        // Surface the first batch of duplicates so the page isn't empty.
        $duplicateGroups = $this->findDuplicateGroups($workspaceId);

        return Inertia::render('admin/contacts/merge', [
            'duplicateGroups' => $duplicateGroups,
        ]);
    }

    /**
     * Programmatic duplicate detection. Returns a JSON list of "groups"
     * where one contact has the same phone or email as another.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $this->authorizeMerge($request);
        $workspaceId = $this->workspaceId($request);

        return response()->json([
            'data' => $this->findDuplicateGroups($workspaceId),
        ]);
    }

    /**
     * Preview: compute the post-merge snapshot without committing. Used
     * by the UI's "Review changes" panel before the user confirms.
     */
    public function preview(Request $request, string $winnerId): JsonResponse
    {
        $this->authorizeMerge($request);
        $winner = $this->resolveContactOrFail($request, $winnerId);

        $loserIds = $request->input('loser_ids', []);
        if (! is_array($loserIds) || empty($loserIds)) {
            return response()->json(['error' => ['code' => 'NO_LOSERS', 'message' => 'loser_ids is required.']], 422);
        }
        $losers = $this->loadLosers($request, $loserIds);

        try {
            $preview = $this->merger->preview($winner, $losers);
        } catch (RuntimeException $e) {
            return response()->json(['error' => ['code' => 'PREVIEW_FAILED', 'message' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => $preview]);
    }

    /**
     * Commit the merge.
     */
    public function store(Request $request, string $winnerId): RedirectResponse
    {
        $this->authorizeMerge($request);
        $winner = $this->resolveContactOrFail($request, $winnerId);

        $data = $request->validate([
            'loser_ids' => ['required', 'array', 'min:1'],
            'loser_ids.*' => ['string', 'uuid'],
        ]);

        $losers = $this->loadLosers($request, $data['loser_ids']);

        try {
            $winner = $this->merger->merge($winner, $losers);
        } catch (RuntimeException $e) {
            return back()->withErrors(['merge' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.contacts.show', $winner)
            ->with('success', sprintf(
                'Đã gộp %d liên hệ vào %s.',
                $losers->count(),
                $winner->full_name,
            ));
    }

    /**
     * Compute duplicate groups for a workspace: each group is one contact
     * (the "winner_suggestion") plus 1+ candidates that share phone or
     * email. Grouped by the lowest-id contact so the result is stable.
     *
     * @return array<int, array{winner_suggestion: array<string, mixed>, candidates: array<int, array<string, mixed>>}>
     */
    private function findDuplicateGroups(string $workspaceId): array
    {
        // Find contacts that have at least one other contact in the same
        // workspace sharing phone_normalized OR LOWER(email).
        $rows = DB::select(
            'SELECT c.id, c.full_name, c.phone, c.phone_normalized, c.email,
                    c.source, c.status, c.created_at,
                    COUNT(*) OVER (PARTITION BY c.phone_normalized) AS phone_dup_count,
                    COUNT(*) OVER (PARTITION BY LOWER(c.email)) AS email_dup_count
               FROM contacts c
              WHERE c.workspace_id = ?
                AND (
                    (c.phone_normalized IS NOT NULL
                     AND EXISTS (
                         SELECT 1 FROM contacts c2
                          WHERE c2.workspace_id = c.workspace_id
                            AND c2.id <> c.id
                            AND c2.phone_normalized = c.phone_normalized
                     ))
                    OR
                    (c.email IS NOT NULL
                     AND EXISTS (
                         SELECT 1 FROM contacts c2
                          WHERE c2.workspace_id = c.workspace_id
                            AND c2.id <> c.id
                            AND LOWER(c2.email) = LOWER(c.email)
                     ))
                )
              ORDER BY c.created_at ASC',
            [$workspaceId],
        );

        // Group by a stable key — phone_normalized takes precedence over
        // email so a contact with both fields sits in the phone group.
        $groups = [];
        $seenRoot = [];
        foreach ($rows as $row) {
            $key = $row->phone_normalized
                ? "p:{$row->phone_normalized}"
                : 'e:'.strtolower((string) $row->email);
            if (isset($seenRoot[$key])) {
                continue;
            }
            $seenRoot[$key] = true;

            $candidates = $this->loadCandidatesForKey(
                $workspaceId,
                $row->phone_normalized,
                $row->email,
            );

            if (count($candidates) < 2) {
                // Skip if we somehow ended up with only 1 row.
                continue;
            }

            $groups[] = [
                'match_key' => $key,
                'match_type' => $row->phone_normalized ? 'phone' : 'email',
                'winner_suggestion' => $this->presentContact($candidates[0]),
                'candidates' => array_map(
                    fn (Contact $c) => $this->presentContact($c),
                    array_slice($candidates, 0, 10),
                ),
                'count' => count($candidates),
            ];
        }

        return $groups;
    }

    /**
     * @return array<int, Contact>
     */
    private function loadCandidatesForKey(string $workspaceId, ?string $phoneNormalized, ?string $email): array
    {
        $q = Contact::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId);

        if ($phoneNormalized) {
            $q->where('phone_normalized', $phoneNormalized);
        } else {
            $q->whereRaw('LOWER(email) = ?', [strtolower((string) $email)]);
        }

        // Earliest-created contact wins the winner_suggestion slot.
        return $q->orderBy('created_at')->orderBy('id')->get()->all();
    }

    /**
     * @param  array<int, string>  $loserIds
     * @return Collection<int, Contact>
     */
    private function loadLosers(Request $request, array $loserIds): Collection
    {
        $workspaceId = $this->workspaceId($request);

        return Contact::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $loserIds)
            ->get();
    }

    private function resolveContactOrFail(Request $request, string $contactId): Contact
    {
        $contact = Contact::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $this->workspaceId($request))
            ->whereKey($contactId)
            ->first();

        abort_if($contact === null, 404);

        return $contact;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentContact(Contact $c): array
    {
        return [
            'id' => $c->id,
            'full_name' => $c->full_name,
            'phone' => $c->phone,
            'phone_normalized' => $c->phone_normalized,
            'email' => $c->email,
            'source' => $c->source,
            'status' => $c->status,
            'created_at' => $c->created_at?->toIso8601String(),
            'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
            'identities_count' => $c->identities()->count(),
        ];
    }

    private function authorizeMerge(Request $request): void
    {
        abort_unless($request->user()->role === 'owner', 403);
    }

    private function workspaceId(Request $request): string
    {
        return (string) app(CurrentWorkspace::class)->id();
    }
}
