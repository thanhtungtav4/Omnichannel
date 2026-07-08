import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

/**
 * Detect Cloudflare challenges / cross-origin redirects on any HTTP request
 * coming out of the SPA shell. If we see a 403 with cf_chl_rt_tk= (or any
 * cf-mitigated=challenge response), the response body is meant for the top
 * window, not the SPA. Trying to navigate inside the current document
 * — especially across an opaque (about:srcdoc) frame — leaves a 403
 * SecurityError in history.replaceState and turns the page into an
 * infinite retry loop. We force a hard top-level navigation and never
 * resolve the promise so Inertia does not retry.
 *
 * Important: this shim must NOT mutate request headers, especially
 * `X-Inertia`. The browser's initial document load is a fetch under the
 * hood, and prepending X-Inertia: true on that load makes Laravel's
 * HandleInertiaRequests middleware render an Inertia component-only
 * response — the browser shows a blank / unstyled page. Inertia's own
 * client sets X-Inertia on subsequent visits, so the header is already
 * in the right place when this shim is the only one that needs to be
 * passive.
 *
 * This shim only owns the client-side failure mode. The server-side
 * counterpart (AppFrameGuard via AppServiceProvider::RequestHandled
 * listener) sets X-Frame-Options / Content-Security-Policy frame-ancestors
 * so the SPA cannot be embedded by opaque wrappers in the first place.
 */
function installInertiaFetchShim(): void {
    if (typeof window === 'undefined') {
        return;
    }
    if ((window as unknown as Record<string, unknown>).__inertiaFetchShimInstalled) {
        return;
    }
    (window as unknown as Record<string, unknown>).__inertiaFetchShimInstalled = true;

    const nativeFetch = window.fetch.bind(window);

    window.fetch = function patchedFetch(
        input: RequestInfo | URL,
        init?: RequestInit,
    ): Promise<Response> {
        const requestUrl =
            typeof input === 'string'
                ? input
                : input instanceof URL
                  ? input.toString()
                  : (input as Request).url;
        return nativeFetch(input, init).then((response) => {
            if (response.status !== 403) {
                return response;
            }
            const finalUrl = response.url || requestUrl;
            const mitigated =
                response.headers.get('cf-mitigated') === 'challenge' ||
                finalUrl.includes('__cf_chl_rt_tk=');
            if (!mitigated) {
                return response;
            }
            if (window.top && window.top !== window) {
                window.top.location.href = finalUrl;
            } else {
                window.location.href = finalUrl;
            }
            return new Promise<Response>(() => {}) as unknown as Response;
        });
    } as typeof fetch;
}

installInertiaFetchShim();

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('platform/'):
                // Platform admin console is out-of-tenant: no tenant sidebar.
                return null;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
