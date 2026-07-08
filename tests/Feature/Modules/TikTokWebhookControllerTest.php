<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\WebhookEvent;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TikTokWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'tiktok-shared-secret-abc';

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $workspace = Workspace::create([
            'slug' => 'tt-webhook',
            'name' => 'TT Webhook Test',
            'status' => 'ACTIVE',
        ]);

        $this->account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => 'TIKTOK_SHOP',
            'name' => 'TT Webhook Shop',
            'status' => 'ACTIVE',
            'credentials' => [
                'shop_id' => 'SHOP-001',
                'shop_cipher' => 'GCipA==',
                'access_token' => 'fake-access',
            ],
            'webhook_secret' => self::SECRET,
        ]);
    }

    private function signedPost(array $payload, ?Carbon $now = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $now ??= Carbon::now();
        $ts = $now->timestamp;
        $sig = hash_hmac('sha256', $ts.'.'.$body, self::SECRET);

        return $this->call(
            'POST',
            route('webhooks.tiktok-shop', $this->account),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_HOST' => 'webhook.qrf.vn',
                'HTTPS' => 'on',
                'HTTP_TIKTOK_SIGNATURE' => 't='.$ts.',s='.$sig,
            ],
            $body,
        );
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'event_type' => 'NEW_MESSAGE',
            'message_id' => 'MSG-TT-1',
            'conversation_id' => 'CONV-1',
            'shop_id' => 'SHOP-001',
            'message_type' => 'text',
            'sender' => [
                'open_id' => 'open-uid-1',
                'nickname' => 'Minh Anh',
            ],
            'content' => ['text' => 'Hello from TikTok'],
            'created_at' => 1735000000,
            'version' => 1,
        ], $overrides);
    }

    public function test_creates_conversation_and_message_for_text(): void
    {
        $response = $this->signedPost($this->basePayload());

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('duplicate', false);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', [
            'channel_account_id' => $this->account->id,
            'provider_message_id' => 'MSG-TT-1',
            'body_text' => 'Hello from TikTok',
            'message_type' => 'TEXT',
        ]);
        $this->assertDatabaseHas('webhook_events', [
            'channel_account_id' => $this->account->id,
            'event_type' => 'message',
            'status' => 'PROCESSED',
        ]);
    }

    public function test_returns_duplicate_for_repeated_payload(): void
    {
        $this->signedPost($this->basePayload())->assertStatus(200);

        $response = $this->signedPost($this->basePayload());

        $response->assertStatus(200);
        $response->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_edit_updates_existing_message_in_place(): void
    {
        $this->signedPost($this->basePayload())->assertStatus(200);

        $editPayload = $this->basePayload([
            'version' => 2,
            'content' => ['text' => 'edited text'],
        ]);
        $response = $this->signedPost($editPayload);

        $response->assertStatus(200);
        $response->assertJsonPath('edit', true);

        $this->assertDatabaseHas('messages', [
            'channel_account_id' => $this->account->id,
            'provider_message_id' => 'MSG-TT-1',
            'body_text' => 'edited text',
        ]);

        $this->assertDatabaseHas('webhook_events', [
            'channel_account_id' => $this->account->id,
            'event_type' => 'message_edit',
            'status' => 'PROCESSED',
        ]);
    }

    public function test_unsupported_message_type_persists_as_ignored(): void
    {
        $payload = $this->basePayload([
            'message_id' => 'MSG-TT-CARD',
            'message_type' => 'product_card',
            'content' => ['product_id' => 'X'],
        ]);

        $response = $this->signedPost($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('ignored', 'product_card');

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => 'unsupported',
            'status' => 'IGNORED',
        ]);
    }

    public function test_image_message_persists_attachment(): void
    {
        $payload = $this->basePayload([
            'message_id' => 'MSG-TT-IMG',
            'message_type' => 'image',
            'content' => [
                'image_url' => 'https://p16-sign-sg.tiktokcdn.com/abc.jpg',
                'caption' => 'look',
            ],
        ]);

        $response = $this->signedPost($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('duplicate', false);
        $this->assertDatabaseHas('messages', [
            'channel_account_id' => $this->account->id,
            'provider_message_id' => 'MSG-TT-IMG',
            'message_type' => 'IMAGE',
        ]);
        $msg = Message::where('provider_message_id', 'MSG-TT-IMG')->firstOrFail();
        $this->assertNotNull($msg->attachments->first());
        $this->assertSame(
            'https://p16-sign-sg.tiktokcdn.com/abc.jpg',
            $msg->attachments->first()->metadata['url'],
        );
    }

    public function test_rejects_missing_signature_header(): void
    {
        $body = json_encode($this->basePayload());

        $response = $this->withServerVariables(['HTTP_HOST' => 'webhook.qrf.vn', 'HTTPS' => 'on'])
            ->call(
                'POST',
                route('webhooks.tiktok-shop', $this->account),
                [], [], [],
                ['CONTENT_TYPE' => 'application/json'],
                $body,
            );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'MISSING_SIGNATURE');
    }

    public function test_rejects_stale_timestamp(): void
    {
        $body = json_encode($this->basePayload());
        $staleTs = Carbon::now()->subSeconds(600)->timestamp;
        $sig = hash_hmac('sha256', $staleTs.'.'.$body, self::SECRET);

        $response = $this->call(
            'POST',
            route('webhooks.tiktok-shop', $this->account),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_HOST' => 'webhook.qrf.vn',
                'HTTPS' => 'on',
                'HTTP_TIKTOK_SIGNATURE' => 't='.$staleTs.',s='.$sig,
            ],
            $body,
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'STALE_SIGNATURE');
    }

    public function test_rejects_tampered_body(): void
    {
        $original = json_encode($this->basePayload(['message_id' => 'MSG-TAMPER']));
        $ts = Carbon::now()->timestamp;
        $sig = hash_hmac('sha256', $ts.'.'.$original, self::SECRET);

        $tampered = json_encode($this->basePayload([
            'message_id' => 'MSG-TAMPER',
            'content' => ['text' => 'CHANGED'],
        ]));

        $response = $this->call(
            'POST',
            route('webhooks.tiktok-shop', $this->account),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_HOST' => 'webhook.qrf.vn',
                'HTTPS' => 'on',
                'HTTP_TIKTOK_SIGNATURE' => 't='.$ts.',s='.$sig,
            ],
            $tampered,
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_SIGNATURE');
    }

    public function test_ignores_non_new_message_envelope(): void
    {
        $payload = $this->basePayload([
            'event_type' => 'CONVERSATION_UPDATE',
            'message_id' => 'READ-1',
            'message_type' => 'read_receipt',
            'content' => ['read_at' => 1735000000],
        ]);

        $response = $this->signedPost($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('ignored', 'CONVERSATION_UPDATE');
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => 'envelope',
            'status' => 'IGNORED',
        ]);
    }
}