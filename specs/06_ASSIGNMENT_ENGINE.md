# 06 Assignment Engine Spec

## Goal

Automatically route customer conversations to the right support agent while keeping workload balanced, preserving customer familiarity, and making all assignment decisions explainable.

## Inspiration From Bitrix24

The v1 engine should support the useful Bitrix-like patterns:

- Even distribution by assigning to the agent who has waited longest.
- Queue-order distribution where the first eligible agent gets the chat, then timeout moves to the next.
- Broadcast mode where all eligible agents can see and claim the chat.
- Check agent availability before routing.
- Limit active conversations per agent.
- Route returning customers to the responsible CRM owner when possible.
- Return unassigned conversations to queue.

## Routing Modes

### STICKY_THEN_EVEN

Default v1 mode.

1. If contact/lead/deal has responsible owner, try that owner first.
2. Owner must be active, available, in queue, and under workload limit.
3. If owner not eligible, assign by `EVEN`.

### EVEN

Assign to the eligible queue member with the oldest `last_assigned_at`.

Tie breakers:

1. Lower active conversation count.
2. Lower queue sort order.
3. Oldest membership creation.

### QUEUE_ORDER

Try queue members by `sort_order`.

- If selected agent does not reply within `timeout_seconds`, reassign to next eligible agent.
- Store each skipped/timed-out candidate in `assignment_attempts`.

### BROADCAST

- Conversation is visible to every eligible queue member.
- First user to click "Assign to me" becomes owner.
- Broadcast mode still respects permissions and active workload limits.

## Eligibility Rules

Agent is eligible when:

- User status is `ACTIVE`.
- User is a queue member with status `ACTIVE`.
- User has `inbox.handle_assigned`.
- Presence is `ONLINE` unless queue `requires_online` is false.
- Active assigned conversations count is below `max_active_per_agent`.
- User is not explicitly excluded by routing rule.

## Assignment State

Assignment writes:

- `conversations.owner_id`
- `conversations.routing_queue_id`
- `conversation_assignments`
- `assignment_attempts`
- `audit_logs`
- `timeline_activities`

Never silently overwrite owner. Every owner change needs assignment history.

## Reassignment Rules

- Timeout reassign runs by scheduled job every minute.
- If no eligible agent exists, conversation stays `WAITING_AGENT` with `owner_id = null`.
- If prior owner becomes unavailable before first response, return conversation to queue.
- If agent has replied and then goes offline, keep assignment until customer replies or support lead transfers.
- Manual transfer overrides automatic assignment and records reason.

## SLA Rules

MVP SLA fields:

- `first_response_due_at`: set when first inbound customer message opens conversation.
- `next_response_due_at`: set when latest customer message arrives.
- `first_response_at`: first outbound agent message.
- `sla_state`: computed as `OK`, `DUE_SOON`, `BREACHED`.

Default targets:

- First response: 5 minutes.
- Next response: 10 minutes.

Queue-specific SLA settings can override defaults.

## Admin Controls

Routing settings screen:

- Queue list.
- Queue mode.
- Timeout seconds.
- Max active conversations per agent.
- Requires online toggle.
- Queue members reorder.
- Pause/resume member.
- View assignment attempts.

Support lead actions:

- Assign to self.
- Assign to another user.
- Transfer queue.
- Force close.
- Mark spam.

## Edge Cases

- Two jobs assign same conversation: use DB transaction and row lock on conversation.
- Agent hits workload limit between candidate selection and write: re-check inside transaction.
- Contact owner not queue member: fall back to queue mode.
- Customer sends new message during transfer: preserve transfer but update unread/SLA.
- Broadcast claim race: first transaction wins; other claim gets conflict response.

## Acceptance Criteria

- Returning customer routes to responsible owner when eligible.
- New customer routes by even distribution in default queue.
- Offline or overloaded agents are skipped.
- Timeout creates a reassignment attempt and moves to next eligible agent.
- Manual transfer is audited.
- Admin cockpit shows unassigned conversations and overloaded queues.
