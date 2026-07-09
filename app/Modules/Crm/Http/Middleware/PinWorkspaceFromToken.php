<?php

namespace App\Modules\Crm\Http\Middleware;

use App\Modules\Crm\Models\WorkspaceIngestToken;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the workspace from the X-Workspace-Key header on the public
 * ingest endpoint. The token's workspace is pinned via CurrentWorkspace
 * so the rest of the request (ContactIngestor, audit log) scopes correctly.
 *
 * The resolved token is attached to the request via $request->attributes
 * under the key `ingest_token` so downstream middleware (rate limiter,
 * HMAC verifier) can use it without re-doing the bcrypt lookup.
 *
 * Lookup strategy: index by `token_prefix` (first 8 chars, indexed). The
 * prefix space is ~40 bits of entropy so collisions are rare; on the
 * off chance two rows share a prefix, bcrypt-verify each candidate.
 *
 * Token format: whk_<32 base32 chars> for form tokens, zmp_<32 base32 chars>
 * for Mini App tokens. Prefix-first lookup narrows bcrypt work to 1-2 rows.
 */
class PinWorkspaceFromToken
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = (string) $request->header('X-Workspace-Key', '');
        if ($plaintext === '') {
            return $this->unauthorized('MISSING_TOKEN', 'X-Workspace-Key header is required.');
        }

        // Prefix tokens are at least 8 chars (whk_xxxx or zmp_xxxx). Reject
        // shorter input early — it's clearly not a valid token shape.
        if (strlen($plaintext) < 12) {
            return $this->unauthorized('MALFORMED_TOKEN', 'X-Workspace-Key is malformed.');
        }

        $prefix = substr($plaintext, 0, 8);

        // Lookup candidates by prefix. WorkspaceScope is bypassed because
        // we don't know the workspace yet — that's the whole point.
        $candidates = WorkspaceIngestToken::query()
            ->withoutWorkspaceScope()
            ->where('token_prefix', $prefix)
            ->get();

        $matched = null;
        foreach ($candidates as $candidate) {
            if (password_verify($plaintext, (string) $candidate->token_hash)) {
                $matched = $candidate;
                break;
            }
        }

        if ($matched === null) {
            return $this->unauthorized('INVALID_TOKEN', 'X-Workspace-Key is invalid.');
        }

        if (! $matched->isUsable()) {
            // Don't leak whether the token was revoked vs expired — same
            // message either way so attackers can't probe state.
            return $this->unauthorized('TOKEN_INACTIVE', 'Token is revoked or expired.');
        }

        // Pin workspace for the rest of the request. Controllers and the
        // ContactIngestor chokepoint rely on this.
        $this->current->forId($matched->workspace_id);

        // Hand the resolved token to downstream middleware / controller.
        $request->attributes->set('ingest_token', $matched);

        return $next($request);
    }

    private function unauthorized(string $code, string $message): Response
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], 401);
    }
}