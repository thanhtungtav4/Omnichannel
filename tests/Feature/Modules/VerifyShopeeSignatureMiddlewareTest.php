<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Http\Middleware\VerifyShopeeSignature;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * VerifyShopeeSignature middleware unit tests.
 * HMAC-SHA256 over raw body, keyed by channel account's webhook_secret.
 * Constant-time compare. Mismatch -> 401.
 */
class VerifyShopeeSignatureMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shopee-test-secret';

    private Workspace $workspace;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'sig',
            'name' => 'Signature Test',
            'status' => 'ACTIVE',
        ]);

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'SHOPEE',
            'name' => 'Sig test',
            'status' => 'ACTIVE',
            'credentials' => ['shop_id' => 1],
            'webhook_secret' => self::SECRET,
        ]);
    }

    public function test_accepts_valid_signature(): void
    {
        $body = '{"message_id":"MSG-1","message_type":"text","content":{"text":"hi"}}';
        $sig = hash_hmac('sha256', $body, self::SECRET);

        $request = Request::create('/webhooks/shopee/'.$this->account->id, 'POST', [], [], [], [], $body);
        $request->headers->set('X-Shopee-Signature', $sig);
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route(['POST'], '/webhooks/shopee/{channelAccount}', []);
            $route->bind($request);
            $route->setParameter('channelAccount', $this->account);

            return $route;
        });

        $mw = app(VerifyShopeeSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_accepts_signature_with_uppercase_or_lowercase_hex(): void
    {
        // Shopee may send lowercase or uppercase hex. We lowercase before compare.
        $body = '{"message_id":"MSG-1"}';
        $sig = strtoupper(hash_hmac('sha256', $body, self::SECRET));

        $request = Request::create('/webhooks/shopee/'.$this->account->id, 'POST', [], [], [], [], $body);
        $request->headers->set('X-Shopee-Signature', $sig);
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route(['POST'], '/webhooks/shopee/{channelAccount}', []);
            $route->bind($request);
            $route->setParameter('channelAccount', $this->account);

            return $route;
        });

        $mw = app(VerifyShopeeSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejects_invalid_signature(): void
    {
        $body = '{"message_id":"MSG-1"}';

        $request = Request::create('/webhooks/shopee/'.$this->account->id, 'POST', [], [], [], [], $body);
        $request->headers->set('X-Shopee-Signature', str_repeat('0', 64));
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route(['POST'], '/webhooks/shopee/{channelAccount}', []);
            $route->bind($request);
            $route->setParameter('channelAccount', $this->account);

            return $route;
        });

        $mw = app(VerifyShopeeSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('INVALID_SIGNATURE', $payload['error']['code']);
    }

    public function test_rejects_missing_signature_header(): void
    {
        $body = '{"message_id":"MSG-1"}';

        $request = Request::create('/webhooks/shopee/'.$this->account->id, 'POST', [], [], [], [], $body);
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route(['POST'], '/webhooks/shopee/{channelAccount}', []);
            $route->bind($request);
            $route->setParameter('channelAccount', $this->account);

            return $route;
        });

        $mw = app(VerifyShopeeSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('MISSING_SIGNATURE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_ignores_authorization_header_does_not_use_it(): void
    {
        // Shopee does NOT use the Authorization header. If someone sends
        // a valid signature in Authorization, we should NOT accept it —
        // only the X-Shopee-Signature header is honored.
        $body = '{"message_id":"MSG-1"}';

        $request = Request::create('/webhooks/shopee/'.$this->account->id, 'POST', [], [], [], [], $body);
        $request->headers->set('Authorization', 'HMAC-SHA256 Signature='.hash_hmac('sha256', $body, self::SECRET));
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route(['POST'], '/webhooks/shopee/{channelAccount}', []);
            $route->bind($request);
            $route->setParameter('channelAccount', $this->account);

            return $route;
        });

        $mw = app(VerifyShopeeSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('MISSING_SIGNATURE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_rejects_account_without_webhook_secret(): void
    {
        $this->account->update(['webhook_secret' => null]);

        $body = '{}';
        $request = Request::create('/webhooks/shopee/'.$this->account->id, 'POST', [], [], [], [], $body);
        $request->headers->set('X-Shopee-Signature', str_repeat('0', 64));
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route(['POST'], '/webhooks/shopee/{channelAccount}', []);
            $route->bind($request);
            $route->setParameter('channelAccount', $this->account);

            return $route;
        });

        $mw = app(VerifyShopeeSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('MISSING_WEBHOOK_SECRET', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_rejects_tampered_body(): void
    {
        $body = '{"message_id":"MSG-1","content":{"text":"hi"}}';
        $sig = hash_hmac('sha256', $body, self::SECRET);

        // Send the signature for the ORIGINAL body but a TAMPERED body.
        $tampered = '{"message_id":"MSG-1","content":{"text":"HACKED"}}';

        $request = Request::create('/webhooks/shopee/'.$this->account->id, 'POST', [], [], [], [], $tampered);
        $request->headers->set('X-Shopee-Signature', $sig);
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route(['POST'], '/webhooks/shopee/{channelAccount}', []);
            $route->bind($request);
            $route->setParameter('channelAccount', $this->account);

            return $route;
        });

        $mw = app(VerifyShopeeSignature::class);
        $response = $mw->handle($request, fn ($r) => response('ok', 200));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('INVALID_SIGNATURE', json_decode($response->getContent(), true)['error']['code']);
    }
}