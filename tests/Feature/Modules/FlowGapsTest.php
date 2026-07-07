<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Services\ConversationSlaMonitor;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Routing\Models\AgentPresence;
use App\Modules\Routing\Models\RoutingQueue;
use App\Modules\Routing\Models\RoutingQueueMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowGapsTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): array
    {
        $ws = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
        $chan = ChannelAccount::create([
            'workspace_id' => $ws->id, 'provider' => 'TELEGRAM', 'name' => 'B', 'status' => 'ACTIVE',
        ]);

        return [$ws, $chan];
    }

    /** #2 replying to an unassigned conversation claims ownership + bumps presence. */
    public function test_reply_to_unassigned_claims_ownership(): void
    {
        [$ws, $chan] = $this->scaffold();
        $agent = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        AgentPresence::create(['workspace_id' => $ws->id, 'user_id' => $agent->id, 'status' => 'ONLINE', 'active_conversation_count' => 0]);

        $conversation = Conversation::create([
            'workspace_id' => $ws->id, 'channel_account_id' => $chan->id, 'owner_id' => null, 'status' => 'OPEN',
        ]);

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), ['body' => 'em nhan'])
            ->assertRedirect();

        // Ownership is the point of #2 (the send itself fails in the test env
        // with no real provider, which #8 then flips back to WAITING_AGENT).
        $conversation->refresh();
        $this->assertSame($agent->id, $conversation->owner_id);
        $this->assertSame(1, AgentPresence::where('user_id', $agent->id)->value('active_conversation_count'));
        $this->assertDatabaseHas('conversation_assignments', [
            'conversation_id' => $conversation->id, 'to_user_id' => $agent->id, 'reason' => 'MANUAL_CLAIM',
        ]);
    }

    /** #3 SLA sweep flags overdue conversations once, skips closed/waiting-customer. */
    public function test_sla_sweep_flags_overdue_conversations(): void
    {
        [$ws, $chan] = $this->scaffold();

        $overdue = Conversation::create([
            'workspace_id' => $ws->id, 'channel_account_id' => $chan->id, 'status' => 'WAITING_AGENT',
            'next_response_due_at' => now()->subMinute(),
        ]);
        $closed = Conversation::create([
            'workspace_id' => $ws->id, 'channel_account_id' => $chan->id, 'status' => 'CLOSED',
            'next_response_due_at' => now()->subMinute(),
        ]);

        app(ConversationSlaMonitor::class)->sweep();

        $this->assertNotNull($overdue->fresh()->sla_breached_at);
        $this->assertNull($closed->fresh()->sla_breached_at);
    }

    /** #6 SLA sweep re-assigns a conversation stuck WAITING_AGENT once an agent is available. */
    public function test_sla_sweep_reassigns_stuck_conversation(): void
    {
        [$ws, $chan] = $this->scaffold();
        $agent = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        AgentPresence::create(['workspace_id' => $ws->id, 'user_id' => $agent->id, 'status' => 'ONLINE', 'active_conversation_count' => 0]);

        $queue = RoutingQueue::create([
            'workspace_id' => $ws->id, 'name' => 'Q', 'status' => 'ACTIVE', 'mode' => 'QUEUE_ORDER',
            'requires_online' => true, 'max_active_per_agent' => 5,
        ]);
        RoutingQueueMember::create([
            'workspace_id' => $ws->id, 'routing_queue_id' => $queue->id, 'user_id' => $agent->id,
            'status' => 'ACTIVE', 'sort_order' => 1,
        ]);

        $stuck = Conversation::create([
            'workspace_id' => $ws->id, 'channel_account_id' => $chan->id, 'owner_id' => null,
            'status' => 'WAITING_AGENT', 'last_message_at' => now(),
        ]);

        app(ConversationSlaMonitor::class)->sweep();

        $this->assertSame($agent->id, $stuck->fresh()->owner_id);
    }

    /** #5 closing a conversation cancels replies still queued to the provider. */
    public function test_close_cancels_pending_outbox(): void
    {
        [$ws, $chan] = $this->scaffold();
        $agent = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_lead', 'status' => 'ACTIVE']);

        $conversation = Conversation::create([
            'workspace_id' => $ws->id, 'channel_account_id' => $chan->id, 'owner_id' => $agent->id, 'status' => 'ASSIGNED',
        ]);
        $message = \App\Modules\Inbox\Models\Message::create([
            'workspace_id' => $ws->id, 'conversation_id' => $conversation->id, 'channel_account_id' => $chan->id,
            'direction' => 'OUTBOUND', 'sender_type' => 'AGENT', 'body_text' => 'hi', 'message_type' => 'TEXT', 'status' => 'QUEUED',
        ]);
        $outbox = OutboxMessage::create([
            'workspace_id' => $ws->id, 'channel_account_id' => $chan->id, 'conversation_id' => $conversation->id,
            'message_id' => $message->id, 'provider' => 'TELEGRAM', 'recipient_external_id' => '123',
            'payload' => ['text' => 'hi'], 'status' => 'QUEUED', 'next_attempt_at' => now(),
        ]);

        $this->actingAs($agent)
            ->post(route('admin.conversations.close', $conversation), [])
            ->assertRedirect();

        $this->assertSame('CANCELLED', $outbox->fresh()->status);
        $this->assertSame('CLOSED', $conversation->fresh()->status);
    }

    /** Agent can manually reopen a closed conversation. */
    public function test_agent_reopens_closed_conversation(): void
    {
        [$ws, $chan] = $this->scaffold();
        $agent = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_lead', 'status' => 'ACTIVE']);
        $conversation = Conversation::create([
            'workspace_id' => $ws->id, 'channel_account_id' => $chan->id, 'owner_id' => $agent->id,
            'status' => 'CLOSED', 'closed_at' => now(),
        ]);

        $this->actingAs($agent)
            ->post(route('admin.conversations.reopen', $conversation), [])
            ->assertRedirect();

        $fresh = $conversation->fresh();
        $this->assertSame('WAITING_AGENT', $fresh->status);
        $this->assertNull($fresh->closed_at);
    }
}
