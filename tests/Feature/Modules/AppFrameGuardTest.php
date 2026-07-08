<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use Tests\TestCase;

class AppFrameGuardTest extends TestCase
{
    /**
     * Tenant admin surfaces must close themselves against opaque iframe
     * wrappers (about:srcdoc). Both X-Frame-Options AND CSP
     * frame-ancestors are required because XFO is the floor: modern
     * browsers honor CSP frame-ancestors over XFO when both are set,
     * but legacy clients still consult XFO.
     */
    public function test_admin_inbox_closes_itself_to_iframe_embed(): void
    {
        $response = $this->get('/admin/inbox');

        // Either the route renders (200 with login shell) or bounces to auth
        // (302). Either way, the security headers we care about must be on
        // the FIRST response Laravel returns — not a later one.
        $this->assertContains($response->getStatusCode(), [200, 302], 'unexpected status: '.$response->getStatusCode());
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'));
    }

    public function test_admin_channels_page_also_guarded(): void
    {
        $response = $this->get('/admin/channels');
        $this->assertContains($response->getStatusCode(), [200, 302]);
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function test_settings_page_also_guarded(): void
    {
        $response = $this->get('/settings/profile');
        $this->assertContains($response->getStatusCode(), [200, 302]);
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function test_platform_admin_route_also_guarded(): void
    {
        $response = $this->get('/platform/admin/workspaces');
        $this->assertContains($response->getStatusCode(), [200, 302, 403, 404]);
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    /**
     * The health endpoint and public webhook receivers MUST stay
     * embeddable. Providers and uptime probes hit them with various
     * user agents and sometimes inside iframe previews; closing them
     * would break monitoring.
     */
    public function test_health_endpoint_is_NOT_guarded(): void
    {
        $response = $this->get('/up');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->headers->get('X-Frame-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_welcome_page_is_NOT_guarded(): void
    {
        $response = $this->get('/');
        $this->assertContains($response->getStatusCode(), [200, 302]);
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    /**
     * Webhook receivers are public; external providers (Telegram, Zalo,
     * Shopee, TikTok) post from servers that may follow their own
     * embedded preflight UIs.
     */
    public function test_webhook_path_is_NOT_guarded_by_path_prefix(): void
    {
        $pathCheck = ['webhooks/telegram/abc', 'webhooks/shopee/abc', 'webhooks/tiktok-shop/abc'];
        foreach ($pathCheck as $path) {
            // We cannot actually invoke the webhook without a valid signature,
            // so we just verify the middleware's filter logic leaves the
            // 'webhooks/*' path alone — the production behavior is covered
            // in the per-adapter webhook tests.
            $request = \Illuminate\Http\Request::create($path, 'POST');
            $middleware = new \App\Http\Middleware\AppFrameGuard();
            $reflection = new \ReflectionClass($middleware);
            $should = $reflection->getMethod('shouldGuard');
            $should->setAccessible(true);
            $this->assertFalse($should->invoke($middleware, $request), "{$path} should NOT be guarded");
        }
    }

    public function test_path_filter_respects_prefix_boundary(): void
    {
        // 'admin' prefix must trigger when followed by `/` or exact match.
        // Stray path 'admins-thing' must NOT match (no `/` boundary right
        // after the prefix); 'admin' itself must match.
        $admin = \Illuminate\Http\Request::create('/admin/inbox', 'GET');
        $adminExact = \Illuminate\Http\Request::create('/admin', 'GET');
        $adminz = \Illuminate\Http\Request::create('/admins-thing/here', 'GET');
        $settings = \Illuminate\Http\Request::create('/settings/profile', 'GET');
        $settingsExact = \Illuminate\Http\Request::create('/settings', 'GET');
        $apiAdmin = \Illuminate\Http\Request::create('/api/admin/workspaces/1/tag-vocabulary', 'GET');

        $guard = \App\Http\Middleware\AppFrameGuard::class;

        $this->assertTrue(\App\Http\Middleware\AppFrameGuard::shouldGuard($admin));
        $this->assertTrue(\App\Http\Middleware\AppFrameGuard::shouldGuard($adminExact));
        $this->assertTrue(\App\Http\Middleware\AppFrameGuard::shouldGuard($settings));
        $this->assertTrue(\App\Http\Middleware\AppFrameGuard::shouldGuard($settingsExact));
        $this->assertTrue(\App\Http\Middleware\AppFrameGuard::shouldGuard($apiAdmin));

        // Stray paths must NOT trigger the guard so a workspace named
        // 'admins-thing' or 'settings-faq' is not broken.
        $this->assertFalse(
            \App\Http\Middleware\AppFrameGuard::shouldGuard($adminz),
            'admins-thing must NOT match admin prefix without / boundary',
        );
        $this->assertFalse(
            \App\Http\Middleware\AppFrameGuard::shouldGuard(
                \Illuminate\Http\Request::create('/settings-faq/article', 'GET'),
            ),
        );

        // Public surfaces must NOT trigger the guard.
        $this->assertFalse(\App\Http\Middleware\AppFrameGuard::shouldGuard(
            \Illuminate\Http\Request::create('/up', 'GET'),
        ));
        $this->assertFalse(\App\Http\Middleware\AppFrameGuard::shouldGuard(
            \Illuminate\Http\Request::create('/', 'GET'),
        ));
        $this->assertFalse(\App\Http\Middleware\AppFrameGuard::shouldGuard(
            \Illuminate\Http\Request::create('/webhooks/telegram/abc', 'GET'),
        ));
    }
}
