<?php

namespace App\Modules\Crm\Http\Middleware;

use App\Modules\Crm\Models\WorkspaceIngestToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify X-Source against the resolved token's allowed_sources list. Runs
 * AFTER PinWorkspaceFromToken (which loads the token into request attributes)
 * but BEFORE VerifyIngestSignature (which would otherwise reject a wrong
 * source with 401 HMAC_NOT_CONFIGURED instead of the correct 403).
 *
 * Both error shapes are kept distinct on purpose:
 *   403 SOURCE_NOT_ALLOWED — the token cannot ingest this source at all
 *   401 HMAC_NOT_CONFIGURED — the source is allowed but the token lacks HMAC
 */
class EnsureIngestSourceAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var WorkspaceIngestToken|null $token */
        $token = $request->attributes->get('ingest_token');
        if (! $token instanceof WorkspaceIngestToken) {
            // Wiring bug — PinWorkspaceFromToken must run first.
            return response()->json([
                'error' => ['code' => 'TOKEN_NOT_RESOLVED', 'message' => 'Token middleware did not run.'],
            ], 500);
        }

        $source = strtoupper((string) $request->header('X-Source', ''));
        if ($source === '' || ! $token->allowsSource($source)) {
            // Same response for "missing source" and "wrong source" — don't
            // leak which sources the token accepts.
            return response()->json([
                'error' => ['code' => 'SOURCE_NOT_ALLOWED', 'message' => 'X-Source is not allowed for this token.'],
            ], 403);
        }

        return $next($request);
    }
}