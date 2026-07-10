<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Channels\Jobs\SendChannelMessageJob;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Crm\Models\ContactNote;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Models\MessageAttachment;
use App\Modules\Routing\Services\AssignmentService;
use App\Modules\Routing\Services\PresenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ConversationActionController extends Controller
{
    public function __construct(private readonly PresenceService $presence) {}

    /**
     * Max images per reply. 9 keeps total payload comfortably under the 100M
     * nginx cap with worst-case 10 MB per file, and matches Telegram's
     * sendMediaGroup limit (10) while leaving headroom.
     */
    private const MAX_REPLY_IMAGES = 9;

    public function reply(Request $request, Conversation $conversation, AssignmentService $assignmentService): RedirectResponse
    {
        $this->authorizeWorkspace($request, $conversation);
        $this->authorizeReply($request, $conversation);

        // Picking up an unassigned conversation: the replying agent claims it.
        // Without this the conversation stays owner-less, so a second agent can
        // reply to the same customer and the presence/queue counters never move.
        if ($conversation->owner_id === null) {
            $assignmentService->claim($conversation, $request->user());
            $conversation->refresh();
        }
        // Body optional when at least one image is attached; one of the two is required.
        // Two input shapes accepted:
        //   - images[] (array, multi-file picker — the current composer)
        //   - image (single — legacy / 3rd-party callers still on the old shape)
        // We validate BOTH independently instead of trying to normalise via
        // $request->merge(), because merge() only writes to the input bag —
        // $request->file('images') would still return empty since the file
        // bag is separate.
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'images' => ['nullable', 'array', 'max:'.self::MAX_REPLY_IMAGES],
            'images.*' => ['image', 'max:10240'], // 10 MB per file
            'image' => ['nullable', 'image', 'max:10240'], // legacy single-file
        ]);
        $imageFiles = $this->collectUploadedImages($request);
        abort_if(
            $imageFiles === [] && blank($data['body'] ?? null),
            422,
            'Cần nội dung hoặc ảnh.',
        );

        $identity = ExternalIdentity::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('contact_id', $conversation->contact_id)
            ->where('provider_account_id', $conversation->channel_account_id)
            ->first();

        // Group replies target the group thread; DMs target the individual.
        $recipient = $conversation->is_group
            ? $conversation->provider_thread_id
            : ($identity?->provider_chat_id ?: $identity?->provider_user_id);

        // -----------------------------------------------------------------
        // Fan-out: one image = one Message row + one OutboxMessage row + one
        // queued job. Telegram/Zalo providers all build payloads from a single
        // OutboxMessage, so per-image rows keep provider adapters unchanged
        // (Zalo OA sends each photo as its own bubble — exactly what customers
        // expect). The shared text (body) goes on the FIRST image only; the
        // remaining images ride as caption-less media so they don't duplicate
        // the message. The InboxConversation's `last_message_*` pointers are
        // set to the LAST queued row so the queue/UI scrolls to the most recent
        // outbound.
        // -----------------------------------------------------------------
        $body = $data['body'] ?? '';
        $lastMessageId = null;
        $queuedOutboxIds = [];

        // Persist uploads to disk BEFORE the DB transaction. Writing files
        // inside the transaction leaks them on rollback (the DB unwinds, the
        // disk doesn't). We stage them here, keep the relative paths, and clean
        // up on any failure below.
        $storedImages = [];
        foreach ($imageFiles as $index => $file) {
            $relative = $file->store('outbound/'.now()->format('Y/m/d'), 'public');
            $storedImages[] = [
                'relative' => $relative,
                'url' => asset('storage/'.$relative),
                'path' => storage_path('app/public/'.$relative),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'caption' => $index === 0 ? $body : '',
            ];
        }

        try {
            DB::transaction(function () use (
                $conversation,
                $request,
                $storedImages,
                $body,
                $recipient,
                &$lastMessageId,
                &$queuedOutboxIds,
            ) {
                $totalImages = count($storedImages);
                $provider = $conversation->channelAccount->provider;

                // If there are no images, keep the legacy single-message path so
                // existing tests/contracts for text-only replies stay intact.
                if ($totalImages === 0) {
                    $message = Message::create([
                        'workspace_id' => $conversation->workspace_id,
                        'conversation_id' => $conversation->id,
                        'channel_account_id' => $conversation->channel_account_id,
                        'direction' => 'OUTBOUND',
                        'sender_type' => 'AGENT',
                        'sender_id' => (string) $request->user()->id,
                        'body_text' => $body,
                        'message_type' => 'TEXT',
                        'status' => 'QUEUED',
                    ]);
                    $outbox = OutboxMessage::create([
                        'workspace_id' => $conversation->workspace_id,
                        'channel_account_id' => $conversation->channel_account_id,
                        'conversation_id' => $conversation->id,
                        'message_id' => $message->id,
                        'provider' => $provider,
                        'recipient_external_id' => $recipient,
                        'payload' => [
                            'text' => $body,
                            'is_group' => (bool) $conversation->is_group,
                            'provider_thread_id' => $conversation->provider_thread_id,
                        ],
                        'status' => 'QUEUED',
                        'next_attempt_at' => now(),
                    ]);
                    $lastMessageId = $message->id;
                    $queuedOutboxIds[] = $outbox->id;

                    return;
                }

                foreach ($storedImages as $stored) {
                    $imageUrl = $stored['url'];
                    $imagePath = $stored['path'];
                    $caption = $stored['caption'];

                    $message = Message::create([
                        'workspace_id' => $conversation->workspace_id,
                        'conversation_id' => $conversation->id,
                        'channel_account_id' => $conversation->channel_account_id,
                        'direction' => 'OUTBOUND',
                        'sender_type' => 'AGENT',
                        'sender_id' => (string) $request->user()->id,
                        'body_text' => $caption,
                        'message_type' => 'IMAGE',
                        'status' => 'QUEUED',
                    ]);

                    MessageAttachment::create([
                        'workspace_id' => $conversation->workspace_id,
                        'message_id' => $message->id,
                        'mime_type' => $stored['mime'],
                        'size_bytes' => $stored['size'],
                        'metadata' => ['url' => $imageUrl, 'type' => 'IMAGE'],
                    ]);

                    $outbox = OutboxMessage::create([
                        'workspace_id' => $conversation->workspace_id,
                        'channel_account_id' => $conversation->channel_account_id,
                        'conversation_id' => $conversation->id,
                        'message_id' => $message->id,
                        'provider' => $provider,
                        'recipient_external_id' => $recipient,
                        'payload' => [
                            'text' => $caption,
                            'is_group' => (bool) $conversation->is_group,
                            'provider_thread_id' => $conversation->provider_thread_id,
                            'image_url' => $imageUrl,   // public URL — Telegram sendPhoto, UI
                            'image_path' => $imagePath, // local abs path — Zalo sidecar
                        ],
                        'status' => 'QUEUED',
                        'next_attempt_at' => now(),
                    ]);

                    $lastMessageId = $message->id;
                    $queuedOutboxIds[] = $outbox->id;
                }
            });
        } catch (\Throwable $e) {
            // DB rolled back — drop the staged files so they don't linger.
            foreach ($storedImages as $stored) {
                Storage::disk('public')->delete($stored['relative']);
            }

            throw $e;
        }

        $conversation->forceFill([
            'status' => 'WAITING_CUSTOMER',
            'last_message_id' => $lastMessageId,
            'last_message_at' => now(),
            'last_agent_message_at' => now(),
        ])->save();

        foreach ($queuedOutboxIds as $outboxId) {
            SendChannelMessageJob::dispatch($outboxId);
        }

        $count = count($queuedOutboxIds);
        $success = $count > 1
            ? "Đã queue {$count} tin (ảnh gửi riêng từng cái)."
            : 'Reply queued for provider delivery.';

        return back()->with('success', $success);
    }

    /**
     * Note about the customer (HubSpot "Comment"): NEVER sent to the customer.
     * There is ONE note store — contact_notes. A note typed from a conversation
     * keeps conversation_id so it shows inline in that thread, and also appears
     * on the contact record (all of the customer's notes in one place). Any
     * workspace agent can note — it's collaboration, not a customer reply.
     */
    public function comment(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeWorkspace($request, $conversation);
        abort_unless($conversation->contact_id, 422, 'Hội thoại chưa gắn khách để lưu ghi chú.');
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        ContactNote::create([
            'workspace_id' => $conversation->workspace_id,
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->id,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
            'pinned' => (bool) ($data['pinned'] ?? false),
        ]);

        return back()->with('success', 'Đã thêm ghi chú.');
    }

    public function transfer(Request $request, Conversation $conversation, AssignmentService $assignmentService): RedirectResponse
    {
        $this->authorizeWorkspace($request, $conversation);
        $this->authorizeReply($request, $conversation); // only owner/lead/admin transfer
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $target = User::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->findOrFail($data['user_id']);

        $assignmentService->transfer($conversation, $target, $request->user());

        return back()->with('success', 'Conversation transferred.');
    }

    public function close(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeWorkspace($request, $conversation);
        $this->authorizeReply($request, $conversation); // same rule: only owner/lead/admin close
        $request->validate(['reason' => ['nullable', 'string', 'max:120']]);

        // Lock so two agents closing the same conversation don't double-decrement
        // the presence counter.
        DB::transaction(function () use ($conversation) {
            $fresh = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($fresh->status === 'CLOSED') {
                return; // already closed by someone else
            }
            $fresh->forceFill(['status' => 'CLOSED', 'closed_at' => now()])->save();

            // Cancel replies still waiting to be sent so we don't message a
            // customer after closing. Only not-yet-picked-up outbox rows; a
            // SENDING row is already at the provider and must run to completion.
            OutboxMessage::query()
                ->where('conversation_id', $fresh->id)
                ->whereIn('status', ['QUEUED', 'RETRYING'])
                ->update(['status' => 'CANCELLED', 'next_attempt_at' => null]);

            if ($fresh->owner_id) {
                $this->presence->conversationReleased($fresh->workspace_id, $fresh->owner_id);
            }
        });

        return back()->with('success', 'Conversation closed.');
    }

    public function reopen(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeWorkspace($request, $conversation);
        $this->authorizeReply($request, $conversation);

        DB::transaction(function () use ($conversation) {
            $fresh = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($fresh->status !== 'CLOSED') {
                return; // already open
            }
            // Reopen as needing an agent; restore the owner's workload counter
            // that close() decremented.
            $fresh->forceFill(['status' => 'WAITING_AGENT', 'closed_at' => null])->save();
            if ($fresh->owner_id) {
                $this->presence->conversationAssigned($fresh->workspace_id, $fresh->owner_id);
            }
        });

        return back()->with('success', 'Đã mở lại hội thoại.');
    }

    private function authorizeWorkspace(Request $request, Conversation $conversation): void
    {
        abort_unless((string) $conversation->workspace_id === (string) $request->user()->workspace_id, 403);
    }

    /**
     * Reply permission (spec 04, spec 08): a support_agent/sales may reply only
     * to conversations assigned to them; owner/admin/support_lead may reply to
     * any conversation. Unassigned conversations are open to any authorized
     * agent (whoever replies first effectively picks it up). Deny by default.
     */
    private function authorizeReply(Request $request, Conversation $conversation): void
    {
        $user = $request->user();

        // Elevated roles reply to anything.
        if (in_array($user->role, ['owner', 'admin', 'support_lead'], true)) {
            return;
        }

        // Agents/sales: own it, or it's unassigned (picking it up).
        $isOwner = $conversation->owner_id !== null
            && (int) $conversation->owner_id === (int) $user->id;
        $isUnassigned = $conversation->owner_id === null;

        abort_unless($isOwner || $isUnassigned, 403, 'Hội thoại này do người khác phụ trách.');
    }

    /**
     * Read uploaded images from either the multi-file (`images[]`) or legacy
     * single-file (`image`) input. Wrapping in a method gives phpstan a clear
     * `array<int, UploadedFile>` return type so the call site doesn't have
     * to fight the ternary narrowing.
     *
     * @return array<int, UploadedFile>
     */
    private function collectUploadedImages(Request $request): array
    {
        if ($request->hasFile('images')) {
            /** @var array<int, UploadedFile> $files */
            $files = $request->file('images');

            return array_values($files);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');

            return $file instanceof UploadedFile ? [$file] : [];
        }

        return [];
    }
}
