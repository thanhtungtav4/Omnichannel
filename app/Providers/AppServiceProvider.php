<?php

namespace App\Providers;

use App\Http\Middleware\AppFrameGuard;
use App\Modules\Channels\Events\OutboundMessageDelivered;
use App\Modules\Channels\Events\OutboundMessageFailed;
use App\Modules\Inbox\Listeners\SyncOutboundMessageResult;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    }

    /**
     * Cross-module event wiring. Modules communicate through these events
     * instead of importing each other's models (AGENTS.md boundary rule).
     */
    protected function registerModuleEventListeners(): void
    {
        Event::listen(OutboundMessageDelivered::class, [SyncOutboundMessageResult::class, 'delivered']);
        Event::listen(OutboundMessageFailed::class, [SyncOutboundMessageResult::class, 'failed']);
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
}
