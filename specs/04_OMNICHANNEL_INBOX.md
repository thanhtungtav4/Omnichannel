# 04 Omnichannel Inbox Spec

## Goal

Give support staff one focused place to handle Zalo and Telegram conversations, understand the customer context, and connect the conversation to CRM lead/deal work.

## Primary Users

- Support agent: handles assigned conversations and replies.
- Support lead: monitors queues, transfers conversations, resolves overload.
- Sales user: views linked customer conversation history from contact/lead/deal.
- Admin: configures channels, routing, and recovery actions.

## Conversation Lifecycle

```text
OPEN
-> ASSIGNED
-> WAITING_AGENT
-> WAITING_CUSTOMER
-> CLOSED
```

Special statuses:

- `SPAM`: hidden from normal queue, retained for audit.
- `REOPENED`: represented by `OPEN` with a timeline activity; do not add a separate status in v1.

Rules:

- Inbound customer message opens or reopens a conversation.
- If assigned agent has not replied yet, status is `WAITING_AGENT`.
- After agent replies, status becomes `WAITING_CUSTOMER`.
- Closing requires reason: `RESOLVED`, `DUPLICATE`, `SPAM`, `NO_RESPONSE`, `OTHER`.
- Closed conversation reopens when customer sends a new message within the reopen window.

## Inbox Screens

### Inbox List

Filters:

- Queue
- Channel
- Status
- Assignee
- Priority
- SLA state
- Unread only
- Has lead/deal
- Search customer name, phone, email, provider username

Columns/cards:

- Customer
- Last message preview
- Channel badge
- Assignee avatar
- Linked lead/deal
- SLA badge
- Last message time
- Unread count

Actions:

- Open conversation
- Assign to me
- Transfer
- Close
- Mark spam

### Conversation Thread

Required panels:

- Message timeline with inbound/outbound/internal note separation.
- Reply composer.
- Internal note composer.
- Customer profile side panel.
- Linked lead/deal panel.
- Assignment and SLA panel.
- Provider delivery state.

Message states:

- Inbound: `RECEIVED`.
- Outbound: `QUEUED`, `SENDING`, `SENT`, `FAILED`, `DELIVERED`, `READ`.
- Unsupported provider content shows an `UNSUPPORTED` message type with raw metadata available to admins.

### Customer Side Panel

Shows:

- Contact name, phone, email, source identities.
- Owner.
- Last inbound/outbound times.
- Active lead/deal.
- Timeline summary.
- Tags.
- Quick actions: edit contact, create/link lead, create/link deal, transfer owner.

## CRM Linking Rules

- Every conversation should link to exactly one primary contact when identity is known.
- A conversation may link to zero or one active lead by default.
- A conversation may link to zero or more deals, but UI highlights one primary deal.
- If no contact match exists, CRM Core creates a contact with source provider identity.
- If no active lead exists, v1 creates a new lead when the first customer message is accepted into Inbox.

## Reply Rules

- Agent can reply only if the conversation is assigned to them unless they have `inbox.reply_any`.
- Support lead/admin can reply to any conversation.
- Reply creates a local message and an outbox record in one transaction.
- UI shows queued state immediately.
- Provider send happens asynchronously.
- Failed outbound message can be retried by users with `inbox.retry_outbound`.

## Internal Notes

- Internal notes never go to providers.
- Notes are visible to users with access to the conversation.
- Notes appear in timeline and audit logs.

## Realtime Behavior

- New inbound message moves conversation to top of list.
- Conversation thread appends message without page refresh.
- Assignment changes update list ownership and side panel.
- SLA breach updates badges in list and cockpit.
- Failed outbound send shows toast and inline status.

## Edge Cases

- Duplicate inbound provider event: ignore duplicate message, mark webhook event `IGNORED`.
- Customer sends multiple messages quickly: preserve order by provider timestamp and local received time.
- Agent sends reply while conversation is being transferred: allow only if user still has reply permission at send time.
- Provider send fails after UI queued state: mark message `FAILED`, keep retry action visible.
- Contact merge later: entity links and conversation `contact_id` must be updated by CRM Core merge service.

## shadcn Components

Use:

- `Sidebar` for admin navigation.
- `Resizable` for inbox list/thread/customer panel.
- `ScrollArea` for conversation history.
- `Card` for side-panel groups.
- `Badge` for channel/status/SLA.
- `Avatar` with fallback for customers/agents.
- `Textarea` and `InputGroup` for composer.
- `Button` with icon `data-icon`.
- `Tabs` for customer details: profile, timeline, lead/deal.
- `Dialog` or `Sheet` for transfer/close/retry actions.
- `Empty` for no conversations.
- `Skeleton` for loading.
- `Sonner` for send/retry notifications.

## Acceptance Criteria

- Agent can handle a full conversation without leaving Inbox.
- Customer context is visible beside the chat thread.
- Conversation can be linked to contact and lead/deal from the side panel.
- Failed outbound messages are visible and retryable.
- Support lead can transfer conversations and audit history records the change.
