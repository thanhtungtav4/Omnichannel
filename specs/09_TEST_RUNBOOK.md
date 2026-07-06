# 09 Test And Runbook Spec

> **See `specs/10_OMNICHANNEL_SUPPORT_PLAN.md`** for the current-phase task
> order. Channel set is four (`TELEGRAM, ZALO_PERSONAL, ZALO_OA, FACEBOOK`).
> Add readiness/tests for: Zalo-personal sidecar (QR login, reconnect, circuit
> breaker), self-echo dedup, anti-block rate limiter, media mirror empty-body
> retry, and Facebook webhook signature. Telegram is the first real channel.

## Goal

Make the CRM runnable locally and verifiable before real Zalo/Telegram credentials are connected. Separate functional local readiness from release readiness.

## Implementation Phases And Gates

### Phase 1: Foundation

Deliver:

- Laravel React/Inertia scaffold.
- PostgreSQL/Redis config.
- Auth and RBAC.
- Module registry.
- shadcn initialized.
- Admin shell.

Gate:

- `php artisan test`
- `npm run build`
- Admin shell renders with authenticated user.

### Phase 2: CRM Core

Deliver:

- Contacts, companies, identities, leads, deals, pipelines, stages.
- Entity links.
- Timeline activities.

Gate:

- Contact create/update tests.
- External identity matching tests.
- Lead/deal linking tests.
- Entity link uniqueness tests.

### Phase 3: Inbox Core

Deliver:

- Conversations, messages, notes, attachments.
- Mock channel adapter.
- Reply outbox lifecycle.
- Realtime updates.

Gate:

- Inbound mock message creates conversation/contact/lead.
- Duplicate inbound event ignored.
- Agent reply creates local message + outbox row.
- Failed outbound status visible.

### Phase 4: Connectors

Deliver:

- Telegram webhook and outbound.
- Zalo webhook and outbound.
- Token/webhook health.
- Replay/retry admin actions.

Gate:

- Telegram webhook secret validation test.
- Telegram `update_id` idempotency test.
- Zalo token expiry/refresh tests.
- Zalo duplicate event test.
- Provider down retry test.

### Phase 5: Assignment Engine

Deliver:

- Routing queues and members.
- Sticky owner.
- Even distribution.
- Queue-order timeout.
- Workload limits.
- Manual transfer.

Gate:

- Returning customer routes to owner.
- Offline agent skipped.
- Overloaded agent skipped.
- Broadcast claim race resolves once.
- Timeout reassign creates assignment attempts.

### Phase 6: Operations

Deliver:

- Admin cockpit.
- Failed jobs/events panel.
- Recovery actions.
- Audit logs.
- Runbook.

Gate:

- Admin sees channel health, queue lag, failed webhook/outbox.
- Retry/replay actions are policy-protected and audited.
- Local runbook starts app from fresh checkout.

## Test Matrix

| Area | Scenario | Expected |
| --- | --- | --- |
| Webhook | Duplicate Telegram update | One message, second event ignored |
| Webhook | Invalid Telegram secret | 401, no webhook event processing |
| Webhook | Zalo unsupported event | Stored then ignored with admin visibility |
| CRM | Existing external identity | Reuses contact |
| CRM | New provider user | Creates contact + identity |
| Inbox | Customer message | Opens conversation and message |
| Inbox | Agent reply | Creates message + outbox |
| Outbox | Provider timeout | Retries with backoff |
| Outbox | Permanent provider error | Marks failed, exposes retry/manual action |
| Routing | Owner available | Sticky assignment |
| Routing | Owner offline | Falls back to queue mode |
| Routing | All agents full | Conversation remains waiting with alert |
| RBAC | Agent replies unassigned chat | Forbidden |
| Admin | Token expired | Channel status degraded |
| UI | Empty inbox | Uses shadcn Empty state |
| UI | Loading cockpit | Uses Skeleton state |

## Local Runbook

Expected local services:

- PHP 8.3+
- Composer
- Node.js LTS
- PostgreSQL
- Redis

Typical commands after scaffold exists:

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --seed
php artisan horizon
php artisan reverb:start
php artisan serve
npm run dev
```

Use mock provider mode before real credentials:

- `CHANNELS_MOCK_MODE=true`
- Telegram/Zalo webhook tests use local signed fixtures.
- Outbound provider calls are faked in tests.

## Release Readiness Checklist

Functional local:

- App boots.
- Admin login works.
- CRM/inbox core tests pass.
- Mock inbound message creates contact, lead, conversation, assignment.
- Mock reply creates outbox and marks sent.

Real provider readiness:

- Public HTTPS webhook URL available.
- Telegram bot token configured.
- Telegram webhook registered with secret token.
- Zalo OA app credentials configured.
- Zalo access/refresh token valid.
- Zalo webhook URL registered.
- At least one routing queue has active support users.
- Admin can see channel health as active.

Production readiness:

- Queue workers supervised.
- Horizon protected.
- Scheduler running.
- Reverb or Pusher-compatible service configured.
- Backups configured.
- Logs and failed jobs monitored.
- Secret rotation documented.
- Rate-limit and retry thresholds reviewed.

## Required Commands Before Handoff

Once code exists:

```bash
php artisan test
npm run build
php artisan route:list --path=webhooks
php artisan queue:failed
```

If UI changed:

```bash
npm run lint
```

Add Playwright or browser smoke tests once the first admin UI screens exist.
