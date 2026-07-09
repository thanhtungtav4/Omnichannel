# 15 Contacts Ingestion & Round-2 UI

> **Re-scope of the CRM Core contacts module** to (a) close the UI/UX gaps that
> are obvious from the current `admin/contacts.tsx` + `admin/contact-show.tsx`,
> and (b) accept two new ingestion sources coming next quarter — **website
> public forms** and **Zalo OA Mini App** — through a single chokepoint so the
> next connector doesn't need another schema migration.
>
> Driven by real-world demand: the sales side keeps getting web leads that
> disappear because there's no Contact created, and the Zalo OA push to ship
> a Mini App for repeat buyers means we need 2-way contact traffic, not just
> 1-way webhook ingestion.

## Scope locked with Tùng (2026-07-09)

1. **UI gaps ship first.** Search/filter/pagination on the list page, status
   + owner change on the detail page, edit-note, "create lead from contact".
2. **Schema + service refactor** lands as a single cut before any new connector.
   `Crm\Services\ContactIngestor` becomes the only path that writes a `Contact`
   row; `Channels\Services\InboundMessageIngestor` is refactored to call it
   (no behavior change for existing Zalo/Telegram traffic).
3. **Public ingest API + workspace tokens.** One endpoint, many sources.
   Tokens are scoped per source (`allowed_sources`) so a leaked website-form
   token can't be used to forge a Zalo Mini App event.
4. **Website form** uses the public endpoint with a per-site token. **Zalo OA
   Mini App** uses the same endpoint with a separate token plus HMAC
   signature verification against the OA app secret. Re-engagement CRM →
   Mini App ships in the same cut: a `MiniAppOutboundNotifier` job + table.
5. **Merge contacts** is the last cut. Uses `phone_normalized` and the
   `external_identities` table to dedup. Re-points identities, conversations,
   leads, notes, audit log.
6. Web form does **NOT** auto-create a lead — sales opens the lead manually
   from the contact record.
7. **1 workspace, many websites.** Each website gets its own token; same
   workspace, sources distinguished by `source_detail`.
8. No file attachments in cut 1 (text-only fields). Reopen when needed.
9. Consent is **informational** — log IP, user-agent, page URL, timestamp.
   No opt-in checkbox gating. Schema still has the columns in case law
   changes.
10. Re-engagement CRM → Mini App is **yes** (template-message send via the
    Zalo OA Notification Service API when domain events fire).

## Non-goals for this spec

- **Companies table** is already declared in `03_DATA_MODEL.md` but not built;
  the "Link company" action in `07_ADMIN_UI.md § Contacts` stays future work.
- File attachments on forms (deferred — re-spec when first real ask arrives).
- Strict opt-in consent UI (PDPA-style checkbox).
- Multi-region Zalo Mini App (VN-only, mirrors Shopee/TikTok posture).
- Building the Zalo Mini App itself — this spec only handles the CRM side
  that the Mini App talks to.
- Outbound marketing / campaign sends to Mini App (only transactional
  notifications tied to a domain event).

---

## Source matrix

| Source            | Auth                | Tenant resolve | Auto-create Lead? | Attributes shape              | Notes |
|-------------------|---------------------|----------------|-------------------|-------------------------------|-------|
| `MANUAL`          | user JWT            | current user   | no (separate action) | `{}`                          | Admin UI |
| `TELEGRAM`        | webhook + secret    | channel account → workspace | yes | provider msg id, chat id      | Existing |
| `ZALO_PERSONAL`   | webhook + sidecar   | channel account → workspace | yes | provider msg id, chat id      | Existing |
| `ZALO_OA`         | webhook + OA secret | channel account → workspace | yes | OA event payload              | Future connector |
| `FACEBOOK`        | webhook + verify    | channel account → workspace | yes | sender PSID, page id          | Future connector |
| `IMPORT`          | CSV upload (admin)  | current user   | no               | row import metadata           | Future |
| `API`             | user JWT            | current user   | no               | caller-provided               | Future |
| `WEBSITE_FORM`    | **public token**    | token → workspace | **no**        | page URL, UTM, referrer, form fields | **C3+C4** |
| `ZALO_MINIAPP`    | **public token + HMAC** | token → workspace | no        | mini app event type, app id, official user id | **C3+C4** |

> ponytail: `IMPORT` and `API` are scoped but not in this cut — added to the
> source enum doc only so we don't re-touch the migration when they ship.

> ponytail: Zalo Mini App events that look like leads (form_submit with
> high-intent fields) are NOT auto-converted to leads because of scope
> lock #6. The Mini App can ship a `LEAD_INTENT` event type later — we'll
> re-discuss owner strategy when the first campaign lands.

---

# Cut 1 — UI Gaps (ship first)

## Backend

### AdminController::contacts — query params

```
GET /admin/contacts
  ?q=<text>          # search full_name, phone, email (case-insensitive ILIKE)
  &status=ACTIVE     # exact match; comma-separated multi
  &source=WEBSITE_FORM,ZALO_MINIAPP
  &owner_id=<uuid>   # or "null" for unassigned
  &tag=VIP           # exact match against contacts.tags JSONB
  &sort=last_inbound_at|full_name|created_at
  &dir=desc|asc
  &page=1
  &per_page=25       # 25 / 50 / 100
```

Default sort: `last_inbound_at desc` (matches current behavior).

Pagination response shape (Inertia-friendly):
```php
[
  'data' => [...],                  // existing mapped shape
  'meta' => ['page' => 1, 'perPage' => 25, 'total' => 312, 'lastPage' => 13],
  'filters' => ['q' => ..., 'status' => [...], 'source' => [...], ...],
]
```

`AdminController::contacts` becomes `LengthAwarePaginator` instead of
`->get()`. Frontend already uses Inertia — just iterate `contacts.data`.

### Status & owner change endpoints

```
PUT /api/admin/contacts/{contact}/status
  body: { status: ACTIVE|ARCHIVED|BLOCKED }

PUT /api/admin/contacts/{contact}/owner
  body: { owner_id: uuid|null }
```

Both live in `ContactController`. Both use the existing workspace guard.
Owner change requires `crm.contacts.update` permission (owner/admin).
Status change anyone with view perm (operators archive spam).

### Edit note

```
PUT /api/admin/contact-notes/{note}
  body: { body: string, pinned: boolean }
```

Author can edit own note; owner/admin/support_lead can edit any (parity
with existing `destroyNote`).

### "Create lead from contact"

```
POST /api/admin/contacts/{contact}/leads
  body: { title: string, value_amount?: number }
```

Creates a Lead linked to this contact, source = contact's source (or
`MANUAL` if contact source is `WEBSITE_FORM`/`IMPORT` — sales opened it).
Default stage = first stage of default pipeline.
Default owner = current user (sales).

Lives in `LeadController` (new method `createFromContact`). Returns 201 +
`{ lead_id }`.

## Frontend

### `admin/contacts.tsx` — list page

Add above the table:
- Search `Input` (debounced 250ms, hits `?q=`).
- Filter row: `Select` for status, `Select` for source, `Select` for owner,
  `Select` for tag.
- Sort headers (click to toggle, default unsorted indicator).
- Pagination footer (`<Pagination>` from shadcn).

Add to table (per spec `07_ADMIN_UI.md § Contacts`):
- **Tags column** — first 3 chips + "+N" overflow.
- **Status column** — explicit (currently buried in name cell).
- **Active lead/deal column** — title + status badge; "—" if none.

### `admin/contact-show.tsx` — detail page

Header:
- Replace static `StatusBadge` for status with a `DropdownMenu` that POSTs
  to the new status endpoint.
- Replace static owner text with an `OwnerPicker` component (combobox over
  workspace agents). Reused on leads page later.

Notes section:
- Inline edit: click note body → becomes `Textarea` + Save / Cancel.
- Delete stays as AlertDialog.

New "Sales" section between Notes and Conversations:
- Lists existing leads (already there) + a "Create lead" button that opens
  a small `Dialog` (title + value_amount). Submits to new endpoint, then
  reloads.

"Origin" card (under Profile, on the left rail) — only renders once
`contact.source` ∈ {WEBSITE_FORM, ZALO_MINIAPP, IMPORT, API} and `attributes`
is non-empty:
- Source + source_detail as `StatusBadge`s.
- Collapsible "Original payload" `<pre>` block.

## Acceptance criteria (C1)

- [ ] Search "0912" returns contacts whose phone contains it (case-insensitive).
- [ ] Filter `status=ACTIVE` + `source=WEBSITE_FORM` returns intersection.
- [ ] Pagination renders correctly at 25 / 50 / 100 per page; URL reflects state.
- [ ] Column sort toggles; second click reverses direction.
- [ ] Status dropdown on contact-show updates server, no full reload.
- [ ] Owner picker dropdown updates server, audit log entry created.
- [ ] Note edit preserves `pinned` state and author.
- [ ] "Create lead" from contact adds lead linked to contact, navigates to
      leads page (or refreshes the leads section).
- [ ] List page shows Tags + Status + Active lead columns per spec.
- [ ] All existing tests still green (`php artisan test`).
- [ ] Manual smoke: 100+ seeded contacts → list page interactive < 300ms.

---

# Cut 2 — Schema + `ContactIngestor` refactor

## Schema migration

`contacts` adds:

```php
$table->jsonb('attributes')->default('{}');
$table->string('source_detail', 120)->nullable();      // e.g. "summer-sale-2026"
$table->timestampTz('consent_given_at')->nullable();
$table->string('consent_text', 500)->nullable();        // text the user agreed to
$table->ipAddress('consent_ip')->nullable();             // nullable for IPv6
$table->text('consent_user_agent')->nullable();
```

`source` enum doc extended (no SQL change, just PHP enum class):
add `WEBSITE_FORM`, `ZALO_MINIAPP`.

Index on `source_detail` only if we ever filter by it; defer.

`leads.source` enum doc also extended (parity).

> ponytail: jsonb default `'{}'` not `'null'` — `attributes` access from
> Eloquent casts always returns array.

## Service: `App\Modules\Crm\Services\ContactIngestor`

Single chokepoint for creating or matching a Contact. All ingestion paths
call it.

```php
final class ContactIngestor
{
    public function __construct(
        private IdentityMatcher $identities,
        private OwnerResolver $owners,
        private ConsentLogger $consent,
        private TimelineWriter $timeline,
    ) {}

    /**
     * @param  array{
     *   workspace_id: string,
     *   source: string,                    // MANUAL | TELEGRAM | ZALO_PERSONAL | ...
     *   source_detail?: ?string,
     *   full_name?: ?string,
     *   phone?: ?string,
     *   email?: ?string,
     *   avatar_url?: ?string,
     *   external_identity?: ?array{provider, provider_account_id, provider_user_id, display_name, avatar_url},
     *   attributes?: array,
     *   consent?: ?array{given_at, text, ip, user_agent},
     *   ingest_event_id?: ?string,         // for dedup via contact_ingest_events
     *   owner_strategy?: ?array,           // workspace_settings.ingest.* override
     * }  $payload
     */
    public function ingest(array $payload): Contact
    {
        // 1. dedup check (contact_ingest_events) — early return existing
        // 2. identity match: external_identities > phone_normalized > email > create
        // 3. write contact + optional new identity row
        // 4. write attributes + consent fields
        // 5. resolve owner per strategy (default UNASSIGNED for non-routed sources)
        // 6. write ingest_events row
        // 7. write TimelineActivity (CONTACT_INGESTED)
        // 8. return Contact
    }
}
```

Subservices are small + composable. Identities and Consent are pure
helpers; OwnerResolver reads `workspace_settings.ingest.*` (uses
`App\Modules\Platform\Services\WorkspaceSettings` already in tree).

## Refactor: `InboundMessageIngestor`

Existing `Channels\Services\InboundMessageIngestor` (the cross-module
exception documented in `AGENTS.md`) keeps its atomic-ingest transaction
shape but inside that transaction it now calls `ContactIngestor::ingest()`
instead of writing to `Contact` directly. Behavior for existing
Zalo/Telegram traffic is unchanged — same fields, same dedup, same lead
auto-create. Verified by the existing 75+ tests.

## `TimelineActivity` writer

`ContactIngestor` writes one row per ingest event with:
- `actor_type = 'SYSTEM'`
- `actor_id = null`
- `verb = 'contact.ingested'`
- `subject_type = 'contact'`, `subject_id = <new or existing>`
- `metadata = { source, source_detail, ingest_event_id, identity_provider }`

This is the seed for the unified timeline view (Cut 5 will add the UI).

## Acceptance criteria (C2)

- [ ] New migration is additive; existing rows have `attributes = '{}'`, all
      new columns null, app boots.
- [ ] Zalo/Telegram webhook tests pass without modification (refactor is
      behavior-preserving).
- [ ] `ContactIngestor::ingest()` unit-tested for:
  - first-time contact create
  - match by phone_normalized across sources
  - match by external_identity (same user from 2 channels)
  - dedup via `ingest_event_id` returns existing contact, no new row
- [ ] `timeline_activities` row written per ingest, queryable per contact.
- [ ] Source enum doc test asserts new values are allowed.

---

# Cut 3 — Public ingest API + workspace tokens

## New tables

```php
// workspace_ingest_tokens — public-issued API tokens per workspace
Schema::create('workspace_ingest_tokens', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('workspace_id');
    $t->string('name', 120);              // "Landing page mùa hè"
    $t->string('token_prefix', 12);       // visible: "whk_a1b2c3" (first 8 chars)
    $t->string('token_hash', 120);        // bcrypt
    $t->jsonb('allowed_sources');         // ["WEBSITE_FORM"]
    $t->jsonb('hmac_secret_hash')->nullable();  // for ZALO_MINIAPP tokens; HMAC secret
    $t->jsonb('default_owner_strategy')->nullable(); // override workspace default
    $t->jsonb('default_source_detail')->nullable();  // default value if form omits
    $t->string('domain_whitelist', 500)->nullable(); // comma-separated
    $t->unsignedSmallInteger('rate_limit_per_minute')->default(60);
    $t->timestampTz('last_used_at')->nullable();
    $t->timestampTz('expires_at')->nullable();
    $t->timestampTz('revoked_at')->nullable();
    $t->timestamps();
    $t->index(['workspace_id', 'revoked_at']);
});

// contact_ingest_events — dedup + audit per source event
Schema::create('contact_ingest_events', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('workspace_id');
    $t->uuid('contact_id');
    $t->string('source', 40);             // MANUAL | WEBSITE_FORM | ZALO_MINIAPP | ...
    $t->string('source_event_id', 200);   // provider msg id / Idempotency-Key / form submission id
    $t->string('payload_hash', 64);       // sha256 of canonical payload
    $t->ipAddress('ip')->nullable();
    $t->text('user_agent')->nullable();
    $t->timestampTz('received_at');
    $t->unique(['workspace_id', 'source', 'source_event_id']);
    $t->index(['contact_id', 'received_at']);
});

// contact_ingest_failures — for ops to debug / retry
Schema::create('contact_ingest_failures', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('workspace_id');
    $t->uuid('token_id')->nullable();     // null = manual / unknown
    $t->string('source', 40);
    $t->jsonb('payload');                 // raw request body
    $t->text('error', 1000);
    $t->ipAddress('ip')->nullable();
    $t->timestampTz('received_at');
    $t->timestampTz('resolved_at')->nullable();
    $t->index(['workspace_id', 'received_at']);
    $t->index(['resolved_at']);
});
```

## Routes

```
# Public — tenant resolved via token, NOT subdomain
POST /api/public/ingest/contact
  headers:
    X-Workspace-Key: <token>          # required
    X-Source: WEBSITE_FORM | ZALO_MINIAPP   # required, must be in token.allowed_sources
    X-Source-Detail: <optional string> # form-side override; otherwise token.default_source_detail
    X-Source-Event-Id: <optional uuid|string> # provider msg id or client idempotency key
    X-Signature: <hex>                # required for ZALO_MINIAPP only
  body:
    {
      "full_name": "...",
      "phone": "...",
      "email": "...",
      "external_identity": {         // optional, presence triggers identity merge path
        "provider": "ZALO_OA",
        "provider_account_id": "...",
        "provider_user_id": "...",
        "display_name": "...",
        "avatar_url": "..."
      },
      "attributes": { ... },         // free-form; validated per source
      "consent": {                   // optional; informational
        "given_at": "2026-07-09T...",
        "text": "...",
        "ip": "...",                 // server overrides with X-Forwarded-For chain
        "user_agent": "..."
      }
    }
  responses:
    201 { "contact_id": "...", "created": true,  "ingest_event_id": "..." }
    200 { "contact_id": "...", "created": false, "ingest_event_id": "..." }  # dedup hit
    401 invalid / revoked / expired token
    403 source not allowed for token
    409 X-Source-Event-Id reused with different payload hash (collision, not idempotency)
    422 validation errors
    429 rate limited
```

```
# Admin — token management
GET    /api/admin/ingest-tokens                       # list
POST   /api/admin/ingest-tokens                       # mint (returns plaintext once)
DELETE /api/admin/ingest-tokens/{token}               # revoke (soft)
POST   /api/admin/ingest-tokens/{token}/rotate        # new plaintext, old revoked
```

## Auth: `X-Workspace-Key`

- Plaintext format: `whk_<32-char base32>` (e.g. `whk_a1b2c3d4e5...`).
  - Prefix `whk_` reserved for form tokens; future `zmp_` for Mini App
    tokens lets us tell them apart at a glance.
- DB stores `bcrypt(token)` in `token_hash` and `token_prefix` (first 8
  chars) for UI display + grep.
- Lookup: hash check + `revoked_at IS NULL` + (`expires_at IS NULL OR
  expires_at > now()`).

## Auth: HMAC for `ZALO_MINIAPP` tokens

When `source = ZALO_MINIAPP`:
- Header `X-Signature: t=<unix>,s=<hex>` (TikTok-style format we already
  use in `13_TIKTOK_SHOP_VN.md`).
- Signed string: `"{unix}.{raw_body}"`, HMAC-SHA256 with the
  `hmac_secret` (the Zalo OA app secret, stored hashed via Laravel
  `Crypt`).
- 5-minute timestamp skew check.

> ponytail: Zalo OA Mini App's official signed-request scheme is the OA
> app's secret used to sign outbound calls from the Mini App server to
> the partner backend. We treat the OA secret as a per-token HMAC secret,
> same shape as TikTok Shop.

## Rate limiting

- Per-IP: `throttle:10,1` (nginx-level via existing setup).
- Per-token: `RateLimiter::for('ingest.token', fn ($r) => Limit::perMinute($r->attributes->get('token')->rate_limit_per_minute))`.
- Per-workspace total: `throttle:1000,60` (configurable via
  `workspace_settings.ingest.workspace_per_hour`).

## Validation rules per source

Hardcoded map in `ContactIngestor::rulesFor(string $source)`:

| Field            | MANUAL  | TELEGRAM | ZALO_PERSONAL | WEBSITE_FORM | ZALO_MINIAPP |
|------------------|---------|----------|---------------|--------------|---------------|
| full_name        | req     | opt      | opt           | req          | opt           |
| phone            | opt     | opt      | opt           | opt          | opt           |
| email            | opt     | opt      | opt           | opt          | opt           |
| external_identity| —       | req      | req           | opt          | opt           |
| attributes       | —       | —        | —             | opt (URL, UTM) | opt (event_type, app_id) |
| consent          | —       | —        | —             | opt          | opt           |

Email/phone format same as existing rules.

## Settings UI (admin)

New page `admin/settings/integrations.tsx`:
- Section "Website forms" — list of `workspace_ingest_tokens` (name,
  prefix, allowed sources, rate limit, last used, status). Buttons:
  Mint, Revoke, Rotate.
- Section "Zalo Mini App" — same shape; columns add "HMAC required".
- Empty state explains how to plug a form / Mini App onto a new token.

Tokens are **not** shown in plaintext again after mint. UI shows the
prefix; ops copy the plaintext from the "Newly minted" toast.

## Acceptance criteria (C3)

- [ ] Mint a token, copy plaintext once, page never shows it again.
- [ ] `POST /api/public/ingest/contact` with valid token returns 201 on
      first call, 200 on duplicate `X-Source-Event-Id` (same payload).
- [ ] Duplicate `X-Source-Event-Id` with **different** payload → 409 and
      a `contact_ingest_failures` row.
- [ ] Revoked token → 401.
- [ ] Token with `allowed_sources = ["WEBSITE_FORM"]` called with
      `X-Source: ZALO_MINIAPP` → 403.
- [ ] Mini App call without signature → 401; with stale signature → 401.
- [ ] Rate limit per-token: 61st call within 60s returns 429.
- [ ] `contact_ingest_events` has 1 row per successful ingest; dedup
      re-uses existing row, no contact re-creation.
- [ ] Settings UI lists / mints / revokes tokens; rotates update
      `token_hash` and set `revoked_at` on old.
- [ ] Failure path: invalid phone format → 422, no `Contact` row, but
      `contact_ingest_failures` row written with `error`.

---

# Cut 4 — Website form connector + Zalo Mini App connector

## Website form

### Frontend snippet (vanilla JS, drop-in)

`resources/js/integrations/qrf-web-form.js` — single file, no deps,
~3 KB minified. Reads `data-*` attrs on a `<form>`:

```html
<form data-qrf-form
      data-endpoint="https://crm.qrf.vn/api/public/ingest/contact"
      data-token="whk_a1b2c3..."
      data-source-detail="summer-sale-2026">
  <input name="full_name" required>
  <input name="phone">
  <input name="email">
  <input name="message">         <!-- becomes attributes.message -->
  <input name="utm_source">      <!-- becomes attributes.utm_source -->
  <input name="utm_campaign">    <!-- becomes attributes.utm_campaign -->
  <button>Submit</button>
</form>
<script src="/path/to/qrf-web-form.js" defer></script>
```

Behavior:
- Generate `source_event_id = crypto.randomUUID()` once per page load.
- On submit: build payload, send `fetch(endpoint, { method:'POST',
  headers: { 'Content-Type':'application/json', 'X-Workspace-Key':
  token, 'X-Source':'WEBSITE_FORM', 'X-Source-Detail': ..., 'X-Source-
  Event-Id': source_event_id }, body: JSON.stringify(payload) })`.
- `form.elements.message.value` + all `data-attribute` field names →
  `attributes` object.
- Success → display server-rendered `data-success-template` element,
  hide form. Failure → `data-error-template`.
- Retry-safe: same `source_event_id` is reused on resubmit; server
  returns 200 (existing contact) without erroring.

> ponytail: the snippet is intentionally framework-free; sites use
> anything from plain HTML to React/Next.js. We avoid a heavy SDK.

### Multi-site = multi-token (scope lock #7)

Each site embeds the snippet with **its own token**. The token's
`default_source_detail` (e.g. `summer-sale-2026`) auto-fills if the form
doesn't override. Filtering contacts by source gives per-site counts.

### Server-side correlation

`attributes` always carries `page_url`, `referrer`, `user_agent`, `ip`,
`submitted_at` so even if the snippet fails to extract a named field, the
contact record shows where it came from.

## Zalo OA Mini App

### Mini App → CRM (ingestion)

The Mini App's backend server (running on Zalo or on the workspace's own
infra) POSTs to `/api/public/ingest/contact` with:
- `X-Workspace-Key`: Mini App token (configured in OA dashboard).
- `X-Source`: `ZALO_MINIAPP`.
- `X-Source-Event-Id`: OA event id (or Mini App-generated UUID).
- `X-Signature`: HMAC of body using OA app secret (see Cut 3).
- Body:
  ```json
  {
    "full_name": "Nguyễn Văn A",        // from OA profile (if granted)
    "phone": "0912...",
    "external_identity": {
      "provider": "ZALO_OA",
      "provider_account_id": "<oa_id>",
      "provider_user_id": "<user_id>",
      "display_name": "...",
      "avatar_url": "..."
    },
    "attributes": {
      "app_id": "...",
      "event_type": "form_submit" | "purchase_intent" | "custom",
      "payload": { ... event-specific ... }
    }
  }
  ```

Identity match path: if the user is already known as `ZALO_PERSONAL`
contact via phone, the OA identity is added to the same contact
(`external_identities` already supports multiple providers per
contact). If unknown, contact created with ZALO_OA identity.

### CRM → Mini App (re-engagement)

Scope lock #10: yes, ship notification path.

New service `App\Modules\Channels\Services\MiniAppOutboundNotifier`:

```php
final class MiniAppOutboundNotifier
{
    public function notifyContact(Contact $c, string $templateCode, array $params = []): void
    {
        $identity = $c->identities->firstWhere('provider', 'ZALO_OA');
        if (! $identity) return;       // user has no OA identity → can't notify
        MiniAppNotificationJob::dispatch($c->workspace_id, $identity->provider_user_id, $templateCode, $params);
    }
}
```

New job `App\Jobs\MiniAppNotificationJob`:
- Looks up the workspace's Zalo OA channel account (`provider =
  ZALO_OA`, the OA app id).
- Calls `ZaloOaAdapter::sendTemplateMessage($oaUserId, $templateCode,
  $params)` — adapter already declared in spec 05 / 10.
- Logs to `outbound_miniapp_notifications` (new table below).

Trigger surfaces (initial):
- `LeadController::updateStatus` → emit `LeadStatusChanged` event →
  listener calls `MiniAppOutboundNotifier` if `params.notify_user = true`
  (UI toggle on lead-update dialog).
- `ContactController::destroy` (soft-delete path) → emit
  `ContactArchived` → notifier sends "we removed your data" template.

### New table: `outbound_miniapp_notifications`

```php
Schema::create('outbound_miniapp_notifications', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('workspace_id');
    $t->uuid('contact_id');
    $t->string('oa_user_id', 100);
    $t->string('template_code', 80);
    $t->jsonb('params');
    $t->string('status', 20);          // QUEUED | SENT | FAILED
    $t->text('last_error', 500)->nullable();
    $t->unsignedSmallInteger('attempts')->default(0);
    $t->timestampTz('queued_at');
    $t->timestampTz('sent_at')->nullable();
    $t->index(['workspace_id', 'status', 'queued_at']);
});
```

> ponytail: only fired when the Mini App's OA is **also** a CRM
> `ChannelAccount` in this workspace — otherwise no `ZaloOaAdapter`
> available, the job no-ops with an audit row at `status=FAILED,
> error='no OA channel account'`. This avoids surprise cost on
> accidental config.

### Mini App permission model

- Mini App token has `allowed_sources = ["ZALO_MINIAPP"]` AND
  `hmac_secret_hash` set.
- Mini App token can ONLY notify via `MiniAppOutboundNotifier` (it can't
  hit any other endpoint because there's no token for it). Defense in
  depth.
- Notify template codes live in `workspace_settings.miniapp.templates`
  JSON (mapping `code → { oa_template_id, default_params }`).

## Acceptance criteria (C4)

- [ ] Drop the snippet on a static HTML page; submission creates a
      Contact with source=WEBSITE_FORM, source_detail=token default,
      attributes contains all `data-attribute` fields + page URL.
- [ ] Same snippet on a second page (different `data-source-detail`)
      creates contacts in same workspace but distinguishable by
      `source_detail`.
- [ ] Mini App POST with valid HMAC creates contact; wrong HMAC → 401.
- [ ] Mini App POST with phone matching an existing `ZALO_PERSONAL`
      contact adds a ZALO_OA identity to the same contact (not a new
      row). Verified via `external_identities` query.
- [ ] Mini App POST with no phone / no email / no name still creates
      contact with identity-only (display_name from Zalo profile).
- [ ] Triggering `LeadController::updateStatus` with notify toggle on
      enqueues `MiniAppNotificationJob`; job picks the workspace's OA
      channel account, calls adapter, writes
      `outbound_miniapp_notifications` row with status SENT.
- [ ] Triggering for a contact without ZALO_OA identity → notifier
      no-ops, no `outbound_miniapp_notifications` row.
- [ ] `MiniAppNotificationJob` retries 3× on transient failure, then
      marks FAILED with last_error.

---

# Cut 5 — Merge contacts

## Service: `App\Modules\Crm\Services\ContactMerger`

```php
final class ContactMerger
{
    /**
     * Merge $losers into $winner. $winner survives; $losers are deleted
     * after their rows are re-pointed.
     *
     * Re-points: external_identities, conversations, leads, contact_notes,
     * timeline_activities (subject_id stays; we don't merge timeline rows,
     * they stay attributed to whoever created them).
     *
     * Audit: writes an audit_log row { action: 'contacts.merged',
     * subject: winner, metadata: { loser_ids, field_diffs } }.
     */
    public function merge(Contact $winner, Collection $losers): Contact;
}
```

Conflict policy (when winner and loser differ on a non-empty field):
- `full_name`: prefer non-empty; if both non-empty, prefer the one
  with more external_identities (more trusted source).
- `phone` / `email`: union (lose nothing).
- `avatar_url`: prefer the one with more external_identities.
- `attributes`: shallow merge, loser's keys win (most recent input).
- `owner_id`: keep winner's.
- `tags`: union.
- `status`: keep the most permissive (ACTIVE > ARCHIVED > BLOCKED).
- `last_inbound_at`: max.

## UI

New page `admin/contacts.merge` (or modal over list):
- Search box → pick a "winner" contact.
- Show "Possible duplicates" computed server-side:
  - Same `phone_normalized`, OR
  - Same email (case-insensitive), OR
  - Multiple identities with overlapping phone/email/avatar.
- Pick one or more "losers".
- Preview panel: side-by-side field comparison with conflict markers
  using the policy above.
- Confirm → POST to `/api/admin/contacts/{winner}/merge` with
  `{ loser_ids: [...] }`.
- After merge: losers gone, winner has combined data, redirect to
  winner's detail page.

## Routes

```
POST /api/admin/contacts/{winner}/merge
  body: { loser_ids: [uuid, ...] }

GET /api/admin/contacts/duplicates
  returns: [{ winner_suggestion, candidates: [...] }, ...]
```

## Acceptance criteria (C5)

- [ ] Two contacts with same phone_normalized show up in
      `/api/admin/contacts/duplicates`.
- [ ] Preview reflects conflict policy: union phone/email, prefer
      fuller name, etc.
- [ ] Merge re-points `external_identities`, `conversations`, `leads`,
      `contact_notes`. After merge, losers return 404.
- [ ] Audit log row written.
- [ ] Cannot merge a contact into itself (422).
- [ ] Cannot merge across workspaces (403).
- [ ] Owner-only action (`crm.contacts.merge` per spec 08).

---

# Cross-cutting concerns

## Tenant isolation (re-stated)

All public ingest operations **must** resolve the workspace from the
token, **never** from the request host. Public endpoints do not pass
through `workspace.required` / `workspace.member` middleware — they
authenticate via the token, then pin the workspace for the rest of the
request via a custom `PinWorkspaceFromToken` middleware.

## Audit log

Every public ingest writes an `audit_logs` row (already declared in
spec 03). Add `module = 'crm'`, `action = 'contact.ingested'`, payload
in `metadata` (token id, source, source_event_id, contact_id, ip).

## Observability

- Counter `ingest.{source}.{result}` per workspace (created / dedup /
  failed) — exposed via Laravel Telescope in dev; prod uses existing
  `audit_logs` query.
- Daily job (existing workspace stats dispatcher) adds a row to
  `workspace_daily_stats` with `contacts_created_by_source` JSONB.

## Test plan

- C1: feature test for each new endpoint + Playwright check on list
  pagination interaction.
- C2: unit test for `ContactIngestor` (each branch); existing 75+
  integration tests stay green.
- C3: feature tests for mint/rotate/revoke; rate-limit test using
  `RateLimiter::fake()`; HMAC signature test for Mini App path.
- C4: Playwright e2e — static HTML page + form submit; Mini App path
  simulated via `Http::fake()`.
- C5: merge feature test verifying re-pointing + audit row.

## Open questions deferred to implementation

- `workspace_settings.ingest.*` default values when the row doesn't
  exist yet. Default to `UNASSIGNED` for non-routed sources.
- Token UI placement: settings > integrations vs settings > API. Will
  pick based on what fits the existing settings shell (see
  `routes/settings.php`).
- Whether to ship C1 + C2 together as a single PR or separate. C1 is
  purely additive UI; C2 is a refactor of a hot path. Lean separate
  for safer rollback, even though C2 enables C3.
- "Origin" card in C1 — ship with C1 even though `attributes` only
  appears in C2. C1 cards are guarded on `attributes` being
  non-null/undefined, so they just don't render until C2 lands.

## References

- `specs/02_MODULE_STRUCTURE.md` — modular boundaries; `Crm\Services`
  host the new `ContactIngestor` + `ContactMerger`.
- `specs/03_DATA_MODEL.md` — base `contacts` + `external_identities`
  + `timeline_activities` schema (extended in C2).
- `specs/04_OMNICHANNEL_INBOX.md` — left-rail contact context (touched
  only if the Origin card expands to live there too; not in this cut).
- `specs/05_CONNECTORS_ZALO_TELEGRAM.md` — existing adapter contract
  that `MiniAppOutboundNotifier` re-uses via `ZaloOaAdapter`.
- `specs/07_ADMIN_UI.md § Contacts` — list columns + actions; this
  spec is the implementation map.
- `specs/08_RBAC.md` — permissions `crm.contacts.view`,
  `crm.contacts.update`, `crm.contacts.merge` referenced throughout.
- `specs/10_OMNICHANNEL_SUPPORT_PLAN.md` — `phone_normalized` dedup
  story; the merge service is the operation that consumes the
  duplicate-detection work that plan deferred.
- `specs/13_TIKTOK_SHOP_VN.md` — `X-Signature: t=<unix>,s=<hex>`
  HMAC format reused for Mini App tokens.