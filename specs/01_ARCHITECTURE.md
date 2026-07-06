# 01 Architecture Spec

## Goal

Build a modular Laravel CRM as a modular monolith first. It should feel like a focused Bitrix24-style contact center: every customer conversation becomes CRM data, every support action is traceable, and future modules can connect through explicit contracts instead of hidden dependencies.

## Runtime Stack

- Laravel 12.
- PHP 8.3+.
- PostgreSQL 16+.
- Redis for cache, queue, rate limits, agent presence, and realtime coordination.
- Laravel Queue + Horizon for webhook normalization, outbound sending, assignment, retries, and health checks.
- Laravel Reverb for realtime admin/inbox updates unless infrastructure later chooses a Pusher-compatible service.
- Inertia React + TypeScript for admin UI.
- shadcn/ui Base UI for admin components.
- Vite for frontend build.

## Architectural Style

The system is a **modular monolith**:

- One Laravel application and one database.
- Modules are isolated by namespace, service/provider registration, migrations, tests, UI routes, permissions, and contracts.
- Cross-module communication uses contracts, events, jobs, and typed entity links.
- Modules may share platform services such as auth, audit, files, notifications, and settings.
- Avoid a microservice split in v1. Queue boundaries and contracts are designed so high-volume connector or AI modules can be extracted later if needed.

## Core Request Flow

```text
Provider webhook
-> WebhookController validates account/secret/signature
-> webhook_events stores raw payload + idempotency key
-> NormalizeInboundMessageJob
-> ChannelAdapter maps provider payload to NormalizedInboundMessage
-> CRM Core matches/creates Contact + ExternalIdentity
-> Inbox opens/updates Conversation + Message
-> Assignment Engine chooses owner/queue state
-> Realtime broadcast updates Inbox/Admin
-> Agent replies
-> outbox_messages stores outbound request
-> SendOutboundMessageJob calls provider API
-> delivery status updates Conversation/Message/Admin Cockpit
```

## Module Boundaries

| Module | Owns | Exposes | Cannot Do |
| --- | --- | --- | --- |
| Platform Core | users, workspaces, roles, permissions, settings, audit, files, tags, entity links, notifications | module registry, audit writer, setting reader, entity-link service | Own CRM-specific lifecycle rules |
| CRM Core | contacts, companies, external identities, leads, deals, pipelines, stages, timeline | contact matcher, lead/deal linker, timeline writer | Read provider payloads directly |
| Omnichannel Inbox | conversations, messages, notes, attachments, assignments, SLA state | conversation service, message service, inbox events | Decide provider-specific parsing |
| Channel Connectors | channel accounts, webhook events, outbound delivery, provider adapters | normalized inbound/outbound contracts | Assign conversations directly without Assignment Engine |
| Assignment Engine | routing queues, queue members, presence, workload, assignment attempts | assignment service, transfer service, availability service | Modify provider tokens or CRM stages |
| Admin Cockpit | health views, dashboards, recovery actions | dashboard view models, recovery commands | Own business data |

## Event And Job Contracts

Events are for module communication and auditability:

- `ProviderWebhookReceived`
- `InboundMessageNormalized`
- `ContactMatched`
- `ConversationOpened`
- `MessageStored`
- `ConversationAssignmentRequested`
- `ConversationAssigned`
- `ConversationTransferred`
- `OutboundMessageQueued`
- `OutboundMessageSent`
- `OutboundMessageFailed`
- `ProviderAccountHealthChanged`
- `SlaBreached`

Jobs are for asynchronous work:

- `NormalizeInboundMessageJob`
- `MatchOrCreateContactJob`
- `OpenOrUpdateConversationJob`
- `AssignConversationJob`
- `SendOutboundMessageJob`
- `RefreshZaloAccessTokenJob`
- `SyncTelegramWebhookStatusJob`
- `ReassignTimedOutConversationJob`
- `ComputeAdminHealthSnapshotJob`

## API Surface

Use Inertia routes for admin pages and JSON endpoints for async actions.

Admin page routes:

- `GET /admin`
- `GET /admin/inbox`
- `GET /admin/contacts`
- `GET /admin/leads`
- `GET /admin/deals`
- `GET /admin/channels`
- `GET /admin/routing`
- `GET /admin/settings`

Webhook routes:

- `POST /webhooks/telegram/{channelAccount:uuid}`
- `POST /webhooks/zalo/{channelAccount:uuid}`

Admin JSON actions:

- `POST /api/admin/conversations/{conversation}/reply`
- `POST /api/admin/conversations/{conversation}/assign`
- `POST /api/admin/conversations/{conversation}/transfer`
- `POST /api/admin/conversations/{conversation}/close`
- `POST /api/admin/outbox-messages/{message}/retry`
- `POST /api/admin/webhook-events/{event}/replay`
- `POST /api/admin/channel-accounts/{account}/test`

All JSON errors use:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Human readable message",
    "details": {}
  }
}
```

## Realtime Requirements

- Conversation list updates when a new inbound message arrives.
- Conversation thread updates when a message, note, delivery status, assignment, transfer, or close event changes.
- Admin cockpit updates channel health, queue lag, failed jobs, and SLA breach counters without full page reload.
- Presence changes should update assignment eligibility within 30 seconds.

## Operational Requirements

- Webhook endpoints must return a fast 2xx after raw event persistence and queue dispatch.
- Any provider payload used by the app must be normalized and validated before touching CRM/Inbox state.
- Raw webhook events are retained for debugging and replay.
- Failed jobs and provider errors are visible in Admin Cockpit.
- Secrets/tokens are encrypted at rest and never rendered in full after save.

## External References

- Bitrix24 Open Channels queue and distribution behavior: https://helpdesk.bitrix24.com/open/25782447/
- Telegram Bot API webhook/update behavior: https://core.telegram.org/bots/api
- Zalo OA webhook and OA API docs: https://developers.zalo.me/docs/official-account/webhook/tong-quan
