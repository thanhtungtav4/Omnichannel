<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Channels\Jobs\SendChannelMessageJob;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Crm\Models\ContactNote;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Models\MessageAttachment;
use App\Modules\Routing\Models\AgentPresence;
use App\Modules\Routing\Services\AssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ConversationActionController extends Controller
{
    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorizeWorkspace($request, $conversation);
        $this->authorizeReply($request, $conversation);
        // Body optional when an image is attached; one of the two is required.
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'image' => ['nullable', 'image', 'max:10240'], // 10 MB
        ]);
        $hasImage = $request->hasFile('image');
        abort_if(! $hasImage && blank($data['body'] ?? null), 422, 'Cần nội dung hoặc ảnh.');

        $identity = ExternalIdentity::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('contact_id', $conversation->contact_id)
            ->where('provider_account_id', $conversation->channel_account_id)
            ->first();

        // Store the image under public disk; keep the absolute path for the
        // sidecar (zca-js needs a local file) and a public URL for Telegram/UI.
        $imageUrl = null;
        $imagePath = null;
        if ($hasImage) {
            $stored = $request->file('image')->store('outbound', 'public');
            $imagePath = storage_path('app/public/'.$stored);
            $imageUrl = asset('storage/'.$stored);
        }

        $message = Message::create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'channel_account_id' => $conversation->channel_account_id,
            'direction' => 'OUTBOUND',
            'sender_type' => 'AGENT',
            'sender_id' => (string) $request->user()->id,
            'body_text' => $data['body'] ?? '',
            'message_type' => $hasImage ? 'IMAGE' : 'TEXT',
            'status' => 'QUEUED',
        ]);

        if ($hasImage) {
            MessageAttachment::create([
                'workspace_id' => $conversation->workspace_id,
                'message_id' => $message->id,
                'mime_type' => $request->file('image')->getMimeType(),
                'size_bytes' => $request->file('image')->getSize(),
                'metadata' => ['url' => $imageUrl, 'type' => 'IMAGE'],
            ]);
        }

        // Group replies target the group thread; DMs target the individual.
        $recipient = $conversation->is_group
            ? $conversation->provider_thread_id
            : ($identity?->provider_chat_id ?: $identity?->provider_user_id);

        $outbox = OutboxMessage::create([
            'workspace_id' => $conversation->workspace_id,
            'channel_account_id' => $conversation->channel_account_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'provider' => $conversation->channelAccount->provider,
            'recipient_external_id' => $recipient,
            'payload' => [
                'text' => $data['body'] ?? '',
                'is_group' => (bool) $conversation->is_group,
                'image_url' => $imageUrl,   // public URL — Telegram sendPhoto, UI
                'image_path' => $imagePath, // local abs path — Zalo sidecar
            ],
            'status' => 'QUEUED',
            'next_attempt_at' => now(),
        ]);

        $conversation->forceFill([
            'status' => 'WAITING_CUSTOMER',
            'last_message_id' => $message->id,
            'last_message_at' => now(),
            'last_agent_message_at' => now(),
        ])->save();

        SendChannelMessageJob::dispatch($outbox->id);

        return back()->with('success', 'Reply queued for provider delivery.');
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

            if ($fresh->owner_id) {
                AgentPresence::query()
                    ->where('workspace_id', $fresh->workspace_id)
                    ->where('user_id', $fresh->owner_id)
                    ->where('active_conversation_count', '>', 0)
                    ->decrement('active_conversation_count');
            }
        });

        return back()->with('success', 'Conversation closed.');
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
}
