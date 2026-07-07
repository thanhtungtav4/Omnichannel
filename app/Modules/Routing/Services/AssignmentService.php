<?php

namespace App\Modules\Routing\Services;

use App\Models\User;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Routing\Models\AgentPresence;
use App\Modules\Routing\Models\AssignmentAttempt;
use App\Modules\Routing\Models\RoutingQueue;
use App\Modules\Routing\Models\RoutingQueueMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function assign(Conversation $conversation, ?User $preferredOwner = null, string $reason = 'AUTO_STICKY_OWNER'): ?User
    {
        return DB::transaction(function () use ($conversation, $preferredOwner, $reason) {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $queue = RoutingQueue::query()
                ->where('workspace_id', $conversation->workspace_id)
                ->where('status', 'ACTIVE')
                ->with(['members.user'])
                ->first();

            if (! $queue) {
                return null;
            }

            $assignee = null;

            if ($preferredOwner && $this->isEligible($preferredOwner, $queue)) {
                $assignee = $preferredOwner;
            }

            if (! $assignee) {
                $assignee = $this->nextEligibleQueueMember($queue);
                $reason = $queue->mode === 'QUEUE_ORDER' ? 'AUTO_QUEUE_ORDER' : 'AUTO_EVEN';
            }

            if (! $assignee) {
                AssignmentAttempt::create([
                    'workspace_id' => $conversation->workspace_id,
                    'conversation_id' => $conversation->id,
                    'routing_queue_id' => $queue->id,
                    'result' => 'FAILED',
                    'reason' => 'NO_ELIGIBLE_AGENT',
                    'created_at' => now(),
                ]);

                $conversation->forceFill([
                    'routing_queue_id' => $queue->id,
                    'status' => 'WAITING_AGENT',
                ])->save();

                return null;
            }

            DB::table('conversation_assignments')->insert([
                'id' => (string) str()->uuid(),
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'from_user_id' => $conversation->owner_id,
                'to_user_id' => $assignee->id,
                'routing_queue_id' => $queue->id,
                'reason' => $reason,
                'metadata' => json_encode(['mode' => $queue->mode]),
                'created_at' => now(),
            ]);

            AssignmentAttempt::create([
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'routing_queue_id' => $queue->id,
                'candidate_user_id' => $assignee->id,
                'result' => 'ASSIGNED',
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $conversation->forceFill([
                'owner_id' => $assignee->id,
                'routing_queue_id' => $queue->id,
                'status' => 'ASSIGNED',
            ])->save();

            RoutingQueueMember::query()
                ->where('routing_queue_id', $queue->id)
                ->where('user_id', $assignee->id)
                ->update(['last_assigned_at' => Carbon::now()]);

            AgentPresence::query()
                ->where('workspace_id', $conversation->workspace_id)
                ->where('user_id', $assignee->id)
                ->increment('active_conversation_count');

            return $assignee;
        });
    }

    /**
     * An agent claims an unassigned conversation by replying to it. No queue
     * eligibility check — reply permission was already verified upstream, and
     * a manual pickup should never be blocked by max-active caps. If someone
     * else claimed it first (owner_id set), we leave their ownership intact.
     */
    public function claim(Conversation $conversation, User $agent): User
    {
        return DB::transaction(function () use ($conversation, $agent) {
            $fresh = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

            if ($fresh->owner_id !== null) {
                return $fresh->owner; // already claimed by someone else
            }

            DB::table('conversation_assignments')->insert([
                'id' => (string) str()->uuid(),
                'workspace_id' => $fresh->workspace_id,
                'conversation_id' => $fresh->id,
                'from_user_id' => null,
                'to_user_id' => $agent->id,
                'routing_queue_id' => $fresh->routing_queue_id,
                'reason' => 'MANUAL_CLAIM',
                'metadata' => json_encode([]),
                'created_at' => now(),
            ]);

            $fresh->forceFill([
                'owner_id' => $agent->id,
                'status' => 'ASSIGNED',
            ])->save();

            AgentPresence::query()
                ->where('workspace_id', $fresh->workspace_id)
                ->where('user_id', $agent->id)
                ->increment('active_conversation_count');

            return $agent;
        });
    }

    public function transfer(Conversation $conversation, User $target, ?User $actor = null): User
    {
        return DB::transaction(function () use ($conversation, $target, $actor) {
            // Lock so concurrent transfers don't race on owner_id / counters.
            $fresh = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $fromOwnerId = $fresh->owner_id;

            if ((int) $fromOwnerId === (int) $target->id) {
                return $target; // already owned by target, no-op
            }

            DB::table('conversation_assignments')->insert([
                'id' => (string) str()->uuid(),
                'workspace_id' => $fresh->workspace_id,
                'conversation_id' => $fresh->id,
                'from_user_id' => $fromOwnerId,
                'to_user_id' => $target->id,
                'routing_queue_id' => $fresh->routing_queue_id,
                'reason' => 'MANUAL_TRANSFER',
                'metadata' => json_encode(['actor_id' => $actor?->id]),
                'created_at' => now(),
            ]);

            $fresh->forceFill([
                'owner_id' => $target->id,
                'status' => 'ASSIGNED',
            ])->save();

            // Move the active-conversation counter from old owner to new.
            if ($fromOwnerId) {
                AgentPresence::query()
                    ->where('workspace_id', $fresh->workspace_id)
                    ->where('user_id', $fromOwnerId)
                    ->where('active_conversation_count', '>', 0)
                    ->decrement('active_conversation_count');
            }
            AgentPresence::query()
                ->where('workspace_id', $fresh->workspace_id)
                ->where('user_id', $target->id)
                ->increment('active_conversation_count');

            return $target;
        });
    }

    private function nextEligibleQueueMember(RoutingQueue $queue): ?User
    {
        return $queue->members
            ->filter(fn (RoutingQueueMember $member) => $member->status === 'ACTIVE' && $this->isEligible($member->user, $queue))
            ->sortBy(fn (RoutingQueueMember $member) => sprintf(
                '%012d-%06d',
                $member->last_assigned_at?->timestamp ?? 0,
                $member->sort_order,
            ))
            ->first()
            ?->user;
    }

    private function isEligible(User $user, RoutingQueue $queue): bool
    {
        if ($user->status !== 'ACTIVE') {
            return false;
        }

        $presence = AgentPresence::query()
            ->where('workspace_id', $queue->workspace_id)
            ->where('user_id', $user->id)
            ->first();

        if ($queue->requires_online && $presence?->status !== 'ONLINE') {
            return false;
        }

        return ($presence?->active_conversation_count ?? 0) < $queue->max_active_per_agent;
    }
}
