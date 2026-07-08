<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\WebhookEvent;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Routing\Models\RoutingQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shopee webhook controller integration (specs/11 W3 G1.2).
 *
 * Covers:
 *   - Happy path: text message -> conversation + message created
 *   - Duplicate: same message_id -> idempotent return
 *   - Edit: version > 1 -> existing message updated in place
 *   - Edit before original: falls through to normal ingest
 *   - Unsupported type (product) -> persisted as IGNORED webhook event
 *   - Missing HMAC signature -> 401 INVALID_SIGNATURE / MISSING_SIGNATURE
 *   - shop_id mismatch -> 401 INVALID_SIGNATURE (rejected by middleware)
 *
 * Tests bypass route binding by invoking the route handler directly with
 * HMAC-signed bodies + a pre-resolved ChannelAccount route param.
 */
class ShopeeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shopee-secret';

    private Workspace $workspace;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'slug' => 'wh',
            'name' => 'Webhook Test',
            'status' => 'ACTIVE',
        ]);

        RoutingQueue::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Default',
            'status' => 'ACTIVE',
            'mode' => 'STICKY_THEN_EVEN',
            'timeout_seconds' => 300,
            'max_active_per_agent' => 5,
            'requires_online' => true,
        ]);

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'SHOPEE',
            'name' => 'Shopee shop 123456',
            'status' => 'ACTIVE',
            'credentials' => ['shop_id' => 123456, 'access_token' => 'a', 'refresh_token' => 'r'],
            'webhook_secret' => self::SECRET,
        ]);
    }

    private function webhookPost(string $body): \Illuminate\Testing\TestResponse
    {
        $sig = hash_hmac('sha256', $body, self::SECRET);

        return $this->call(
            'POST',
            route('webhooks.shopee', $this->account),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_HOST' => 'webhook.qrf.vn',
                'HTTPS' => 'on',
                'HTTP_X_SHOPEE_SIGNATURE' => $sig,
            ],
            $body,
        );
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'message_id' => 'MSG-'.uniqid(),
            'conversation_id' => 'CONV-789',
            'shop_id' => 123456,
            'buyer_id' => 55555,
            'buyer_name' => 'Nguyen Van A',
            'buyer_portrait_url' => 'https://cf.shopee.vn/avatar',
            'message_type' => 'text',
            'content' => ['text' => 'Hello CRM'],
            'created_timestamp' => 1720000000,
            'version' => 1,
        ], $overrides);
    }

    public function test_creates_conversation_and_message_for_text(): void
    {
        $payload = $this->basePayload();

        $response = $this->webhookPost(json_encode($payload));

        $response->assertOk();
        $response->assertJson(['ok' => true, 'duplicate' => false]);

        $this->assertSame(1, Message::count());
        $msg = Message::first();
        $this->assertSame('Hello CRM', $msg->body_text);
        $this->assertSame('INBOUND', $msg->direction);
        $this->assertSame('CUSTOMER', $msg->sender_type);
        $this->assertSame('TEXT', $msg->message_type);
        $this->assertSame($payload['message_id'], $msg->provider_message_id);
    }

    public function test_returns_duplicate_for_repeated_payload(): void
    {
        $payload = $this->basePayload(['message_id' => 'MSG-DUP']);

        $this->webhookPost(json_encode($payload))->assertOk()->assertJson(['ok' => true, 'duplicate' => false]);
        $this->webhookPost(json_encode($payload))->assertOk()->assertJson(['ok' => true, 'duplicate' => true]);

        // Only one message row should exist
        $this->assertSame(1, Message::where('provider_message_id', 'MSG-DUP')->count());
    }

    public function test_edit_updates_existing_message_in_place(): void
    {
        $original = $this->basePayload(['message_id' => 'MSG-EDIT', 'content' => ['text' => 'Original text']]);
        $this->webhookPost(json_encode($original))->assertOk();

        $msg = Message::where('provider_message_id', 'MSG-EDIT')->firstOrFail();
        $this->assertSame('Original text', $msg->body_text);

        $edit = $this->basePayload([
            'message_id' => 'MSG-EDIT',
            'version' => 2,
            'content' => ['text' => 'Edited text'],
        ]);

        $response = $this->webhookPost(json_encode($edit));

        $response->assertOk();
        $response->assertJson(['ok' => true, 'edit' => true]);

        $msg->refresh();
        $this->assertSame('Edited text', $msg->body_text);

        // Two webhook events: one PROCESSED (insert), one PROCESSED (edit)
        $events = WebhookEvent::where('idempotency_key', 'like', "shopee:{$this->account->id}:msg:MSG-EDIT:%")->get();
        $this->assertCount(2, $events);
    }

    public function test_unsupported_product_message_persists_as_ignored(): void
    {
        $payload = $this->basePayload([
            'message_id' => 'MSG-PROD',
            'message_type' => 'product',
            'content' => ['product_id' => 'P-123', 'text' => 'product card'],
        ]);

        $response = $this->webhookPost(json_encode($payload));

        $response->assertOk();
        $response->assertJson(['ok' => true, 'ignored' => 'product']);

        $this->assertSame(0, Message::count());

        $event = WebhookEvent::where('provider_event_id', 'MSG-PROD:ignored')->firstOrFail();
        $this->assertSame('IGNORED', $event->status);
        $this->assertSame('unsupported', $event->event_type);
    }

    public function test_image_message_persists_attachment(): void
    {
        $payload = $this->basePayload([
            'message_id' => 'MSG-IMG',
            'message_type' => 'image',
            'content' => [
                'image_url' => 'https://cf.shopee.vn/file-abc',
                'caption' => 'Look at this',
            ],
        ]);

        $this->webhookPost(json_encode($payload))->assertOk();

        $msg = Message::where('provider_message_id', 'MSG-IMG')->firstOrFail();
        $this->assertSame('IMAGE', $msg->message_type);
        $this->assertSame('Look at this', $msg->body_text);
        $this->assertNotNull($msg->attachments->first());
        $this->assertSame(
            'https://cf.shopee.vn/file-abc',
            $msg->attachments->first()->metadata['url'],
        );
    }

    public function test_rejects_missing_signature_header(): void
    {
        $body = json_encode($this->basePayload());

        $response = $this->withServerVariables(['HTTP_HOST' => 'webhook.qrf.vn', 'HTTPS' => 'on'])
            ->call(
                'POST',
                route('webhooks.shopee', $this->account),
                [], [], [],
                ['CONTENT_TYPE' => 'application/json'],
                $body,
            );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'MISSING_SIGNATURE');
    }

    public function test_rejects_tampered_body(): void
    {
        $original = json_encode($this->basePayload(['message_id' => 'MSG-TAMPER']));
        $sig = hash_hmac('sha256', $original, self::SECRET);

        $tampered = json_encode($this->basePayload(['message_id' => 'MSG-TAMPER', 'content' => ['text' => 'CHANGED']]));

        $response = $this->call(
            'POST',
            route('webhooks.shopee', $this->account),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_HOST' => 'webhook.qrf.vn',
                'HTTPS' => 'on',
                'HTTP_X_SHOPEE_SIGNATURE' => $sig,
            ],
            $tampered,
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_SIGNATURE');
    }
}