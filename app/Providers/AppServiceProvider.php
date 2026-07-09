<?php

namespace App\Providers;

use App\Http\Middleware\AppFrameGuard;
use App\Modules\Channels\Events\OutboundMessageDelivered;
use App\Modules\Channels\Events\OutboundMessageFailed;
use App\Modules\Crm\Events\ContactArchived;
use App\Modules\Crm\Events\LeadStatusChanged;
use App\Modules\Crm\Listeners\NotifyContactOnContactArchived;
use App\Modules\Crm\Listeners\NotifyContactOnLeadStatusChanged;
use App\Modules\Inbox\Listeners\SyncOutboundMessageResult;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentWorkspace::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerModuleEventListeners();
        $this->registerAppFrameGuard();
        $this->registerIngestRateLimiters();
    }

    /**
     * Cross-module event wiring. Modules communicate through these events
     * instead of importing each other's models (AGENTS.md boundary rule).
     */
    protected function registerModuleEventListeners(): void
    {
        Event::listen(OutboundMessageDelivered::class, [SyncOutboundMessageResult::class, 'delivered']);
        Event::listen(OutboundMessageFailed::class, [SyncOutboundMessageResult::class, 'failed']);

        // Mini App re-engagement (spec 15 § C4). Listeners always run —
        // they self-skip when notify_user is false or the contact has no
        // OA identity, so the cost is one extra dispatch per lead move.
        Event::listen(LeadStatusChanged::class, [NotifyContactOnLeadStatusChanged::class, 'handle']);
        Event::listen(ContactArchived::class, [NotifyContactOnContactArchived::class, 'handle']);
    }

    /**
     * Hardens /admin/*, /settings/*, /platform/*, /api/admin/* against
     * opaque iframe wrapping (the about:srcdoc scenario that breaks
     * Inertia history.replaceState when Cloudflare edge challenges hit).
     * The pipeline middleware variant handles success responses; this
     * RequestHandled listener covers EVERY other response path —
     * exception handler rendered 404s, abort() 401s/403s, unmatched
     * routes — which never go through ResponsePrepared.
     */
    protected function registerAppFrameGuard(): void
    {
        Event::listen(RequestHandled::class, static function (RequestHandled $event): void {
            if (! $event->request instanceof Request) {
                return;
            }
            if (! $event->response instanceof Response) {
                return;
            }
            if (! AppFrameGuard::shouldGuard($event->request)) {
                return;
            }
            AppFrameGuard::decorate($event->request, $event->response);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Rate limiters for the public contact-ingest endpoint (spec 15 § C3).
     *
     * - `ingest.token` is per-resolved-token with a limit pulled from the
     *   token row (default 60/min). Stops one form from blowing up the
     *   whole workspace quota.
     * - `ingest.workspace` is per-workspace with a fixed 1000/min budget
     *   (future: pull from workspace_settings.ingest.workspace_per_hour).
     *   Defense in depth so a workspace can't be flooded by rotating
     *   tokens.
     */
    protected function registerIngestRateLimiters(): void
    {
        RateLimiter::for('ingest.token', function (Request $request) {
            $token = $request->attributes->get('ingest_token');
            $perMinute = (int) ($token->rate_limit_per_minute ?? 60);
            // Key by token id so each token has its own bucket. Falling back
            // to IP is only there as a safety net if the middleware order
            // ever changes (the token middleware should always run first).
            $key = $token ? 'ingest:token:'.$token->id : 'ingest:ip:'.$request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });

        RateLimiter::for('ingest.workspace', function (Request $request) {
            $token = $request->attributes->get('ingest_token');
            $key = $token
                ? 'ingest:workspace:'.$token->workspace_id
                : 'ingest:workspace:ip:'.$request->ip();

            return Limit::perMinute(1000)->by($key);
        });
    }
}
