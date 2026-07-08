<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\ResponsePrepared;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lock app screens inside our own origin.
 *
 * Why this exists: the SPA wraps every visitor session into history-based
 * navigation. If a third party embeds /admin/* inside an opaque <iframe
 * srcdoc> wrapper (browser extensions, dev preview sandboxes, monitoring
 * tooling), a Cloudflare edge challenge on that iframe yields a 403 with
 * cf_chl_rt_tk= on the URL. Inertia's history.replaceState then throws a
 * SecurityError against about:srcdoc, the SPA keeps retrying, and the
 * browser console fills with a loop of the same three errors.
 *
 * Forcing X-Frame-Options: SAMEORIGIN + Content-Security-Policy
 * frame-ancestors 'self' makes the iframe wrap impossible in the first
 * place — the browser refuses to render the document at all, so no SPA
 * hydration, no Inertia retry, no console storm.
 *
 * The pipeline-middleware variant (handle) covers the success path; the
 * AppServiceProvider::boot listener covers the failure path (abort() +
 * exception handler). Together they harden every response that comes out
 * of a tenant URL.
 *
 * This is the server-side counterpart to the client-side Inertia fetch
 * shim in resources/js/app.tsx (installInertiaFetchShim). Either side
 * alone leaves a window open; both together close it.
 *
 * Scope: only tenant app and platform admin routes. Webhooks and the
 * health endpoint must stay embeddable so providers can probe them.
 */
final class AppFrameGuard
{
    /**
     * Path prefixes that must refuse to be embedded anywhere we control.
     *
     * Keep aligned with routes/web.php group prefixes (`admin`, `settings`,
     * `api/admin`). The webhook route group is intentionally excluded.
     *
     * @var list<string>
     */
    public const PROTECTED_PREFIXES = [
        'admin',
        'settings',
        'platform',
    ];

    /**
     * Pipeline-middleware variant. Adds the security headers to any
     * response that flows through the web group's middleware stack.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! self::shouldGuard($request)) {
            return $response;
        }

        self::decorate($request, $response);

        return $response;
    }

    public static function shouldGuard(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        if ($path === '') {
            return false;
        }
        // Direct match of a protected prefix (`admin`, `settings`, `platform`).
        if (in_array($path, self::PROTECTED_PREFIXES, true)) {
            return true;
        }
        // Match with `/` boundary so a workspace literally named
        // `admins-things` does not accidentally fall under the same rule.
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }
        // JSON endpoints under /api/admin/* must not be embeds either.
        return str_starts_with($path, 'api/admin');
    }

    /**
     * Decorate the response in place. Idempotent — calling it on a
     * response that already has these headers is a no-op.
     */
    public static function decorate(Request $request, Response $response): void
    {
        $existing = $response->headers->get('X-Frame-Options');
        if ($existing === 'SAMEORIGIN') {
            return;
        }
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'self'",
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
    }
}
