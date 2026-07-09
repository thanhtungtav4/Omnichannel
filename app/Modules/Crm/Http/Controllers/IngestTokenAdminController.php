<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\WorkspaceIngestToken;
use App\Modules\Crm\Services\ContactIngestor;
use App\Modules\Crm\Services\IngestTokenIssuer;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin CRUD for workspace_ingest_tokens (specs/15 § C3).
 *
 * Mint returns the plaintext EXACTLY ONCE. Subsequent calls return only
 * the prefix + metadata. Rotate mints a new token and revokes the old
 * one (preserving the row for audit). Revoke is a soft-delete via
 * `revoked_at`.
 *
 * Per spec 15 § C3: only owner/admin can manage tokens.
 */
class IngestTokenAdminController extends Controller
{
    public function __construct(private readonly IngestTokenIssuer $issuer) {}

    /** Settings page (Inertia). Renders the integrations list. */
    public function index(Request $request): Response
    {
        $this->authorizeManage($request);
        $workspaceId = $this->workspaceId($request);

        $tokens = WorkspaceIngestToken::query()
            ->where('workspace_id', $workspaceId)
            ->latest()
            ->get()
            ->map(fn (WorkspaceIngestToken $token) => $this->present($token));

        return Inertia::render('admin/settings/integrations', [
            'tokens' => $tokens,
            'publicEndpoint' => url('/api/public/ingest/contact'),
        ]);
    }

    /** JSON list — used by future settings UI fetches / API consumers. */
    public function list(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $workspaceId = $this->workspaceId($request);

        $tokens = WorkspaceIngestToken::query()
            ->where('workspace_id', $workspaceId)
            ->latest()
            ->get()
            ->map(fn (WorkspaceIngestToken $token) => $this->present($token));

        return response()->json([
            'data' => $tokens,
            'publicEndpoint' => url('/api/public/ingest/contact'),
        ]);
    }

    /** Mint a new token. Returns plaintext once. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $workspaceId = $this->workspaceId($request);
        $workspace = \App\Modules\Platform\Models\Workspace::query()->findOrFail($workspaceId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'allowed_sources' => ['required', 'array', 'min:1'],
            'allowed_sources.*' => ['string', Rule::in(ContactIngestor::ALLOWED_PUBLIC_SOURCES)],
            'with_hmac' => ['sometimes', 'boolean'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'default_source_detail' => ['nullable', 'string', 'max:120'],
            'domain_whitelist' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $minted = $this->issuer->mint($workspace, [
            'name' => $data['name'],
            'allowed_sources' => $data['allowed_sources'],
            'with_hmac' => (bool) ($data['with_hmac'] ?? false),
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? 60,
            'default_source_detail' => $data['default_source_detail'] ?? null,
            'domain_whitelist' => $data['domain_whitelist'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        // JSON response (not redirect-with-flash) so the UI can capture the
        // plaintext in component state and display it once. The plaintext
        // is gone the moment the user navigates away or refreshes.
        return response()->json([
            'token' => $this->present($minted['token']),
            'plaintext' => $minted['plaintext'],
            'hmac_secret' => $minted['hmac_secret'],
        ], 201);
    }

    /** Soft-revoke. */
    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        $this->authorizeManage($request);
        $token = $this->resolveTokenOrFail($request, $tokenId);

        $this->issuer->revoke($token);

        return response()->json(['ok' => true]);
    }

    /**
     * Rotate: mint a fresh token, revoke the old. Returns plaintext of the
     * new token (once). The old token is soft-deleted in the same DB
     * transaction so a race can't leave the workspace tokenless.
     */
    public function rotate(Request $request, string $tokenId): JsonResponse
    {
        $this->authorizeManage($request);
        $token = $this->resolveTokenOrFail($request, $tokenId);

        $minted = DB::transaction(fn () => $this->issuer->rotate($token));

        return response()->json([
            'token' => $this->present($minted['token']),
            'plaintext' => $minted['plaintext'],
            'hmac_secret' => $minted['hmac_secret'],
        ], 201);
    }

    /**
     * Look up a token by UUID, bypassing the BelongsToWorkspace global
     * scope (which would silently 404 cross-workspace rows via route-model
     * binding). Returns 404 if no such row exists, 403 if it lives in a
     * different workspace than the current request.
     */
    private function resolveTokenOrFail(Request $request, string $tokenId): WorkspaceIngestToken
    {
        $token = WorkspaceIngestToken::query()
            ->withoutWorkspaceScope()
            ->whereKey($tokenId)
            ->first();

        abort_if($token === null, 404);
        abort_unless($token->workspace_id === $this->workspaceId($request), 403);

        return $token;
    }

    private function present(WorkspaceIngestToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'token_prefix' => $token->token_prefix,
            'allowed_sources' => $token->allowed_sources ?? [],
            'requires_hmac' => $token->requiresHmac(),
            'rate_limit_per_minute' => $token->rate_limit_per_minute,
            'default_source_detail' => $token->default_source_detail,
            'domain_whitelist' => $token->domain_whitelist,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'is_active' => $token->isUsable(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }

    private function authorizeManage(Request $request): void
    {
        // spec 08: only owner/admin manage ingest tokens (same as channel accounts).
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);
    }

    private function workspaceId(Request $request): string
    {
        return (string) app(CurrentWorkspace::class)->id();
    }
}