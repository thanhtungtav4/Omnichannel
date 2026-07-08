# 11 Shopee Chat VN (Cut 1)

> **First new connector after spec 05/10.** Adds `SHOPEE` (Shopee Open
> Platform v2, VN region only) to the existing `ChannelAdapter` family.
> TikTok Shop Chat (`TIKTOK_SHOP`) is scoped out for a later cut — kept as a
> future provider enum value so we don't re-touch the migration.
>
> Scope locked with Tùng (2026-07-08):
> 1. Shopee first, then TikTok Shop later
> 2. **Chat only** in this cut (no order tracking, no product card, no ads)
> 3. **VN region only** (`shopeemobile.vn` / `partner.shopeemobile.com/api/v2`)
> 4. Target: August 2026

## Goal

Sync Shopee VN seller chat into CRM Inbox, allow agents to reply from CRM,
and expose enough provider health for admins to debug without reading logs.

Non-goals for this cut:

- Order tracking / status updates
- Product card / catalog reply shortcuts
- Multi-region (SG/MY/TH/ID/PH/TW/BR/MX)
- TikTok Shop Chat (planned cut 2, separate spec revision)

## Shared adapter contract

No change to the `ChannelAdapter` interface from spec 05/10. Shopee
implements the same six methods. The `ChannelAdapterRegistry` resolves by
provider enum.

## Shopee Chat VN

### Endpoint

- `POST /webhooks/shopee/{channelAccount:uuid}`

Bound to `webhook.qrf.vn` via the host-binding added in spec
`OPS_WEBHOOKS.md`. Reuses the existing throttling (`throttle:600,1`) and
`workspace.channel` middleware — no route-level changes needed beyond adding
the path.

### Required configuration

Platform-level (one set per tenant; encrypt at rest):

- `partner_id` (Shopee Open Platform partner ID).
- `partner_key` (HMAC secret used to sign OAuth + API calls).

> **Storage decision (open):** platform-level credentials have no home yet.
> Options: a new `workspace_settings` table (single-row JSONB), a per-key
> `workspace_partner_credentials` table, or piggyback on the existing
> `workspaces` JSON column. Pick at G1.1 implementation time. Encryption at
> rest via `Crypt` is mandatory regardless of which table wins.

Per-channel-account (set after OAuth flow completes):

- `shop_id` — numeric Shopee shop ID.
- `merchant_id` — Shopee merchant ID.
- `region` — locked to `vn` for cut 1.
- `access_token` — encrypted, 4h TTL.
- `refresh_token` — encrypted, 30d TTL.
- `access_token_expires_at` — UTC timestamp.
- `webhook_secret` — auto-generated 64-char hex; passed to Shopee when
  registering the push URL so Shopee includes it in HMAC.
- `webhook_url` — `https://webhook.qrf.vn/webhooks/shopee/{uuid}`; set by the
  app on register-webhook.

`channel_accounts.credentials` JSON column carries all of the above under
keys `shop_id`, `merchant_id`, `access_token`, `refresh_token`,
`access_token_expires_at`. `webhook_secret` and `webhook_url` stay on the
typed columns for parity with existing adapters.

### OAuth pre-registration

Shopee's partner OAuth requires the redirect URI to be **pre-registered in
the partner dashboard** at https://open.shopee.vn/developer before any
authorization request is made. Mismatched URIs return
`error=invalid_redirect_uri`.

For production:

```
https://<tenant-slug>.qrf.vn/admin/channels/shopee/callback
```

(plus the equivalent for `admin.qrf.vn` if platform-level connection is
ever supported). Each tenant slug must be registered separately. **Do not
generate the connect URL until the tenant admin confirms the slug has
been registered** — otherwise Shopee returns a hard error and the admin UX
is broken.

For local development (using Shopee sandbox):

```
http://localhost:8000/admin/channels/shopee/callback
```

The sandbox host (`https://partner.test-shopeemobile.com/api/v2/`) is a
**different endpoint** from production (`https://partner.shopeemobile.com/api/v2/`).
The cut 1 adapter reads `SHOPEE_API_BASE` from `config/services.php` so dev
can switch by setting `SHOPEE_API_BASE=https://partner.test-shopeemobile.com/api/v2`
in `.env.local`. Production defaults to the live endpoint.

### Verification (HMAC)

**VERIFIED** against Shopee Open Platform docs and rollout.com integration guide.

Shopee signs every push with HMAC-SHA256 over the raw body, keyed by the
`webhook_secret` you registered. The signature arrives as:

```
X-Shopee-Signature: <hex_digest>
```

The value is a bare hex digest — no `sha256=` prefix, no `HMAC-SHA256` scheme
prefix. Laravel middleware (`VerifyShopeeSignature`) verifies the signature
before any DB write. Mismatch → `401 INVALID_SIGNATURE`. Constant-time
compare via `hash_equals`. The `webhook_secret` is never logged.

The `partner_key` is used only for **outgoing** signing — OAuth and API
calls. It is never used for webhook verification.

### Idempotency

- Primary key: `shopee:{channel_account_id}:msg:{shopee_message_id}`.
- `shopee_message_id` is the `message_id` field on each push payload (stable
  for the lifetime of the message, even on edit).
- Edited messages: Shopee repushes with the same `message_id` and an
  incremented `version`. Update the existing message row, do not duplicate.
- Deleted messages: Shopee does not push deletes in the public API; we do
  not support delete-backfill in cut 1.

### Inbound mapping

```
Shopee push payload (cn.vn.shopee.chat.message) →
  message_id              -> provider_message_id
  conversation_id         -> provider_conversation_id
  buyer_id                -> provider_user_id
  buyer_name              -> sender_display_name
  buyer_portrait_url      -> sender_avatar_url
  message_type            -> message_type
                            ("text" -> "TEXT",
                             "image" -> "IMAGE",
                             "product" -> "PRODUCT"  // product card; ignored
                                                      // cut 1, stored raw,
                             "video" -> "VIDEO",
                             "sticker" -> "STICKER",
                             "order"  -> ignored, log + admin flag)
  content.text            -> body_text
  content.image_url       -> attachments[].url
  content.video_url       -> attachments[].url
  content.product_id      -> attachments[].product_id
  created_timestamp       -> provider_timestamp
  shop_id                 -> provider_account_id (sanity check vs channel_account)
```

Unsupported message types (`order`, `voucher`, `combo`, etc.) are persisted
to `webhook_events` with status `IGNORED` and surfaced as
`WEBHOOK_UNSUPPORTED_EVENT` in the channel account health view so admins can
spot content we're silently dropping.

### Outbound send

Endpoint: `POST /api/v2/seller_chat/send_message` (region `vn`).

Request shape:

```json
{
  "shop_id": 123456,
  "conversation_id": 789,
  "message_type": "text",
  "content": { "text": "..." }
}
```

Image send: pre-upload to Shopee's image service
(`POST /api/v2/media/upload_image`) to get a CDN URL, then send with
`message_type: "image"` and `content.image_url`. 5MB max per image; we
re-encode / downscale client-side in the admin UI before upload.

Local-first contract (per spec 04/05):

1. Persist `messages` row + `outbox_messages` row in one transaction.
2. Return `202 ACCEPTED` to the admin UI.
3. Queue `SendShopeeMessageJob` runs in `queues:shopee` with priority 2
   (lower than agent-facing jobs).
4. On 200 from Shopee: stamp `provider_response`, mark `SENT`.
5. On `error: "auth_error"` / expired token: `RefreshShopeeAccessTokenJob`
   runs, then retry once. Still failing → `DEGRADED` channel account.
6. On `error: "rate_limited"` (HTTP 429): respect `retry_after`, exponential
   backoff per cut-1 retry policy.
7. On permanent recipient errors (`"buyer_blocked"`, `"shop_blocked"`): mark
   message `FAILED` without auto-retry; admin manually resends.

### Token lifecycle

- `access_token` 4h TTL. `RefreshShopeeAccessTokenJob` runs at 75% of TTL.
- `refresh_token` 30d TTL. Refresh fails → `DEGRADED` channel account +
  admin action `SHOPEE_TOKEN_EXPIRED`.
- Failed refresh + manual retry also fails → flag `REAUTH_REQUIRED`,
  surface "Reconnect Shopee" button in admin UI (next OAuth round-trip).
- Token values encrypted via Laravel `Crypt`; never shown after save.

### Health

Channel account health check calls (via Shopee partner API):

- `GET /api/v2/shop/get_shop_info` → verify token + shop status.
- `GET /api/v2/seller_chat/get_conversation_list?page_size=1` → smoke the
  chat endpoint.
- Local: last inbound webhook time from `webhook_events`.
- Admin health card surfaces:
  - `ACTIVE` / `DEGRADED` / `DISABLED` (parity with other adapters).
  - `pending_message_count` from Shopee (polled every 5 min, cached).
  - `last_error_code` / `last_error_message` from the most recent failed
    outbox or webhook.

## Admin recovery actions

| Action | Trigger | Effect |
|---|---|---|
| Test credentials | Manual | Calls `get_shop_info`; shows shop name + status |
| Re-register webhook | Manual / after secret rotation | Calls `set_webhook_url`; updates `webhook_secret` |
| Refresh token | Manual / after auto-retry fail | Runs OAuth refresh; on success updates tokens, on fail flags `REAUTH_REQUIRED` |
| Reconnect Shopee | Manual after `REAUTH_REQUIRED` | Re-runs OAuth round-trip from the admin UI |
| Replay failed webhook | Manual | Re-runs `NormalizeInboundMessageJob` for one `webhook_events` row |
| Retry failed outbound | Manual | Re-queues `SendShopeeMessageJob` from `outbox_messages` row |
| Disable channel account | Manual | Status `DISABLED`; admin can re-enable |

## Retry policy (per spec 05 baseline)

Inbound normalization:

- Retry transient DB / queue errors (3 attempts, exponential).
- Do not retry invalid payload shape — admin replays after fix.

Outbound:

- Retry network timeouts and HTTP 5xx.
- Retry token-expired exactly once after refresh.
- Retry HTTP 429 with `retry_after` cap.
- Do not retry `buyer_blocked`, `shop_blocked`, `recipient_not_found`.
- Backoff: 1m, 5m, 15m, 1h. Max 5 attempts.

## Data model changes

New migration `2026_07_xx_add_shopee_to_provider_enum.php`:

```php
// Drop and recreate the provider CHECK constraints to include SHOPEE
// (TIKTOK_SHOP is included as a placeholder so we don't re-touch the
// migration when cut 2 lands).
$this->dropCheck('channel_accounts', 'provider');
$this->check('channel_accounts', 'provider', [
    'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'SHOPEE', 'TIKTOK_SHOP',
]);

$this->dropCheck('external_identities', 'provider');
$this->check('external_identities', 'provider', [
    'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'SHOPEE', 'TIKTOK_SHOP',
]);
```

`ChannelAdapterRegistry` (spec 10) gets two more entries, both pointing to
placeholder classes for cut 1 (`ShopeeAdapter`, `TikTokShopAdapter`). The
TikTok class returns "not yet implemented" until cut 2, but the enum value
exists so we don't have to re-touch migrations later.

## Module / file layout

```
app/Modules/Channels/
  Adapters/
    ShopeeAdapter.php                    # implements ChannelAdapter
    TikTokShopAdapter.php                # placeholder, throws on call
  Http/
    Controllers/
      ShopeeOAuthController.php          # callback for partner OAuth
      ProviderWebhookController.php      # + shopee() method
    Middleware/
      VerifyShopeeSignature.php          # HMAC verify before ingest
  Jobs/
    SendShopeeMessageJob.php
    RefreshShopeeAccessTokenJob.php
    NormalizeShopeeInboundMessageJob.php # or reuse generic with adapter
  Models/
    ChannelAccount.php                   # + boot hooks for SHOPEE enum
  routes/                                # (new — channels module owns its routes)
    shopee.php                           # OAuth routes
```

`routes/web.php` gets one new line under the existing webhook host binding:

```php
Route::post('webhooks/shopee/{channelAccount}', [ProviderWebhookController::class, 'shopee'])
    ->name('webhooks.shopee');
```

OAuth callback (NOT under webhook host — public route on the main app):

```php
// app/Modules/Channels/routes/shopee.php, loaded via the channels service
// provider.
Route::middleware(['auth', 'verified', 'workspace.member'])
    ->prefix('admin/channels/shopee')
    ->name('admin.channels.shopee.')
    ->group(function () {
        Route::get('connect', [ShopeeOAuthController::class, 'connect'])->name('connect');
        Route::get('callback', [ShopeeOAuthController::class, 'callback'])->name('callback');
    });
```

## Test plan

### Unit / adapter

- `ShopeeAdapterTest::verify_webhook_accepts_valid_hmac`
- `ShopeeAdapterTest::verify_webhook_rejects_invalid_hmac`
- `ShopeeAdapterTest::verify_webhook_rejects_missing_signature`
- `ShopeeAdapterTest::extract_idempotency_key_uses_message_id`
- `ShopeeAdapterTest::normalize_inbound_text_maps_to_canonical_shape`
- `ShopeeAdapterTest::normalize_inbound_image_maps_attachments`
- `ShopeeAdapterTest::normalize_inbound_product_is_unsupported_but_persisted`
- `ShopeeAdapterTest::build_outbound_text_payload`
- `ShopeeAdapterTest::build_outbound_image_payload_after_upload`

### Integration (HTTP)

- `OmnichannelShopeeTest::webhook_ingest_creates_conversation_and_message`
- `OmnichannelShopeeTest::duplicate_message_id_does_not_double_ingest`
- `OmnichannelShopeeTest::edited_message_updates_existing_row`
- `OmnichannelShopeeTest::unsupported_event_surfaces_in_admin_health`
- `OmnichannelShopeeTest::oauth_callback_persists_tokens_encrypted`
- `OmnichannelShopeeTest::refresh_token_job_rotates_credentials`
- `OmnichannelShopeeTest::expired_refresh_token_marks_REAUTH_REQUIRED`
- `OmnichannelShopeeTest::send_message_outbox_marks_SENT_on_200`
- `OmnichannelShopeeTest::send_message_outbox_retries_on_429`
- `OmnichannelShopeeTest::send_message_outbox_marks_FAILED_on_buyer_blocked`

### Smoke (live VN shop)

Documented in `docs/OPS_WEBHOOKS.md` § "Register a bot" — add a Shopee
section with end-to-end steps using a real VN seller shop test account.

## Risks

| Risk | Mitigation |
|---|---|
| Rate limit 100 req/min/shop | Queue outbox; batch where possible; surface rate pressure in admin health |
| VN Open Platform review 1-2 weeks | Start G0 immediately; spec + migration scaffold don't block review |
| Shopee API breaking changes | Pin adapter version; isolate via `ChannelAdapter` interface; track Shopee changelog monthly |
| Refresh token loss (user revokes) | Surface `REAUTH_REQUIRED` prominently; admin cannot retry without OAuth round-trip |
| Webhook push outage | Health check alerts on `last_inbound_at > 5m`; admin can trigger manual poll via `get_conversation_list` |
| Image upload 5MB cap | UI re-encodes before upload; show size warning |
| Product / order messages silently dropped | Persist as `IGNORED` in `webhook_events`; surface in admin health |

## Cut 1 milestones (August 2026)

| Week | Milestone |
|------|-----------|
| W1 (G0) | Apply Shopee Open Platform VN; spec 11 merged; migration merged; channel adapter skeleton + HMAC verify test pass |
| W2 (G1.1) | OAuth round-trip + token refresh job; encrypted credentials in DB |
| W3 (G1.2) | Inbound webhook → ingest → Inbox renders new conversation (smoke with live VN shop) |
| W4 (G1.3) | Outbound send + retry policy + health card; cut 1 ready for first VN shop pilot |

Cut 2 (post-August, separate scope): typing/read indicators, attachment
round-trip beyond images, cold-start conversation sync, admin UI
Shopee-specific affordances (product lookup shortcut, order link).

Cut 3 (later still): TikTok Shop Chat (separate spec revision referencing
this one's data model).