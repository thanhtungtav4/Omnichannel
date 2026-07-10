<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Jobs\SendChannelMessageJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Models\MessageAttachment;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Multi-image reply fan-out.
 *
 * Contract:
 *   - Composer accepts images[] (max 9).
 *   - Each image produces ONE Message row + ONE MessageAttachment + ONE
 *     OutboxMessage + ONE queued SendChannelMessageJob.
 *   - The shared text body lands ONLY on the first image's caption; the
 *     remaining images ride caption-less so the customer's thread doesn't
 *     show N copies of the same message.
 *   - Conversation's last_message_id points to the LAST queued row (so the
 *     queue UI scrolls to the most recent outbound).
 *   - Legacy `image` (singular) field still works — normalised to array.
 */
class MultiImageReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_images_create_three_messages_and_three_jobs(): void
    {
        [, $agent, $zalo, $contact, $conversation] = $this->seedConversation('ZALO_OA');

        ExternalIdentity::create([
            'workspace_id' => $agent->workspace_id,
            'contact_id' => $contact->id,
            'provider' => 'ZALO_OA',
            'provider_account_id' => $zalo->id,
            'provider_user_id' => '8001',
            'provider_chat_id' => '9001',
            'display_name' => 'Zalo Customer',
            'last_seen_at' => now(),
        ]);

        Storage::fake('local');
        Bus::fake([SendChannelMessageJob::class]);

        $files = [
            File::image('a.jpg', 100, 100),
            File::image('b.jpg', 100, 100),
            File::image('c.jpg', 100, 100),
        ];

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), [
                'body' => 'Bảng giá 3 trang',
                'images' => $files,
            ])
            ->assertRedirect();

        // 3 messages, all IMAGE, all queued, all outbound
        $messages = Message::query()->where('direction', 'OUTBOUND')->get();
        $this->assertCount(3, $messages);
        foreach ($messages as $message) {
            $this->assertSame('IMAGE', $message->message_type);
            $this->assertSame('QUEUED', $message->status);
            $this->assertSame((string) $agent->id, $message->sender_id);
        }

        // Body only on first, empty on 2 & 3
        $this->assertSame('Bảng giá 3 trang', $messages[0]->body_text);
        $this->assertSame('', $messages[1]->body_text);
        $this->assertSame('', $messages[2]->body_text);

        // 3 attachments, 3 outbox rows
        $this->assertSame(3, MessageAttachment::query()->count());
        $this->assertSame(3, OutboxMessage::query()->count());

        // Each outbox row has its own image_url + image_path; no row
        // shares an attachment with another.
        $outboxes = OutboxMessage::query()->orderBy('created_at')->get();
        $urls = $outboxes->pluck('payload')->pluck('image_url')->all();
        $this->assertCount(3, array_unique($urls));

        // 3 jobs dispatched (one per image)
        Bus::assertDispatchedTimes(SendChannelMessageJob::class, 3);

        // Conversation pointer lands on the LAST queued message
        $conversation->refresh();
        $this->assertSame($messages->last()->id, $conversation->last_message_id);
        $this->assertSame('WAITING_CUSTOMER', $conversation->status);
    }

    public function test_nine_image_limit_is_enforced(): void
    {
        [, $agent, , , $conversation] = $this->seedConversation();

        $files = collect(range(1, 10))
            ->map(fn ($i) => File::image("img-{$i}.jpg", 10, 10))
            ->all();

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), [
                'body' => '10 ảnh',
                'images' => $files,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Message::query()->where('direction', 'OUTBOUND')->count());
        $this->assertSame(0, OutboxMessage::query()->count());
    }

    public function test_legacy_single_image_field_still_works(): void
    {
        [, $agent, $zalo, $contact, $conversation] = $this->seedConversation('ZALO_OA');

        ExternalIdentity::create([
            'workspace_id' => $agent->workspace_id,
            'contact_id' => $contact->id,
            'provider' => 'ZALO_OA',
            'provider_account_id' => $zalo->id,
            'provider_user_id' => '8001',
            'provider_chat_id' => '9001',
            'display_name' => 'Zalo Customer',
            'last_seen_at' => now(),
        ]);

        Storage::fake('local');
        Bus::fake([SendChannelMessageJob::class]);

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), [
                'body' => 'Một ảnh',
                'image' => File::image('solo.jpg', 50, 50),
            ])
            ->assertRedirect();

        $this->assertSame(1, Message::query()->where('direction', 'OUTBOUND')->count());
        $this->assertSame(1, OutboxMessage::query()->count());
        Bus::assertDispatchedTimes(SendChannelMessageJob::class, 1);
    }

    public function test_text_only_reply_skips_fanout_path(): void
    {
        [, $agent, $zalo, $contact, $conversation] = $this->seedConversation('ZALO_OA');

        ExternalIdentity::create([
            'workspace_id' => $agent->workspace_id,
            'contact_id' => $contact->id,
            'provider' => 'ZALO_OA',
            'provider_account_id' => $zalo->id,
            'provider_user_id' => '8001',
            'provider_chat_id' => '9001',
            'display_name' => 'Zalo Customer',
            'last_seen_at' => now(),
        ]);

        Bus::fake([SendChannelMessageJob::class]);

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), [
                'body' => 'Chỉ text',
            ])
            ->assertRedirect();

        $message = Message::query()->where('direction', 'OUTBOUND')->firstOrFail();
        $this->assertSame('TEXT', $message->message_type);
        $this->assertSame('Chỉ text', $message->body_text);

        $outbox = OutboxMessage::query()->firstOrFail();
        $this->assertArrayNotHasKey('image_url', $outbox->payload);
    }

    public function test_zalo_adapter_receives_one_image_per_outbound_call(): void
    {
        // The fan-out guarantee — the actual provider behavior is covered by
        // the provider-specific tests. This test asserts the SEAM: N images
        // in → N single-image outbox payloads out, never a single payload
        // carrying an array of images.
        [, $agent, $zalo, $contact, $conversation] = $this->seedConversation('ZALO_OA');

        ExternalIdentity::create([
            'workspace_id' => $agent->workspace_id,
            'contact_id' => $contact->id,
            'provider' => 'ZALO_OA',
            'provider_account_id' => $zalo->id,
            'provider_user_id' => '8001',
            'provider_chat_id' => '9001',
            'display_name' => 'Zalo Customer',
            'last_seen_at' => now(),
        ]);

        Storage::fake('local');
        Http::fake();

        $files = [
            File::image('a.jpg', 100, 100),
            File::image('b.jpg', 100, 100),
        ];

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), [
                'body' => 'Hai ảnh',
                'images' => $files,
            ])
            ->assertRedirect();

        $outboxes = OutboxMessage::query()->orderBy('created_at')->get();
        $this->assertCount(2, $outboxes);
        foreach ($outboxes as $outbox) {
            // Each payload has its OWN image_url — not an array of urls.
            $this->assertIsString($outbox->payload['image_url']);
            $this->assertArrayNotHasKey('images', $outbox->payload);
            $this->assertArrayNotHasKey('image_urls', $outbox->payload);
        }
    }

    /**
     * @return array{Workspace, User, ChannelAccount, Contact, Conversation}
     */
    public function test_outbound_image_uses_private_path_and_signed_route(): void
    {
        [$workspace, $agent, $telegram, $contact, $conversation] = $this->seedConversation('TELEGRAM');

        ExternalIdentity::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'provider' => 'TELEGRAM',
            'provider_account_id' => $telegram->id,
            'provider_user_id' => '5001',
            'provider_chat_id' => '5001',
            'display_name' => 'Customer',
            'last_seen_at' => now(),
        ]);

        Storage::fake('local');
        Bus::fake();

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), [
                'body' => 'here you go',
                'images' => [File::image('a.jpg', 40, 40)],
            ])
            ->assertRedirect();

        $attachment = MessageAttachment::query()->firstOrFail();

        // Stored privately: relative path, no public URL baked in.
        $this->assertArrayHasKey('path', $attachment->metadata);
        $this->assertArrayNotHasKey('url', $attachment->metadata);
        Storage::disk('local')->assertExists($attachment->metadata['path']);

        // A valid signed URL streams the file.
        $signed = URL::temporarySignedRoute('media.outbound', now()->addHour(), ['attachment' => $attachment->id]);
        $this->get($signed)->assertOk();

        // Same route without a signature is rejected.
        $this->get(route('media.outbound', ['attachment' => $attachment->id]))->assertForbidden();
    }

    private function seedConversation(string $provider = 'TELEGRAM'): array
    {
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'slug' => 'test',
            'status' => 'ACTIVE',
        ]);
        $agent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'display_name' => 'Agent Test',
            'role' => 'support_agent',
            'status' => 'ACTIVE',
        ]);
        $channel = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'provider' => $provider,
            'name' => "{$provider} Test",
            'status' => 'ACTIVE',
            'credentials' => ['bot_token' => 'test-token'],
            'webhook_secret' => 'test-secret',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'owner_id' => $agent->id,
            'full_name' => 'Test Customer',
            'status' => 'ACTIVE',
            'source' => $provider,
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channel->id,
            'contact_id' => $contact->id,
            'owner_id' => $agent->id,
            'status' => 'ASSIGNED',
            'priority' => 'NORMAL',
            'last_message_at' => now(),
        ]);

        return [$workspace, $agent, $channel, $contact, $conversation];
    }
}
