# CRM Master Plan

> **Current-phase execution plan: `specs/10_OMNICHANNEL_SUPPORT_PLAN.md`.**
> It expands the channel set to four (Telegram, Zalo personal via zca-js
> sidecar, Zalo OA, Facebook Messenger), adds the adapter-registry refactor,
> and adds message/contact fields + `sdk_limits`. Where it conflicts with
> earlier specs, spec 10 wins. ZaloCRM is a feature reference only (AGPL - do
> not copy code).
>
> **Next connector (cut 1, August 2026): `specs/11_SHOPEE_CHAT_VN.md`.**
> Adds Shopee Chat (VN region only, chat scope only) on top of spec 10's
> adapter-registry pattern. Provider enum extended with `SHOPEE` and a
> reserved `TIKTOK_SHOP` placeholder. No architecture changes — purely an
> adapter + new webhook path + OAuth round-trip.

## Vision

Build a modular Laravel CRM with a Bitrix24-like contact-center core: customer records, lead/deal pipeline, omnichannel inbox, Zalo/Telegram synchronization, and automatic support assignment. The system must be easy to extend with future modules while keeping the first release focused and operable.

## Spec Index

- `00_MASTER_PLAN.md`: product direction, first-release scope, and acceptance criteria.
- `01_ARCHITECTURE.md`: runtime architecture, module boundaries, queue/realtime design, and deployment assumptions.
- `02_MODULE_STRUCTURE.md`: exact Laravel modular monolith layout and module contract rules.
- `03_DATA_MODEL.md`: first-release schema, ownership, indexes, idempotency keys, and cross-module links.
- `04_OMNICHANNEL_INBOX.md`: support inbox behavior, conversation lifecycle, message states, and user flows.
- `05_CONNECTORS_ZALO_TELEGRAM.md`: provider adapter contracts, webhook endpoints, outbound sending, retries, and admin health.
- `06_ASSIGNMENT_ENGINE.md`: routing queues, sticky owner, even distribution, availability, workload limits, and reassignment.
- `07_ADMIN_UI.md`: shadcn-based screen inventory, layout, table/filter/action behavior, and realtime states.
- `08_RBAC.md`: role matrix, policies, sensitive configuration rules, and audit requirements.
- `09_TEST_RUNBOOK.md`: implementation phases, test matrix, local runbook, and release-readiness checklist.
- `10_OMNICHANNEL_SUPPORT_PLAN.md`: sidecar-based Zalo personal + Facebook connectors, adapter-registry refactor, dedup/media rules.
- `11_SHOPEE_CHAT_VN.md`: Shopee Open Platform v2 (VN region) chat connector — adapter contract, OAuth flow, HMAC verification, idempotency, retry policy, milestones through August 2026.
- `12_SHOPEE_CHAT_VN_READINESS.md`: project-management companion to spec 11 — locked decision log, DoD per milestone, estimate breakdown, go/no-go gates, project risk register, rollout plan, support model, success metrics. Use this to drive Shopee cut 1 from "ready to code" to "ready to ship".
- `13_TIKTOK_SHOP_VN.md`: TikTok Shop Partner API (VN region, chat only, NEW_MESSAGE webhook) connector — adapter contract, OAuth flow, HMAC verification, idempotency, retry policy, milestones through August 2026 (parallel to Shopee cut 1). Two W1 spikes required: auth model + signature scheme.
- `14_TIKTOK_SHOP_READINESS.md`: PM companion to spec 13 — same shape as spec 12. Decision log, DoD per milestone, estimate breakdown (~20 dev-days, mostly pattern-reuse from Shopee), go/no-go gates, project risk register, rollout plan.

## Non-Negotiable Architecture

- **Module-first**: CRM Core, Omnichannel Inbox, Channel Connectors, Assignment Engine, Admin Cockpit, Auth/RBAC, Audit, Notifications, and Files are separate modules.
- **Contract-first**: modules expose public services, events, jobs, API resources, and typed link points. No hidden cross-module coupling.
- **Provider adapters**: Telegram and Zalo adapters normalize inbound messages into one internal conversation/message contract.
- **Event and queue driven**: inbound webhook, normalization, assignment, notification, outbound send, and delivery-status updates run through jobs/events where possible.
- **Linkable entities**: conversations, contacts, leads, deals, tasks, tickets, campaigns, and future modules can be linked through explicit typed links.
- **Operator-first admin**: every module must show status, backlog, failures, ownership, and next action.

## Initial Module Map

### Platform Core

- Owns users, roles, permissions, teams, settings, audit logs, files, tags, comments, notifications, and activity timeline.
- Provides shared contracts for module registration, permissions, entity links, audit logging, and admin navigation.

### CRM Core

- Owns contacts, companies, external identities, leads, deals, pipelines, stages, custom fields, and customer timeline.
- Public contracts: find/create contact from identity, append timeline activity, link conversation to contact/lead/deal, advance lead/deal stage.

### Omnichannel Inbox

- Owns conversations, messages, internal notes, attachments, participants, unread state, SLA state, and assignment history.
- UI: inbox queue, conversation thread, customer side panel, lead/deal link controls, internal note, reply box, transfer/assign actions.

### Channel Connectors

- Telegram connector owns bot account config, webhook validation, update id idempotency, message normalization, outbound send, and delivery status.
- Zalo OA connector owns OA account config, OAuth/access-refresh token lifecycle, webhook validation, message normalization, outbound send, and delivery status.
- Future connectors must implement the same normalized channel adapter contract.

### Assignment Engine

- Owns routing queues, queue members, agent availability, workload limits, sticky owner routing, round-robin/even distribution, timeout reassignment, and manual transfer.
- Emits assignment events consumed by Inbox, Notifications, Audit, and future reporting modules.

### Admin Cockpit

- Owns health views across modules: channel status, webhook freshness, token expiry, queue lag, failed jobs, failed provider events, SLA breaches, and agent workload.
- This is the "5 minutes to understand operations" screen.

## shadcn/ui Implementation Rules

- Use Laravel + Inertia React + TypeScript for the admin UI.
- Initialize shadcn after the Laravel frontend exists and keep `components.json` as the source of truth.
- Use shadcn Base UI by default.
- Before adding components, run `npx shadcn@latest info --json`; before implementing with a component, run `npx shadcn@latest docs <component>`.
- Use shadcn components instead of custom markup for buttons, forms, tables, dialogs, sheets, sidebars, tabs, badges, alerts, empty states, skeletons, toasts, charts, separators, scroll areas, avatars, and pagination.
- Forms use FieldGroup/Field/InputGroup patterns with `data-invalid` and `aria-invalid`.
- Layout uses semantic tokens, `gap-*`, `size-*`, `truncate`, and `cn()`.
- Do not use raw color utilities as primary styling, `space-x-*`, `space-y-*`, manual overlay z-index, manual dark overrides, or hand-built replacements for existing shadcn primitives.

## First Release Scope

- Laravel app scaffold with auth, RBAC, PostgreSQL, Redis queue, Horizon, and realtime broadcasting.
- CRM Core for contacts, external identities, leads, deals, pipelines, and timeline.
- Omnichannel Inbox for normalized conversations/messages from Telegram and Zalo.
- Telegram and Zalo adapters with webhook ingestion, idempotency, outbound replies, and delivery state.
- Assignment Engine with sticky owner, round-robin/even distribution, online availability, max active chats, timeout reassignment, and manual transfer.
- Admin Cockpit with channel health, queue health, failed events/jobs, token status, SLA breaches, and support workload.

## Implementation Phases

1. **Foundation**: scaffold Laravel React/Inertia app, PostgreSQL, Redis, queues, websockets, auth, RBAC, module registry, shadcn setup, and admin shell.
2. **CRM Core**: contacts, companies, external identities, leads, deals, pipelines, stages, entity links, activity timeline, and core policies.
3. **Inbox Core**: conversations, messages, notes, attachments, assignment records, realtime inbox updates, and support reply workflow with mock channel adapter.
4. **Channel Connectors**: Telegram and Zalo webhook ingestion, idempotency, normalized message mapping, outbound outbox, delivery state, token/webhook health.
5. **Assignment Engine**: routing queues, sticky owner, even distribution, queue-order distribution, online availability, workload limits, timeout reassignment, manual transfer.
6. **Operations Hardening**: admin cockpit, failed event/job recovery, metrics, audit logs, provider diagnostics, test coverage, runbook, and release checklist.

## Acceptance Criteria

- A new Telegram or Zalo customer message creates or matches a contact, opens a conversation, creates or links a lead, and assigns the conversation to support.
- Duplicate provider webhooks do not create duplicate messages or duplicate assignments.
- An agent can reply from CRM and see delivery status as queued, sent, failed, or retrying.
- A returning customer routes to the previous owner when that owner is available and within workload limits.
- Admin can see connector health, queue lag, token expiry, failed inbound/outbound events, and overloaded agents from one cockpit.
- Each module has documented ownership, contracts, permissions, events/jobs, UI entry points, and tests.

## Future Modules

- Tasks and ticketing
- Campaigns and marketing automation
- Invoice/payment tracking
- Product/inventory catalog
- Appointment booking
- Knowledge base and canned replies
- AI summarization and agent assist
- Reporting/BI
- Workflow automation builder
