# 13 TikTok Shop Chat VN (Cut 1)

> Second new connector after spec 11 (Shopee). Adds `TIKTOK_SHOP` (TikTok
> Shop Partner API, VN region only) on top of the existing ChannelAdapter
> family. **Provider enum was extended with `TIKTOK_SHOP` in migration
> 2026_07_08_000001** so we don't re-touch migrations when cut 1 lands.
>
> Scope locked with Tùng (2026-07-08):
> 1. Chat only (no order/product/livestream events in cut 1)
> 2. VN region only
> 3. Auth model: OAuth 2.0 per seller (verify against current TikTok docs —
>    some lower tiers accept app-only auth for limited APIs)
> 4. Parallel build with Shopee cut 1 (shared infrastructure reused)
> 5. Webhook `NEW_MESSAGE` confirmed (Customer Service → New message;
>    payload has `message_id` + `conversation_id`)

## Goal

Sync TikTok Shop VN customer-service chat into CRM Inbox, allow agents to
reply from CRM, and expose enough provider health for admins to debug
without reading logs.

Non-goals for cut 1:

- Order tracking / status webhooks
- Product / inventory / livestream events
- Multi-region (US/UK/SEA)
- Shopee-side features we don't yet have parity for (typing/read)

## Shared adapter contract

No change. `TikTokShopAdapter` will implement the same 6-method contract
from spec 05/10/11. Resolved through `ChannelAdapterRegistry`.

## TikTok Shop Chat VN

### Endpoint

- `POST /webhooks/tiktok/{channelAccount:uuid}` for `NEW_MESSAGE` event.

Bound to `webhook.qrf.vn` via the same host binding already in place for
Shopee. No nginx changes needed — the route group already accepts new
`/webhooks/<provider>/*` entries.

### Auth model (VERIFIED)

OAuth 2.0 per-seller flow via **TikTok Shop Partner API**:

- **App-level** (per workspace, in `workspace_settings`):
  - `app_key` (TikTok Shop's public app identifier — NOT `app_id` or `client_id`)
  - `app_secret` (encrypted)
- **Per-channel-account** (after seller authorization):
  - `open_id` (TikTok Shop seller identifier)
  - `shop_id` (TikTok Shop shop identifier)
  - `seller_base_region` (e.g. "VN")
  - `access_token` (encrypted; ~24h TTL)
  - `refresh_token` (encrypted; long-lived, ~30+ days)
  - `access_token_expires_at`
  - `refresh_token_expires_at`
  - `granted_scopes` (array)

> **Partner program required:** TikTok Shop Partner API chat endpoints are
> gated behind Shop Partner approval + market eligibility (VN is eligible).
> Apply via https://partner.tiktokshop.com before W2.

### Authorization URL (VERIFIED)

`https://auth.tiktok-shops.com/api/v2/token/authorize`

Query params:
- `app_key` — your app's public identifier
- `state` — CSRF token (issue via `TikTokOAuthState`)

### Token endpoint (VERIFIED)

`https://auth.tiktok-shops.com/api/v2/token/get`

GET request with query params:
- `app_key`
- `app_secret`
- `auth_code` — from callback (NOT `code`)
- `grant_type` — value: `authorized_code` (NOT `authorization_code`)

Response:
- `access_token`
- `refresh_token`
- `refresh_token_expire_in` (unix seconds)
- `access_token_expire_in` (unix seconds; verify field name)
- `open_id`
- `shop_id` (or `seller_base_region` for region-only contexts)
- `seller_name`
- `granted_scopes`

### Refresh endpoint (VERIFIED)

Same endpoint `https://auth.tiktok-shops.com/api/v2/token/get` with
`grant_type=refresh_token` and the stored `refresh_token`. New tokens
issued; previous `refresh_token` may be rotated (store the new one).

### API base for Shop Partner operations (VERIFIED)

`https://open.tiktokglobalshop.com/api`

Chat endpoints:
- `POST /im/202412/send_message` — outbound
- `GET /im/202412/conversations` — list conversations
- Customer Service API overview: https://partner.tiktokshop.com/docv2/page/customer-service-api-overview

### Verification (HMAC) — VERIFIED (TikTok Open Platform scheme)

**VERIFIED** against TikTok Open Platform webhook docs (developers.tiktok.com
docs/webhooks-and-events + rollout.com integration guide). This is the
generic TikTok webhook signature format; the assumption is that TikTok Shop
Partner API uses the same scheme for the Customer Service webhooks. To be
re-validated when the first partner account is onboarded (see Risks T1).

- Header: `TikTok-Signature: t=<unix_ts>,s=<hex_digest>`
  - `t` = unix seconds at which the event was generated
  - `s` = HMAC-SHA256 hex digest of `${t}.${raw_body}` keyed by `app_secret`
    (i.e. the channel account's `webhook_secret`)
- Header: `TikTok-Timestamp: <unix_seconds>` (sent alongside; we trust the
  `t=` inside `TikTok-Signature` instead)
- Header: `TikTok-Client-Id: <app_key>` (optional; used to resolve the right
  secret when a workspace has multiple apps)
- Reject if `abs(now - t) > 300` (replay protection, configurable via
  `VerifyTikTokSignature::REPLAY_WINDOW_SECONDS`)
- Constant-time compare via `hash_equals`. `webhook_secret` never logged.

> **STILL TO VALIDATE (W3 first integration):** TikTok Shop Partner API's
> configuration-guide page mentions a signature code in the `Authorization`
> header for some endpoints. Confirm whether the Customer Service webhook
> delivery uses `TikTok-Signature: t=...,s=...` (assumed above) or the
> `Authorization` scheme before signing off the first real partner. If they
> differ, add a `VerifyTikTokShopPartnerSignature` variant — do not silently
> fall back to a weaker scheme.

### Idempotency

Primary key: `tiktok:{channel_account_id}:msg:{message_id}`.

Edits: TikTok repushes the same `message_id` with `version` increment
(verify field name). Update existing message in place rather than
duplicating.

Deletes: not pushed in cut 1; we do not support delete-backfill.

### Inbound mapping

```
TikTok NEW_MESSAGE webhook payload (assumed shape):
  event_type               -> "NEW_MESSAGE"
  message_id               -> provider_message_id
  conversation_id          -> provider_chat_id
  sender.open_id           -> provider_user_id
  sender.nickname          -> sender_display_name
  sender.avatar_url        -> sender_avatar_url
  message_type             -> "text"|"image"|"video"|"sticker"|...
                            cut 1: text + image only; others -> UNSUPPORTED
  content.text             -> body_text
  content.image_url        -> attachments[].url
  content.video_url        -> attachments[].url
  created_at               -> provider_timestamp (parse ISO-8601 or unix)
  shop_id                  -> provider_account_id (sanity check vs account)
```

Unsupported types (product card, order card, voucher, livestream invite)
→ persist as `IGNORED` in `webhook_events`, surfaced in admin health.

### Outbound send

Endpoint (verify): `POST /api/v1/im/send_message` (or current path).

Request shape:

```json
{
  "open_id": "...",
  "conversation_id": "...",
  "message_type": "text|image",
  "content": { "text": "..." } | { "image_url": "..." }
}
```

Image send: TikTok typically requires their CDN URL. Upload via the
im/media/upload endpoint first, then send with the returned URL.

Local-first contract (per spec 04/05/11):
1. Persist `messages` + `outbox_messages` row in one transaction.
2. Return 202 to admin UI.
3. Queue `SendTikTokMessageJob` on `queues:tiktok` priority 2.
4. On 200: stamp `provider_response`, mark `SENT`.
5. On auth_error: refresh + 1 retry, then `DEGRADED`.
6. On 429: respect `retry_after`.
7. On `recipient_blocked` / `conversation_closed`: `FAILED`, no retry.

### Token lifecycle

- `access_token` TTL: ~24h (verify). `RefreshTikTokAccessTokenJob` at 75%.
- `refresh_token` TTL: longer (verify). Refresh fail → `DEGRADED` +
  `REAUTH_REQUIRED`.
- Token values encrypted via `Crypt`. Never shown after save.

### Health

Channel account health check calls (verify paths):

- `GET /api/v1/shop/get_shop_info` — verify token + shop status.
- `GET /api/v1/im/conversation_list` — smoke the im endpoint.
- Local: last inbound webhook time from `webhook_events`.
- Admin health card: status / last inbound / pending msg count / last error.

## Admin recovery actions

| Action | Trigger | Effect |
|---|---|---|
| Test credentials | Manual | `get_shop_info`; shows shop name + status |
| Re-register webhook | Manual / secret rotation | Calls TikTok webhook registration endpoint |
| Refresh token | Manual / auto-retry fail | OAuth refresh; flag `REAUTH_REQUIRED` on fail |
| Reconnect TikTok | Manual after `REAUTH_REQUIRED` | Re-runs OAuth round-trip |
| Replay failed webhook | Manual | Re-runs `NormalizeTikTokInboundJob` for one event |
| Retry failed outbound | Manual | Re-queues `SendTikTokMessageJob` |
| Disable channel account | Manual | Status `DISABLED` |

## Retry policy (same shape as spec 11)

Inbound: retry transient DB/queue errors; don't retry invalid payload.
Outbound: retry network timeouts + 5xx + 429; 1 refresh+retry on
auth_error; permanent failure on `recipient_blocked` / `conversation_closed`
/ `recipient_not_found`.

Backoff: 1m, 5m, 15m, 1h. Max 5 attempts.

## Data model changes

The provider enum is already extended (migration 2026_07_08_000001).
The `contacts/leads/deals.source` enum is also extended
(migration 2026_07_08_000003). No further migration needed for cut 1.

`ChannelAdapterRegistry` already wires `TIKTOK_SHOP` → `TikTokShopAdapter`
(class currently throws — to be filled in W2-W4).

## Module / file layout (planned for W1+)

```
app/Modules/Channels/
  Adapters/
    TikTokShopAdapter.php                    # implement (cut 1 target)
  Http/
    Controllers/
      TikTokOAuthController.php              # W2
      ProviderWebhookController.php          # + tiktok() method (W3)
    Middleware/
      VerifyTikTokSignature.php              # W3
  Jobs/
    SendTikTokMessageJob.php                 # W4 (or reuse SendChannelMessageJob)
    RefreshTikTokAccessTokenJob.php          # W2
    NormalizeTikTokInboundMessageJob.php     # W3 (or generic via adapter)
  Services/Shopee/TikTokOAuthState.php       # W2
  Services/Shopee/TikTokTokenExchanger.php   # W2
  Services/Shopee/TikTokTokenException.php   # W2
  routes/tiktok.php                          # OAuth routes (W2)
```

`routes/web.php` adds one line under the existing webhook host binding:

```php
Route::post('webhooks/tiktok/{channelAccount}', [ProviderWebhookController::class, 'tiktok'])
    ->name('webhooks.tiktok');
```

OAuth routes (NOT on webhook host — tenant-scoped):

```php
Route::middleware(['auth', 'verified', 'workspace.member'])
    ->prefix('admin/channels/tiktok')
    ->name('admin.channels.tiktok.')
    ->group(function () {
        Route::get('connect', [TikTokOAuthController::class, 'connect'])->name('connect');
        Route::get('callback', [TikTokOAuthController::class, 'callback'])->name('callback');
    });
```

## Test plan (mirrors spec 11)

### Unit / adapter

- `TikTokShopAdapterTest::verify_webhook_accepts_valid_signature`
- `TikTokShopAdapterTest::verify_webhook_rejects_invalid_signature`
- `TikTokShopAdapterTest::verify_webhook_rejects_drifted_timestamp`
- `TikTokShopAdapterTest::extract_idempotency_key_uses_message_id`
- `TikTokShopAdapterTest::normalize_inbound_text_maps_to_canonical_shape`
- `TikTokShopAdapterTest::normalize_inbound_image_maps_attachments`
- `TikTokShopAdapterTest::normalize_inbound_unsupported_is_persisted`

### Integration (HTTP)

- `OmnichannelTikTokTest::webhook_ingest_creates_conversation_and_message`
- `OmnichannelTikTokTest::duplicate_message_id_does_not_double_ingest`
- `OmnichannelTikTokTest::oauth_callback_persists_tokens_encrypted`
- `OmnichannelTikTokTest::refresh_token_job_rotates_credentials`
- `OmnichannelTikTokTest::send_message_outbox_marks_SENT_on_200`
- `OmnichannelTikTokTest::send_message_outbox_retries_on_429`
- `OmnichannelTikTokTest::send_message_outbox_marks_FAILED_on_recipient_blocked`

### Smoke (live VN seller)

Documented in `docs/SHOPEE_SANDBOX_SETUP.md` (template will be cloned for
TikTok when sandbox access is available). For now: spec 13 doesn't gate
on live smoke — covered by cut 1 GA gate.

## Risks

| ID | Risk | Likelihood | Impact | Mitigation |
|----|------|-----------|--------|-----------|
| T1 | TikTok Shop Partner uses different signature scheme than Open Platform (Authorization header vs TikTok-Signature) | medium | high | Validate against first partner webhook; if mismatch, add separate middleware variant, do NOT silently fall back |
| T2 | Chat endpoints require partner tier not yet obtained | medium | high | Tùng confirms tier; fallback = spec 14 alternative scope |
| T3 | OpenAPI vs Shop Partner API confusion (different auth schemes) | medium | high | W1 spike: confirm which API we hit before coding |
| T4 | Token TTL shorter than expected, refresh too aggressive | low | medium | Job runs at 75% of TTL; configurable |
| T5 | `NEW_MESSAGE` event might not include all needed fields | medium | medium | Adapter has defensive mapping + UNSUPPORTED fallback |
| T6 | Region detection for VN shop_id | low | low | Cut 1 VN-only — no region router needed |
| T7 | Rate limit (TikTok Shop: typically 100 req/min/shop) | medium | medium | Outbox queues; admin health card surfaces pressure |

## Cut 1 milestones (parallel to Shopee cut 1)

| Week | Milestone | Status |
|------|-----------|--------|
| W1 (G0) | Spec 13 + 14 merged; TikTok auth model spike; signature scheme spike; TikTokShopAdapter skeleton + verify middleware skeleton | ✅ done 2026-07-08 |
| W2 (G1.1) | OAuth round-trip + state token + refresh job + DEGRADED/REAUTH_REQUIRED transition; unit + integration tests | ✅ done 2026-07-08 |
| W3 (G1.2) | Inbound webhook controller + HMAC verify + idempotency + edit handling + unsupported routing | ✅ done 2026-07-08 (commit `feat(tiktok): W3 — adapter inbound + webhook + signature middleware`) |
| W4 (G1.3) | Outbound send + retry policy + admin health card; cut 1 ready for first VN shop pilot | ✅ done 2026-07-08 (commit `feat(tiktok): W4 — retry_after + admin health card + TikTok UI`) |

Cut 2 (post-cut-1 GA): typing/read indicators, attachment round-trip beyond
images, cold-start conversation sync, shop-owner onboarding UX.

Cut 3 (later still): TikTok non-VN regions, livestream chat.