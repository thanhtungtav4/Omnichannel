# 10 Omnichannel Support Module Plan

## Purpose

This is the **execution plan** for the current phase: build the omnichannel
customer-support chat module (Zalo personal, Zalo OA, Telegram, Facebook
Messenger) on top of the modules that already exist, so agents can chat with
customers across platforms from one CRM inbox.

ZaloCRM (github.com/locphamnguyen/ZaloCRM) is a **feature reference only**, not
a code source. Its stack is Node/Fastify/Prisma + Vue/Vuetify and it is
AGPL-3.0. Our stack is Laravel + Inertia/React + shadcn. Do NOT copy its code
(different language + framework = rewriting is faster than porting, and AGPL
copyleft applies). Copy its *ideas* only: data model shape, anti-block logic,
message dedup flow, media mirror flow. The one exception is the Node zca-js
sidecar (see Phase 5) where their `openzca`/zca-js wrapper logic can be ported
directly because it is already Node.

Business roadmap for context (do not build later phases now):
`lead -> customer -> dental patient module`. This inbox links to `contacts`.
The future dental/patient module attaches to `contacts` via `entity_links`,
never touches the inbox. Keep contact as the pivot.

## Current State (already built - read before changing)

- Data model complete across 4 modules (see `specs/03_DATA_MODEL.md`):
  contacts, external_identities, conversations, messages, outbox_messages,
  webhook_events, entity_links, routing_queues, agent_presence, etc.
- `app/Modules/Channels/Services/InboundMessageIngestor.php` already does:
  webhook_event dedup (unique idempotency_key), find-or-create contact via
  external_identity, find-or-create lead, find-or-create conversation, create
  message, timeline activity, and calls `AssignmentService::assign` with
  `AUTO_STICKY_OWNER`. **Do not rebuild this - extend it.**
- `ProviderWebhookController` receives webhooks per channel_account UUID.
- Inbox models exist: Conversation, Message, InternalNote.
- `AssignmentService::assign` (sticky owner) exists.

## Gap (what "chat across platforms easily" needs)

1. Adapter normalize is a single `normalize()` if/else inside the ingestor.
   Adding a channel means editing that method. Spec 05 defines a `ChannelAdapter`
   interface that is not used yet. This is the blocker for "easy to add channels".
2. No real outbound send job (outbox_messages table exists, nothing sends it).
3. No channel connected for real (Telegram, Zalo sidecar, Zalo OA, FB all TODO).
4. No Inbox UI (models exist, no React/Inertia screens).
5. No media / sticker / template / conversation filters (ZaloCRM-parity features).

## Stolen Ideas from ZaloCRM (design/logic only, no code)

These are the hard-won ideas worth copying. Reimplement in Laravel/Eloquent.
Source: ZaloCRM `backend/prisma/schema.prisma`, `message-handler.ts`,
`zalo-rate-limiter.ts`, `zalo-pool.ts`, `zalo-listener-factory.ts`.

### Data-model ideas we are MISSING (add via migrations)

1. **Snowflake sort key on messages.** Add `provider_message_seq` (BIGINT,
   nullable) = Zalo `msgIdNum`. **Sort the thread by this, not by timestamp.**
   Self-listen echo events often lack a real `ts` and fall back to `now()`
   (~30s skew), so timestamp ordering is wrong. Timestamp is display/fallback only.
2. **Two-layer identity: Contact (person) + per-nick relationship.**
   ZaloCRM splits `Contact` (the human, keyed by global id) from `Friend`
   (one row per nick x person). This lets multiple agent nicks chat the same
   customer without leaking each other's chats (each nick sees its own
   last-message preview). Our `external_identities` is close but is 1 row per
   provider-user; we may need a per-(channel_account x contact) preview cache
   when multi-nick lands (Task 10). Not needed until multi-nick - note it.
3. **Dual identity keys for dedup.** Zalo `uid` drifts per viewer; the real
   dedup key is the **global id**. Also store `phone_normalized` (canonical
   84xxx) indexed, for phone dedup across 0xxx/84xxx/+84xxx. Contact resolution
   order: identity(global) -> phone_normalized -> per-account uid -> create,
   race-safe (catch unique violation).
4. **Denormalized last-activity cache on contact** (`last_inbound_*`,
   `last_outbound_*`, counters) for inbox list perf. We already have
   `last_inbound_at`; extend when the list needs it.
5. **`sdk_limits` table** (org-default row + per-nick override rows) driving the
   rate limiter (see below). One table, `channel_account_id` nullable for the
   default.

### Message dedup + ordering (the msgId fix)

- Unique on `(channel_account, provider_message_id, direction)` already planned.
  On insert conflict -> skip silently.
- **Self-echo dedup** (provider echoes our own sent message back within ~30s):
  - Text: find our recent `OUTBOUND` message with same body sent within 30s ->
    backfill provider ids into that placeholder, drop the echo.
  - Attachment: body strings differ (ours = storage URL, echo = CDN URL) ->
    match by `message_type` only + **atomic compare-and-swap** claim on
    `provider_message_id IS NULL` (updateMany-style). Exactly one echo claims;
    album siblings that fail to claim insert normally. This is the
    "duplicate album image" fix.
- Primary thread sort = `provider_message_seq` (see idea 1).

### Anti-block rate limiter

- Per-account, per-category (message / friend_add / reaction / chat_action).
- Two gates: **daily counter** + **burst sliding window**.
- Redis: daily = `HINCRBY rl:daily:{acct}:{cat}` field=date (expire 2d);
  burst = sorted set `rl:burst:{acct}:{cat}` (`ZADD` ts, `ZREMRANGEBYSCORE`
  prune, `ZCARD` count). **Fail-open** (error -> allow, never block real work).
- Limits read from `sdk_limits`: nick override -> org default -> hardcoded
  fallback (300 msg/day/nick, 30 friend-adds/day). Cache 60s.
- `checkLimits()` before every SDK op, `recordSend()` after.

### Media mirror (CDN -> object storage)

- Zalo CDN URLs (`zpc.zdn.vn`) expire fast -> mirror inbound media to S3/MinIO
  so bubbles always render. Walk candidate URL fields
  (`hdUrl,href,normalUrl,fileUrl,url,thumbUrl,thumb,thumbnail`, nested
  `params.rawUrl/params.hd`), fetch -> upload -> rewrite URL in stored payload.
- **Empty-body retry**: CDN often returns 200 with empty body (eventual
  consistency). **Retry once after 1.5s; if still empty, keep original URL,
  never store a 0-byte file.**
- Optional: compress images to webp before store (skip video/voice/gif).
- **Outbound**: zca-js `sendMessage` needs a local file path, not a URL ->
  stream media to a temp file for the sidecar, clean up after.
- Do all mirroring in a queued job, not inline in the webhook.

## Inbox UI Blueprint (rebuild ZaloCRM's layout in shadcn/React)

ZaloCRM's UI (Vue/Vuetify) is good; we copy the **design**, not the code
(Vue does not run in React). Rebuild this exact 4-column layout with shadcn
+ `Resizable`, per `specs/04_OMNICHANNEL_INBOX.md` component list. Source:
`frontend/src/views/ChatView.vue`, `components/chat/ChatContactPanel.vue`.

Grid: `290px | 380px | 1fr | 350px`, responsive collapse (col4 hides <1024px,
col1 hides <1200px, col1 -> 56px icon rail when collapsed).

- **Col 1 (~290px) Filter rail**: workspace + current user, **nick/channel
  picker with live online/offline dots** (green = connected) + per-nick unread,
  folders, total unread, deep CRM filters (score tier, stage, stuck duration,
  last-message-within, customer-waiting-reply, appointment-within-24h,
  birthday-within-7d). shadcn: `Sidebar`, `Badge`, `Select`.
- **Col 2 (~380px) Conversation list**: stacked top banners (realtime-offline
  amber pulse; out-of-scope blue bar "N msgs in M other nicks"); filter tabs
  **Cá nhân / Nhóm / Chính / Ưu tiên** with live counts; search (300ms debounce).
  Row = avatar, name + **follow-bell** (care-session), per-nick-scoped last
  preview, unread badge, channel badge, labels/tags, SLA badge. shadcn:
  `ScrollArea`, `Tabs`, `Badge`, `Avatar`, `Input`, `Empty`, `Skeleton`.
- **Col 3 (1fr) Message thread**: header (contact/nick switcher, labels
  dropdown) + bubbles (reactions, quote-reply, album grouping, delivered/seen
  icons, typing dots, source badge "Sale CRM · name") + composer (send, reply,
  edit [CRM-only edit], forward, reactions, AI-suggest, insert-from-media,
  appointment, quick templates via `/`). shadcn: `ScrollArea`, `Textarea`,
  `InputGroup`, `Button` (icon `data-icon`), `Sonner` for send/retry.
- **Col 4 (~350px) Contact panel** (only when contact known): header =
  **score banner** (3 stat cards: lead / engagement / priority + trend, tabular
  nums) + avatar + inline stage selector. Bottom 4 tabs
  **Profile / Media / AI / Follow-up**; Profile has sub-tabs Hồ sơ (collapsible
  form) / CRM (link, next-action, heat bar, timeline, "sales sharing this
  customer", "nicks co-caring") / Appointments / Score. shadcn: `Card`, `Tabs`,
  `Badge`, `Field`/`FieldGroup`, `Separator`.

Build order: Col 3 (thread + composer) and Col 2 (list) first in Task 4 - that
is the usable core. Col 1 deep filters and Col 4 score/CRM widgets come in
Phase 3 (Tasks 9-10) once the CRM aggregates exist.

## Architecture Decision: one registry, N adapters

Refactor the ingestor's `normalize()` if/else into adapter classes that
implement the `ChannelAdapter` contract from `specs/05_CONNECTORS_ZALO_TELEGRAM.md`.
This is the key to "add a channel easily": a new channel = one new class, no
edit to the ingestor.

```
ChannelAdapterRegistry->for($channelAccount) returns:
 - TelegramAdapter      official webhook (easiest, do first)
 - ZaloPersonalAdapter  zca-js via Node sidecar (ZaloCRM-style personal chat)
 - ZaloOaAdapter        Zalo OA official webhook + token refresh
 - FacebookAdapter      Messenger webhook + Graph send
```

Ingestor calls `registry->for($account)->normalizeInbound(...)`.
Outbound job calls `registry->for($account)->sendOutbound(...)`.

## Task List

### Phase 0: Adapter contract (foundation for every channel)

- [ ] **Task 1** - Define `ChannelAdapter` interface + `ChannelAdapterRegistry`.
  Extract the current Telegram/Zalo/mock branches from `InboundMessageIngestor::normalize()`
  into separate adapter classes. Ingestor resolves the adapter from the registry.
  **Behavior must not change** - pure refactor.
  - Files: `app/Modules/Channels/Contracts/ChannelAdapter.php`,
    `app/Modules/Channels/Adapters/{Telegram,ZaloOa,Mock}Adapter.php`,
    `app/Modules/Channels/Services/ChannelAdapterRegistry.php`,
    edit `InboundMessageIngestor.php`.
  - Verify: existing tests still pass + new test asserting registry returns the
    right adapter per provider.
  - Scope: S-M.

**Checkpoint 0:** multi-channel architecture ready; adding a channel does not
touch the ingestor.

### Phase 1: One channel end-to-end for real (Telegram)

Telegram first: official webhook, no sidecar, no AGPL, no account-ban risk.
Proves the whole inbox flow before touching Zalo.

- [ ] **Task 2** - Telegram inbound for real: register webhook (`setWebhook` +
  secret_token), verify `X-Telegram-Bot-Api-Secret-Token` header, wire into ingestor.
  - Verify: send a real Telegram message -> webhook_events + messages rows created.
  - Scope: S.
- [ ] **Task 3** - Outbound: `SendChannelMessageJob` -> `adapter->sendOutbound()`
  -> Telegram `sendMessage`. Update outbox_messages + message status. Retry policy
  from spec 05 (backoff 1m/5m/15m/1h, max 5 attempts).
  - Verify: reply from inbox -> message arrives on Telegram; failure -> FAILED + retry.
  - Scope: M.
- [ ] **Task 4** - Inbox UI per the "Inbox UI Blueprint" above (React/Inertia/shadcn).
  This phase: **Col 2 (conversation list) + Col 3 (thread + composer)** only, with
  realtime append. Col 1 filters + Col 4 CRM widgets are Phase 3. Follow the
  ZaloCRM 4-column layout so later columns slot in without rework.
  - Verify: agent handles a full conversation without leaving the inbox.
  - Scope: M.

**Checkpoint 1:** customer messages on Telegram -> contact + conversation +
assign + agent reply from UI. This is a working omnichannel inbox.

### Phase 2: Fan out to Zalo + Facebook (reuse Phase 1 plumbing)

- [ ] **Task 5a** - Migrations for Zalo-personal reality (from "Stolen Ideas"):
  add `messages.provider_message_seq` (BIGINT nullable, thread sort key),
  `contacts.phone_normalized` (indexed), `sdk_limits` table (org-default +
  per-nick override rows). Add self-echo/atomic-claim dedup to the ingestor.
  NOTE: DB already built in `database/migrations/2026_07_04_000001_create_modular_crm_tables.php`
  (20 tables, per spec 03). `message_attachments` was already added there. If
  that migration has NOT been deployed to any real DB yet, edit it in place;
  if it HAS, add a new additive migration instead. Check `migrations` table first.
  - Verify: thread sorts by seq; phone dedup across 0xxx/84xxx; echo does not dup.
  - Scope: M.
- [ ] **Task 5b** - Node zca-js sidecar (ZaloCRM's `zalo-pool.ts` /
  `zalo-listener-factory.ts` logic is PORTABLE here - it is already Node).
  Separate process holding one SDK instance per nick. Contract:
  `POST /accounts/:id/login-qr` (stream QR events via events 0/1/2/4),
  `POST /accounts/:id/reconnect`, `POST /accounts/:id/send`,
  `DELETE /accounts/:id`, `GET /accounts/:id/status`, `GET /health`.
  Session creds `{cookie,imei,userAgent}` stored encrypted in Postgres; sidecar
  reads them to reconnect on boot. Copy their guards: epoch counter (stale
  callback guard), in-flight reconnect set, manual-disconnect skip,
  **circuit breaker (>5 disconnects/5min -> stop auto-reconnect, require QR)**,
  30s auto-reconnect, uid-mismatch rejection. Sidecar -> Laravel via Redis
  pub-sub or signed webhook -> ingestor (idempotent). QR/events -> browser via
  Reverb.
  - Verify: QR login persists session; kill+restart sidecar reconnects; message
    2-way; circuit breaker trips on flapping.
  - Scope: L.
- [ ] **Task 5c** - `ZaloPersonalAdapter` (normalize sidecar events) + wire the
  anti-block rate limiter (see "Stolen Ideas") into the outbound job:
  `checkLimits()` before send, `recordSend()` after; fail-open.
  - Verify: 301st message/day is blocked; burst window throttles.
  - Scope: M.
- [ ] **Task 6** - `ZaloOaAdapter`: OA official webhook + `RefreshZaloAccessTokenJob`
  (spec 05 token lifecycle).
  - Scope: M.
- [ ] **Task 7** - `FacebookAdapter`: Messenger webhook (verify + signature) + Graph send.
  - Scope: M.

**Checkpoint 2:** all 4 channels flow into one inbox.

### Phase 3: ZaloCRM feature parity

- [ ] **Task 8** - Media mirror per "Stolen Ideas": queued job walks candidate
  URL fields, fetch -> S3/MinIO -> rewrite payload, **retry-once-on-empty-body
  (1.5s), never store 0-byte**, optional webp compress. Outbound = stream to
  temp file for sidecar. `message_attachments` already exists.
  - Verify: inbound image URL points to storage not `zpc.zdn.vn`; empty-body CDN
    keeps original URL; 2-way image works.
  - Scope: M.
- [ ] **Task 9** - Col 1 deep filters + quick templates (type `/`) + conversation
  filter tabs (Cá nhân/Nhóm/Chính/Ưu tiên with live counts) + unread state.
  - Scope: M.
- [ ] **Task 10** - Col 4 CRM widgets (score banner, heat bar, timeline,
  sales-sharing, nicks-co-caring) + sticker/reaction render + multi-nick
  management UI (nick picker online dots, per-nick preview scoping).
  - Scope: M-L.

**Checkpoint 3:** support feature-parity with ZaloCRM.

### Dental/patient prep (do NOT build now - YAGNI)

`entity_links` already exists. Future Dental module inserts `patient <-> contact`
links only. Inbox does not change. No patient code this phase.

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| zca-js bans the account (unofficial) | High | Task 5 rate limit + adapter is swappable to Zalo OA |
| "parity with ZaloCRM" is too big at once | High | Phased; Phase 1 is already usable for real |
| Sidecar single point of failure | Medium | outbox queue keeps messages if sidecar restarts |
| AGPL copyleft if code is copied | High (legal) | Do not copy code; port ideas + zca-js sidecar only |

## Sequencing Rule for the Implementing Agent

Do tasks in order. Each task must leave the app green
(`php artisan test`, `npm run build`). Stop at each checkpoint for review.
Do not start "parity" features (Phase 3) before Phase 1 is usable end-to-end.

## How to Resume (read this every session)

Before doing anything, figure out where the work stands, then do the next task:

1. Read the **Progress Log** below — the last entry names the last finished task.
2. Verify it against the codebase (do not trust the log blindly):
   - Task 1 done if `app/Modules/Channels/Contracts/ChannelAdapter.php` +
     `ChannelAdapterRegistry` exist and the ingestor uses the registry.
   - Task 2 done if a real Telegram webhook route verifies the secret header.
   - Task 3 done if `SendChannelMessageJob` exists and updates outbox + message status.
   - Task 4 done if an Inbox React page renders a conversation list + thread.
   - Task 5a done if `messages.provider_message_seq`, `contacts.phone_normalized`,
     and the `sdk_limits` table exist in the migration/DB.
   - Task 5b done if a Node sidecar dir + its HTTP contract exist.
   - ...and so on down the task list.
3. The **next task** = the first task in the list that is NOT confirmed done.
4. Run `php artisan test` first to confirm the app is green before starting.
5. Do exactly ONE task. Then append a Progress Log entry and stop at any checkpoint.

If the log and the codebase disagree, trust the codebase and fix the log.

## Progress Log

Append one line per finished task. Format:
`YYYY-MM-DD | Task N | done | <one-line note> | tests: N passed`

- 2026-07-05 | Phase-0 prep | DB hardened (68 FK, 30 CHECK, hot indexes, PostgreSQL, message_attachments/sdk_limits/provider_message_seq/phone_normalized added). NOT a plan task — foundation. | tests: 41 passed
- 2026-07-05 | Task 1 | done | Adapter contract + registry extracted; ingestor resolves provider adapters without normalize if/else. | tests: 42 passed
- 2026-07-05 | Task 2 | done | Telegram setWebhook registration command added with secret_token, allowed_updates, HTTPS guard, and secret-header ingestion tests. | tests: 44 passed
- 2026-07-05 | Task 3 | done | SendChannelMessageJob sends Telegram sendMessage via adapter and updates outbox/message sent or retrying status. | tests: 46 passed
- 2026-07-05 | Task 4 | done | Inbox core UI rebuilt as queue + thread/composer with operational stats, filters, auto-refresh, transfer/close/reply actions, and outbox failure visibility. | tests: 47 passed
- 2026-07-05 | Task 5a (part) | done | Contact.phone mutator auto-populates phone_normalized (VN 0xxx/+84/84 -> 84xxx canonical) with runnable self-check. Schema (provider_message_seq/phone_normalized/sdk_limits) already existed from Phase-0. DEFERRED to Task 5b/5c: self-echo + atomic-claim album dedup and provider_message_seq thread-sort usage — only meaningful once the zca-js sidecar emits self-listen echoes. | tests: 47 passed
- 2026-07-05 | Task 5b (scaffold) | done | Node sidecar in sidecar/ (node:http, no framework): ZaloPool + HTTP contract (login-qr/reconnect/send/status/health, DELETE disconnect), shared-token auth, pushes events to CRM zalo webhook. STUB mode (ZALO_STUB=1) simulates QR/login/send + self-echo so the full pipe is testable without real Zalo. 4 sidecar tests. Also fixed a real bug: webhook routes were behind CSRF (419) — added validateCsrfTokens(except: webhooks/*); zalo webhook now verifies X-Sidecar-Token. env keys added. DEFERRED: real zca-js wiring (login/session/reconnect/circuit-breaker), user cams that in. | tests: 48 passed + 4 sidecar
- 2026-07-05 | Task 5c | done | ZaloPersonalAdapter (own adapter, split from ZaloOa in registry): normalizes sidecar events incl. provider_message_seq, sendOutbound proxies to sidecar /send gated by SdkRateLimiter. SdkRateLimiter (Redis 2-gate daily+burst, fail-open, sdk_limits nick-override->org-default->fallback). Ingestor now persists provider_message_seq. services.zalo_sidecar config added. | tests: 54 passed + 4 sidecar
- 2026-07-05 | Task 6 | done | ZaloOaAdapter.sendOutbound calls Zalo OA message API (openapi.zalo.me/v3.0/oa/message/cs); token-expired errors mark DEGRADED + retryable. RefreshZaloAccessTokenJob refreshes access token (oauth.zaloapp.com/v4/oa/access_token), failure degrades account. | tests: 58 passed
- 2026-07-05 | Task 7 | done | FacebookAdapter (Messenger) + registry entry. Webhook: GET verify (hub.challenge vs webhook_secret), POST events verify X-Hub-Signature-256 (app_secret HMAC) then ingest each entry[].messaging[] with a message. sendOutbound via Graph /me/messages. Routes webhooks/facebook/{account} GET+POST. | tests: 62 passed
- 2026-07-05 | Task 7.5 (channel config UI) | done | Not in original plan but required: ChannelAccountController store/update (RBAC owner/admin only, per-provider credential validation, credential merge on update, auto webhook_secret). Admin channels page gains an "Add channel account" form (provider select -> dynamic credential fields for Telegram/ZaloOA/ZaloPersonal/Facebook, webhook URL hint) gated by canManage. Routes POST/PUT api/admin/channels. | tests: 66 passed
- 2026-07-05 | Channel setup UX | done | Fixed "created a channel, now what?" gap. Each channel row shows live webhook state (receiving / registered-no-msg / not-set-up). New Setup dialog: per-provider ordered checklist with green checks driven by live data, copyable callback URL + verify token, "Register webhook" (Telegram auto via registrar) / "Mark ready" (manual providers set URL + ACTIVE). Edit/Delete kept. registerWebhook endpoint + route. | tests: 68 passed
- 2026-07-05 | Task 5b (real zca-js) | done | Wired real zca-js 2.1.2 in sidecar/zalo-pool.js: loginQR (event 0 QR image -> resolves immediately, event 4 persists cookie/imei/userAgent to sidecar/sessions/{id}.json), login() reconnect on boot, listener.onMessage -> pushes normalized inbound to CRM, sendMessage(text, threadId, User). server.js saves/loads credentials locally. CRM proxies QR/status: ChannelAccountController zaloLoginQr + zaloStatus (owner/admin), routes added, services.zalo_sidecar env. Setup dialog now renders a real QR (ZaloQrLogin: start -> show QR img -> poll status -> CONNECTED reflects to account ACTIVE). csrf-token meta added. Verified: real QR PNG generated by zca-js. Stub tests still 4 pass. | tests: 68 passed
- 2026-07-05 | Dev ergonomics | done | dev.sh starts Laravel + queue worker + Zalo sidecar (real) in one command (TUNNEL=1 adds cloudflared). Fixes "reply stuck because queue:work wasn't running". Production still uses supervisor (docs/DEPLOY_VPS.md).
- 2026-07-05 | Self-echo dedup | done (simpler than planned) | The planned 30s text-match + atomic-claim album dedup is NOT needed: sidecar skips isSelf=true events (own outbound never re-ingested), and the DB unique index (workspace, channel_account, provider_message_id, direction) blocks duplicates. Verified: 0 duplicate groups in DB; duplicate insert raises UniqueConstraintViolationException. Trade-off noted: messages an agent sends from the phone (not via CRM) are skipped as isSelf.
- 2026-07-05 | CRUD contact | done | ContactController store/update/destroy (owner/admin delete, phone mutator normalizes). contacts.tsx "New contact" dialog (was disabled). contact-show delete button. Verified create+normalize+update+delete. | tests: 68 passed
- 2026-07-05 | Lead kanban | done | Separate Leads screen (was sharing contacts.tsx). AdminController.leads groups leads by status; leads.tsx = 5-column kanban (Mới/Đang tư vấn/Quan tâm/Chốt/Mất) with native HTML5 drag-drop -> LeadController.updateStatus. Cards link to contact detail. Routes: admin/leads own method + PUT api/admin/leads/{lead}/status. | tests: 68 passed
- 2026-07-06 | Group chat | done | Zalo group messages were splitting per-member into separate 1:1 conversations. Fixed: sidecar sends thread_type/thread_id (msg.type 0=User 1=Group); ingestor keys the conversation by the GROUP thread ("[Nhóm] name" contact), prefixes each line with the sender name, skips lead creation for groups. Migration adds conversations.is_group + provider_thread_id. Group REPLY: ConversationActionController targets provider_thread_id + payload is_group; ZaloPersonalAdapter passes isGroup; sidecar sends ThreadType.Group. Manual "Sync lịch sử" button (zaloSync -> requestOldMessages) added to Setup dialog. Verified: 2 members -> 1 conversation, 2 prefixed messages; group reply builds recipient=group thread. | tests: 68 passed
- 2026-07-06 | Inbox polish | done | (1) Split inbox.tsx 1139->355 lines into inbox/{lib,MessageBubble,QueueParts,ThreadPanel}. (2) Query: conversationList uses lastMessage relation (last_message_id) instead of a per-row subquery — fixed 6 queries regardless of conversation count; backfilled null last_message_id. (3) Task 8 media: sidecar parseContent detects image/video/file/sticker + url; ingestor stores MessageAttachment; MessageBubble renders image/sticker inline + file/video links. (4) Quick-reply templates: type "/" in composer -> template picker (QUICK_TEMPLATES). Vietnamese labels throughout. | tests: 68 passed
- 2026-07-06 | Multi-agent concurrency | done | For 10 agents at once: (1) Reply/close/transfer permission — agents reply only to conversations they own or that are unassigned; owner/admin/support_lead reply to any (deny-by-default, spec 04/08) + 4 regression tests. (2) close() and transfer() now run under lockForUpdate transactions so concurrent actions don't double-count the presence counter; transfer moves the counter old->new owner. (3) Presence heartbeat: inbox POSTs /presence/heartbeat every 20s (ONLINE + last_seen_at), sendBeacon offline on unload, scheduled sweep flips stale (>90s) agents OFFLINE — so auto-assign only routes to present agents. (4) Auto-assign round-robin already in AssignmentService (sticky-then-even, last_assigned_at ordering); verified 6 new customers distribute across online agents. Reset stale active_conversation_count to real open-conversation counts. | tests: 72 passed
- 2026-07-06 | Inbox UX (Task 9/10 finish) | done | Three UI pieces user asked for: (1) emoji picker — smile button in composer opens a native emoji grid (static EMOJIS list, no dep), appends to reply body; (2) search-in-thread — Search button in thread header toggles a filter bar that filters the loaded messages by body text (client-side over the loaded page, match count shown); (3) presence badge — thread header shows "{owner} đang xử lý" with a green/idle dot; AdminController adds owner.online (AgentPresence ONLINE within 90s window). | tests: 72 passed
- 2026-07-06 | Status i18n | done | StatusBadge now Vietnamises every enum via one statusLabelVi map (WAITING_AGENT→"Chờ nhân viên", OPEN→"Đang mở", priority/message/SLA/presence enums). Raw enum falls through for unmapped. One change covers inbox, contact, lead, channel badges. | tests: 72 passed
- 2026-07-06 | Send image | done | Outbound image support (Telegram + Zalo personal). reply() accepts optional `image` (nullable body when image present, one-of required, 10MB image validation), stores under public disk, creates IMAGE Message + MessageAttachment (metadata.url), outbox payload carries image_url (public, Telegram) + image_path (local abs, Zalo sidecar). TelegramAdapter branches to sendPhoto(photo=url, caption=text). ZaloPersonalAdapter passes attachmentPath; sidecar send() adds content.attachments=[path] for zca-js. Composer: attach-image button + object-URL preview strip with remove + multipart submit (forceFormData); send disabled unless body-or-image. storage:link created. 2 new tests (sendPhoto path + one-of-required 422). | tests: 74 passed
- 2026-07-06 | Zalo image send fix | done | Bug: CRM stored image but Zalo never received it. Root cause = zca-js sendMessage with attachments requires an `imageMetadataGetter` option (Node can't read image dimensions natively) — it threw "Missing imageMetadataGetter". Added sidecar/image-meta.js (zero-dep PNG+JPEG header dimension reader, runnable self-check) and wired `imageMetadataGetter` into both `new Zalo(...)` calls via a shared zaloOptions. Verified real send: providerMessageId returned for /tmp path AND the space-containing storage path; full CRM outbox path went SENT. Also discovered the running sidecar/queue were stale (started before the code edits) — restart required to load changes. | tests: sidecar 4 pass
- 2026-07-06 | Zalo profile refresh + avatars | done | Contacts whose name was the raw UID (thread started outbound → no inbound name seen) can now be fixed. Sidecar GET /accounts/:id/user/:uid → zca-js getUserInfo → {displayName, avatar}. ContactController.refreshProfile pulls it via the Zalo identity and updates contact.full_name + avatar_url + identity. "Cập nhật hồ sơ Zalo" button on contact-show (shown when hasZalo). Avatars now render (AvatarImage) in inbox queue rows, thread header, and contact-show — avatarUrl added to conversation/activeConversation/contact payloads. Verified live: "Khách 4205766487069017020" → "Lệ Trinh" + avatar. | tests: 74 php + 4 sidecar
- (next: Low-pri UI: routing-queue management screen, Reverb realtime, multi-nick picker UI, media S3 mirror, group backfill. Telegram/Messenger profile+avatar deferred — Telegram has no arbitrary-user API, Messenger needs Graph profile_pic. Zalo avatar CDN url may expire — mirror to storage if that becomes a problem.)
