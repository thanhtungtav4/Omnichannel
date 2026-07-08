<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Platform\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Platform\Http\Middleware\EnsureUserBelongsToWorkspace;
use App\Modules\Platform\Http\Middleware\RequireWorkspace;
use App\Modules\Platform\Http\Middleware\ResolveWorkspace;
use App\Modules\Platform\Http\Middleware\ResolveWorkspaceFromChannel;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Tenant-app 404 (no workspace on this host) must beat the auth redirect,
        // so apex/admin hosts 404 instead of bouncing guests to login.
        $middleware->prependToPriorityList(
            before: Authenticate::class,
            prepend: RequireWorkspace::class,
        );

        // Webhook tenant resolution must run before route-model binding so the
        // channel account is loaded unscoped and its workspace pinned first.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveWorkspaceFromChannel::class,
        );

        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            'workspace.member' => EnsureUserBelongsToWorkspace::class,
            'workspace.required' => RequireWorkspace::class,
            'workspace.channel' => ResolveWorkspaceFromChannel::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'shopee.signature' => \App\Modules\Channels\Http\Middleware\VerifyShopeeSignature::class,
            'tiktok.signature' => \App\Modules\Channels\Http\Middleware\VerifyTikTokSignature::class,
        ]);

        // Runs before auth on every web request. Tenant hosts get pinned (or
        // 404 on unknown slug); non-tenant hosts (apex/admin/webhooks) pass.
        $middleware->web(prepend: [
            ResolveWorkspace::class,
        ]);

        // AppFrameGuard is NOT registered in the web group — it is wired
        // via AppServiceProvider::registerAppFrameGuard() on RequestHandled,
        // which fires after the exception handler, so it covers abort() /
        // 404 / 401 / 403 paths that a post-handle middleware hook would
        // never see.
        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Provider webhooks come from external servers/the sidecar and carry no
        // CSRF token; they authenticate via secret header instead.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // Behind nginx on the VPS: trust the reverse proxy so Laravel sees the
        // real client IP and https scheme (needed for correct webhook URLs).
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
