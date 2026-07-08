<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Http\Middleware\VerifyTikTokSignature;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class VerifyTikTokSignatureMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'tiktok-shared-secret-abc';

    private ChannelAccount $account;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::create([
            'slug' => 'tt-sig',
            'name' => 'TikTok Signature Test',
            'status' => 'ACTIVE',
        ]);
        $this->account = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'TIKTOK_SHOP',
            'name' => 'TikTok Test Shop',
            'status' => 'ACTIVE',
            'credentials' => ['shop_id' => 'fake-shop', 'shop_name' => 'Fake'],
            'webhook_secret' => self::SECRET,
        ]);
    }

    private function makeRequest(string $body, array $headers, ?ChannelAccount $account = null): Request
    {
        $account ??= $this->account;
        $request = Request::create('/webhooks/tiktok-shop/'.$account->id, 'POST', [], [], [], [], $body);
        foreach ($headers as $k => $v) {
            $request->headers->set($k, $v);
        }
        $request->setRouteResolver(function () use ($request, $account) {
            $route = new \Illuminate\Routing\Route(['POST'], '/webhooks/tiktok-shop/{channelAccount}', []);
            $route->bind($request);
            $route->setParameter('channelAccount', $account);

            return $route;
        });

        return $request;
    }

    public function test_accepts_valid_signature(): void
    {
        $body = '{"event":"message","message_id":"MSG-TT-1","content":{"text":"hi"}}';
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, self::SECRET);

        $request = $this->makeRequest($body, [
            'TikTok-Signature' => 't='.$ts.',s='.$sig,
        ]);

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_accepts_signature_with_keys_in_any_order(): void
    {
        // TikTok's spec does not guarantee the order of t=,s= parts.
        $body = '{"event":"message"}';
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, self::SECRET);

        $request = $this->makeRequest($body, [
            'TikTok-Signature' => 's='.$sig.',t='.$ts,
        ]);

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejects_invalid_signature(): void
    {
        $body = '{"event":"message"}';
        $ts = time();

        $request = $this->makeRequest($body, [
            'TikTok-Signature' => 't='.$ts.',s='.str_repeat('0', 64),
        ]);

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('INVALID_SIGNATURE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_rejects_missing_signature_header(): void
    {
        $body = '{"event":"message"}';
        $request = $this->makeRequest($body, []);

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('MISSING_SIGNATURE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_rejects_malformed_signature_header(): void
    {
        $body = '{"event":"message"}';
        $request = $this->makeRequest($body, ['TikTok-Signature' => 'garbage-no-comma']);

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('MALFORMED_SIGNATURE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_rejects_stale_timestamp(): void
    {
        $body = '{"event":"message"}';
        $staleTs = time() - VerifyTikTokSignature::REPLAY_WINDOW_SECONDS - 30;
        $sig = hash_hmac('sha256', $staleTs.'.'.$body, self::SECRET);

        $request = $this->makeRequest($body, [
            'TikTok-Signature' => 't='.$staleTs.',s='.$sig,
        ]);

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('STALE_SIGNATURE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_rejects_future_timestamp(): void
    {
        // Future timestamps beyond replay window are also rejected (clock skew protection).
        $body = '{"event":"message"}';
        $futureTs = time() + VerifyTikTokSignature::REPLAY_WINDOW_SECONDS + 30;
        $sig = hash_hmac('sha256', $futureTs.'.'.$body, self::SECRET);

        $request = $this->makeRequest($body, [
            'TikTok-Signature' => 't='.$futureTs.',s='.$sig,
        ]);

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('STALE_SIGNATURE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_rejects_account_without_webhook_secret(): void
    {
        $this->account->update(['webhook_secret' => null]);

        $body = '{}';
        $ts = time();
        $request = $this->makeRequest($body, [
            'TikTok-Signature' => 't='.$ts.',s='.str_repeat('0', 64),
        ]);

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('MISSING_WEBHOOK_SECRET', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_rejects_tampered_body(): void
    {
        $body = '{"event":"message","content":{"text":"hi"}}';
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, self::SECRET);

        $tampered = '{"event":"message","content":{"text":"HACKED"}}';
        $request = $this->makeRequest($tampered, [
            'TikTok-Signature' => 't='.$ts.',s='.$sig,
        ]);

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('INVALID_SIGNATURE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_rejects_when_channel_account_not_resolved(): void
    {
        $body = '{}';
        $request = Request::create('/webhooks/tiktok-shop/999', 'POST', [], [], [], [], $body);
        // Intentionally no setRouteResolver — so route('channelAccount') is null.

        $mw = app(VerifyTikTokSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('CHANNEL_NOT_RESOLVED', json_decode($response->getContent(), true)['error']['code']);
    }
}