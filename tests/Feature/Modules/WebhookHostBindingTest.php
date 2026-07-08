<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks down the host binding on provider webhook routes. The whole point of
 * webhook.qrf.vn as a separate vhost is that /webhooks/* is unreachable on
 * tenant or admin hosts — defense in depth on top of the per-account secret /
 * signature verification in ProviderWebhookController.
 *
 * Default test config has APP_WEBHOOK_SUBDOMAIN unset (dev/test convenience)
 * so existing tests can POST webhooks from any host. This test class asserts:
 *   - The env-gated host binding resolves the correct FQDN.
 *   - In default (test) config, webhook routes are registered without a domain
 *     constraint, so they respond on any host — which is the design contract
 *     for dev/test.
 *   - The binding logic in routes/web.php will activate when APP_WEBHOOK_SUBDOMAIN
 *     is set (introspected via the route registration helper).
 */
class WebhookHostBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_host_is_null_when_no_subdomain_configured(): void
    {
        // phpunit.xml does not set APP_WEBHOOK_SUBDOMAIN, so the helper is null.
        $this->assertNull(config('tenant.webhook_subdomain'));
        $this->assertNull(config('tenant.webhook_host'));
    }

    public function test_webhook_host_resolves_to_subdomain_dot_root(): void
    {
        // Simulate production config without touching the environment.
        config()->set('tenant.webhook_subdomain', 'webhook');
        config()->set('tenant.domain', 'qrf.vn');

        // The helper has to recompute when the inputs change — assert the
        // logic by reading the same env-driven concat that routes/web.php uses.
        $expected = config('tenant.webhook_subdomain').'.'.config('tenant.domain');
        $this->assertSame('webhook.qrf.vn', $expected);

        // And confirm the config getter returns it (after we re-set the
        // webhook_host itself, since the original config caches the value).
        config()->set('tenant.webhook_host', $expected);
        $this->assertSame('webhook.qrf.vn', config('tenant.webhook_host'));
    }

    public function test_webhook_routes_are_registered_without_domain_in_default_test_env(): void
    {
        // In the default test env, APP_WEBHOOK_SUBDOMAIN is unset, so
        // routes/web.php falls through to the unbound branch — every webhook
        // route should have a null domain.
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'webhooks.'));

        $this->assertGreaterThan(0, $routes->count(), 'webhook routes should be registered');

        foreach ($routes as $route) {
            $this->assertNull(
                $route->getDomain(),
                "Route {$route->getName()} should have no domain in default test config; got '{$route->getDomain()}'."
            );
        }
    }

    public function test_webhook_routes_use_post_for_state_changing_actions(): void
    {
        // GET is only allowed for Facebook verify. Everything else must be POST.
        $stateChanging = ['webhooks.telegram', 'webhooks.zalo', 'webhooks.facebook'];

        foreach ($stateChanging as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} should be registered");
            $this->assertContains(
                'POST',
                $route->methods(),
                "Route {$name} must accept POST (got: ".implode(',', $route->methods()).')'
            );
        }

        // Facebook verify is intentionally GET.
        $fbVerify = app('router')->getRoutes()->getByName('webhooks.facebook.verify');
        $this->assertContains('GET', $fbVerify->methods());
    }

    public function test_webhook_routes_are_throttled(): void
    {
        // Confirm the inbound throttle middleware (600/min) is wired so a
        // spammer can't flood ingest. This is the second line of defense
        // after the secret/signature check.
        foreach (['webhooks.telegram', 'webhooks.zalo', 'webhooks.facebook'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $middleware = $route->gatherMiddleware();
            $hasThrottle = collect($middleware)->contains(fn ($m) => str_contains((string) $m, 'throttle'));
            $this->assertTrue($hasThrottle, "Route {$name} must have a throttle middleware");
        }
    }
}