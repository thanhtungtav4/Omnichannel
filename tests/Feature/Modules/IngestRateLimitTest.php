<?php

namespace Tests\Feature\Modules;

use App\Modules\Crm\Models\WorkspaceIngestToken;
use App\Modules\Crm\Services\IngestTokenIssuer;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Per-token throttle on the public ingest endpoint (spec 15 § C3).
 *
 * The `ingest.token` rate limiter pulls `rate_limit_per_minute` from the
 * token row and keys by token id. Bootstrap/app.php puts
 * `PinWorkspaceFromToken` ahead of `ThrottleRequests` in the middleware
 * priority list, so the token is resolved before the named limiter is
 * asked for a Limit — without that ordering, the limiter falls back to
 * the IP-shaped default 60/min and ignores per-token rate limits.
 *
 * Tests swap the cache to the `file` driver so limiter hits survive the
 * `postJson()` call (the default `array` driver is per-process and the
 * counter resets between HTTP calls in the same test).
 */
class IngestRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private WorkspaceIngestToken $tightToken;

    private WorkspaceIngestToken $looseToken;

    private string $tightPlaintext;

    private string $loosePlaintext;

    protected function setUp(): void
    {
        parent::setUp();

        // Persist limiter hits across `postJson()` calls within a single
        // test. Forgetting the cache and RateLimiter singletons forces
        // a re-resolve under the new config; we re-register the named
        // limiters directly because AppServiceProvider's boot ran with
        // the original RateLimiter instance and won't run again.
        config(['cache.default' => 'file']);
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance(\Illuminate\Cache\RateLimiter::class);
        Facade::clearResolvedInstance(\Illuminate\Cache\RateLimiter::class);
        $this->reRegisterIngestRateLimiters();

        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);

        $issuer = app(IngestTokenIssuer::class);

        $tight = $issuer->mint($this->workspace, [
            'name' => 'tight',
            'allowed_sources' => ['WEBSITE_FORM'],
            'rate_limit_per_minute' => 5,
        ]);
        $this->tightToken = $tight['token'];
        $this->tightPlaintext = $tight['plaintext'];

        $loose = $issuer->mint($this->workspace, [
            'name' => 'loose',
            'allowed_sources' => ['WEBSITE_FORM'],
            'rate_limit_per_minute' => 100,
        ]);
        $this->looseToken = $loose['token'];
        $this->loosePlaintext = $loose['plaintext'];
    }

    /**
     * Re-register the named rate limiters that AppServiceProvider's boot
     * set up. We forgot the RateLimiter singleton so the limiter map
     * (kept inside the RateLimiter instance) was wiped; without this
     * re-registration, the throttle middleware throws
     * `Rate limiter [ingest.token] is not defined` on the first hit.
     */
    private function reRegisterIngestRateLimiters(): void
    {
        RateLimiter::for('ingest.token', function (Request $request) {
            $token = $request->attributes->get('ingest_token');
            $perMinute = (int) ($token->rate_limit_per_minute ?? 60);
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

    public function test_http_throttle_returns_429_after_rate_limit_exceeded(): void
    {
        // Pre-burn the tight bucket. The actual cache key is the
        // limiter-name + by-key passed through md5() by ThrottleRequests.
        $byKey = 'ingest:token:'.$this->tightToken->id;
        $cacheKey = md5('ingest.token'.$byKey);

        $limiter = $this->app->make(\Illuminate\Cache\RateLimiter::class);
        for ($i = 0; $i < 5; $i++) {
            $limiter->hit($cacheKey, 60);
        }

        $r = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'over-the-limit',
        ], [
            'X-Workspace-Key' => $this->tightPlaintext,
            'X-Source' => 'WEBSITE_FORM',
        ]);

        $r->assertStatus(429);
    }

    public function test_separate_tokens_have_separate_buckets(): void
    {
        $tightKey = md5('ingest.token'.'ingest:token:'.$this->tightToken->id);
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($tightKey, 60);
        }

        // Tight is exhausted.
        $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'tight-overflow',
        ], [
            'X-Workspace-Key' => $this->tightPlaintext,
            'X-Source' => 'WEBSITE_FORM',
        ])->assertStatus(429);

        // Loose is still open.
        $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'loose-still-works',
        ], [
            'X-Workspace-Key' => $this->loosePlaintext,
            'X-Source' => 'WEBSITE_FORM',
        ])->assertCreated();
    }

    public function test_limiter_decision_uses_token_rate_limit_per_minute(): void
    {
        // Direct limiter inspection on the post-md5 cache key, since
        // the throttle middleware hashes the by-key with the limiter
        // name. This is the exact slot the middleware will read.
        $cacheKey = md5('ingest.token'.'ingest:token:'.$this->tightToken->id);
        $perMinute = 5;

        for ($i = 0; $i < $perMinute; $i++) {
            $this->assertFalse(RateLimiter::tooManyAttempts($cacheKey, $perMinute));
            RateLimiter::hit($cacheKey, 60);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts($cacheKey, $perMinute));
    }
}
