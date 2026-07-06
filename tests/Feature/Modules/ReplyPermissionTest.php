<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplyPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function setup2(): array
    {
        $ws = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
        $chan = ChannelAccount::create([
            'workspace_id' => $ws->id, 'provider' => 'TELEGRAM', 'name' => 'B',
            'status' => 'ACTIVE',
        ]);

        return [$ws, $chan];
    }

    private function conv(Workspace $ws, ChannelAccount $chan, ?User $owner): Conversation
    {
        return Conversation::create([
            'workspace_id' => $ws->id,
            'channel_account_id' => $chan->id,
            'owner_id' => $owner?->id,
            'status' => $owner ? 'ASSIGNED' : 'OPEN',
        ]);
    }

    public function test_agent_cannot_reply_conversation_owned_by_another(): void
    {
        [$ws, $chan] = $this->setup2();
        $agentA = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        $agentB = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        $conversation = $this->conv($ws, $chan, $agentA);

        $this->actingAs($agentB)
            ->post(route('admin.conversations.reply', $conversation), ['body' => 'chen ngang'])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_owner_agent_can_reply(): void
    {
        [$ws, $chan] = $this->setup2();
        $agentA = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        $conversation = $this->conv($ws, $chan, $agentA);

        $this->actingAs($agentA)
            ->post(route('admin.conversations.reply', $conversation), ['body' => 'da em ho tro'])
            ->assertRedirect();

        $this->assertDatabaseHas('messages', ['direction' => 'OUTBOUND', 'body_text' => 'da em ho tro']);
    }

    public function test_agent_can_reply_unassigned_conversation(): void
    {
        [$ws, $chan] = $this->setup2();
        $agent = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        $conversation = $this->conv($ws, $chan, null);

        $this->actingAs($agent)
            ->post(route('admin.conversations.reply', $conversation), ['body' => 'em nhan'])
            ->assertRedirect();
    }

    public function test_support_lead_can_reply_any_conversation(): void
    {
        [$ws, $chan] = $this->setup2();
        $agentA = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        $lead = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_lead', 'status' => 'ACTIVE']);
        $conversation = $this->conv($ws, $chan, $agentA);

        $this->actingAs($lead)
            ->post(route('admin.conversations.reply', $conversation), ['body' => 'lead vao'])
            ->assertRedirect();
    }
}
