<?php

namespace App\Modules\Crm\Http\Middleware;

use App\Modules\Crm\Models\WorkspaceIngestToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify the X-Signature HMAC header on public ingest calls whose source
 * is ZALO_MINIAPP. The HMAC secret is per-token (encrypted via Laravel
 * Crypt on the model row); the signed string is "{unix}.{raw_body}"
 * keyed by that secret.
 *
 * Format mirrors VerifyTikTokSignature so we share the same client library
 * shape across providers:
 *
 *   Header X-Signature: t=<unix>,s=<hex>
 *     - t = unix seconds at which the event was generated
 *     - s = HMAC-SHA256 hex digest of `${t}.${raw_body}` keyed by token.hmac_secret
 *
 * Replay window: 5 minutes. Tighter than the form path because the Mini App
 * can re-emit events indefinitely; loose enough to absorb clock skew on the
 * Mini App backend.
 *
 * Only runs when:
 *   - the resolved token requires HMAC (token.hmac_secret is set), AND
 *   - the request declares source = ZALO_MINIAPP.
 *
 * The check is skipped for form tokens (whk_*) so the same endpoint can
 * serve both sources without per-route middleware config.
 */
class VerifyIngestSignature
{
    public const REPLAY_WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var WorkspaceIngestToken|null $token */
        $token = $request->attributes->get('ingest_token');

        if (! $token instanceof WorkspaceIngestToken) {
            // PinWorkspaceFromToken must run first. If it didn't, that's a
            // wiring bug — refuse loudly rather than silently skip.
            return response()->json([
                'error' => ['code' => 'TOKEN_NOT_RESOLVED', 'message' => 'Token middleware did not run.'],
            ], 500);
        }

        // HMAC is only enforced when the source is ZALO_MINIAPP. Other
        // public sources (form tokens) don't carry a signature.
        $source = strtoupper((string) $request->header('X-Source', ''));
        if ($source !== 'ZALO_MINIAPP') {
            return $next($request);
        }

        if (! $token->requiresHmac()) {
            return response()->json([
                'error' => ['code' => 'HMAC_NOT_CONFIGURED', 'message' => 'Token is not configured for HMAC.'],
            ], 401);
        }

        $signatureHeader = (string) $request->header('X-Signature', '');
        if ($signatureHeader === '') {
            return response()->json([
                'error' => ['code' => 'MISSING_SIGNATURE', 'message' => 'Missing X-Signature header.'],
            ], 401);
        }

        [$timestamp, $signature] = $this->parseSignatureHeader($signatureHeader);
        if ($timestamp === null || $signature === null) {
            return response()->json([
                'error' => ['code' => 'MALFORMED_SIGNATURE', 'message' => 'X-Signature header is malformed.'],
            ], 401);
        }

        // Replay window — reject anything outside +/- 5 minutes from now.
        if (abs(time() - $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            return response()->json([
                'error' => ['code' => 'STALE_SIGNATURE', 'message' => 'Timestamp outside replay window.'],
            ], 401);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), (string) $token->hmac_secret);

        if (! hash_equals($expected, strtolower($signature))) {
            return response()->json([
                'error' => ['code' => 'INVALID_SIGNATURE', 'message' => 'Signature mismatch.'],
            ], 401);
        }

        return $next($request);
    }

    /**
     * Parse "t=<unix>,s=<hex>" into [timestamp, signature]. Returns [null, null]
     * on malformed input. Order of keys is not guaranteed by the spec.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function parseSignatureHeader(string $header): array
    {
        $parts = array_map('trim', explode(',', $header));
        $timestamp = null;
        $signature = null;
        foreach ($parts as $part) {
            if (str_starts_with($part, 't=')) {
                $timestamp = (int) substr($part, 2);
            } elseif (str_starts_with($part, 's=')) {
                $signature = substr($part, 2);
            }
        }

        if ($timestamp === null || $signature === null || $signature === '') {
            return [null, null];
        }

        return [$timestamp, $signature];
    }
}