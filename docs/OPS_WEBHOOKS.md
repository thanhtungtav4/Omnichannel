# OPS: Provider Webhooks on webhook.qrf.vn

Runbook for the dedicated webhook ingress. Read this before paging anyone at
03:00 about "messages aren't coming in".

## What it is

`webhook.qrf.vn` is a dedicated nginx vhost (`deploy/nginx/crm-webhook.conf`)
that handles POST traffic from Telegram / Zalo OA / Facebook / future
providers. It serves only `/webhooks/*` and Laravel's `/up` health probe —
nothing else.

Why a separate host (not on `qrf.vn` / `*.qrf.vn`):

- Method-restricted at the edge: only POST/GET/HEAD; everything else is 405
  before PHP-FPM even sees it.
- Lower body cap (1 MB) than the main app (25 MB).
- Isolated access log (`/var/log/nginx/crm-webhook.access.log`) so a webhook
  flood doesn't drown out agent activity.
- Independent rate limiting and (optionally) IP allowlists per provider.
- Blast radius: webhook abuse can't take down the operator UI.

Laravel `routes/web.php` also binds `/webhooks/*` to the webhook host when
`APP_WEBHOOK_SUBDOMAIN` is set, so even if a misconfigured proxy forwards
webhook traffic to a tenant subdomain, Laravel 404s it.

## URL contract

| Provider    | Method | URL                                                              | Auth header                          |
|-------------|--------|------------------------------------------------------------------|--------------------------------------|
| Telegram    | POST   | `/webhooks/telegram/{channel_account_uuid}`                       | `X-Telegram-Bot-Api-Secret-Token`    |
| Zalo OA     | POST   | `/webhooks/zalo/{channel_account_uuid}`                          | `X-Zalo-Signature: sha256=...`       |
| Zalo Personal| POST  | `/webhooks/zalo/{channel_account_uuid}` (from sidecar)           | `X-Sidecar-Token`                    |
| Facebook    | GET    | `/webhooks/facebook/{channel_account_uuid}` (verify)             | `hub.verify_token` query param       |
| Facebook    | POST   | `/webhooks/facebook/{channel_account_uuid}`                      | `X-Hub-Signature-256: sha256=...`    |
| Shopee      | POST   | `/webhooks/shopee/{channel_account_uuid}` (VN region, cut 1)     | `X-Shopee-Signature: <hex>` (bare hex digest, no prefix) |
| TikTok Shop | POST   | `/webhooks/tiktok-shop/{channel_account_uuid}` (VN region, cut 1) | `TikTok-Signature: t=<unix>,s=<hex>` (HMAC over `${t}.${raw_body}`) |

The full URL is always:

```
https://webhook.<DOMAIN>/webhooks/<provider>/<channel_account_uuid>
```

Construct the URL with `route('webhooks.'.$provider, $account)` from Laravel
side — never concatenate manually.

## Smoke tests

Run from the VPS (so you test the real nginx + Laravel path, not just your
local /up):

```bash
# Health probe — must return 200.
curl -fsS -o /dev/null -w "%{http_code}\n" https://webhook.qrf.vn/up

# Wrong method — must return 405 (nginx edge reject).
curl -s -o /dev/null -w "%{http_code}\n" -X PUT https://webhook.qrf.vn/webhooks/telegram/00000000-0000-0000-0000-000000000000

# Bogus UUID on a real POST — must return 404 from Laravel (channel not found).
curl -s -o /dev/null -w "%{http_code}\n" -X POST https://webhook.qrf.vn/webhooks/telegram/00000000-0000-0000-0000-000000000000

# Webhook host should NOT serve tenant pages.
curl -s -o /dev/null -w "%{http_code}\n" https://webhook.qrf.vn/admin
# expect 404 (no tenant pinned, route group middleware 404s)

# Tenant host should NOT serve webhooks — the route domain binding rejects them.
# First, find an existing channel account UUID:
php artisan tinker --execute="echo \App\Modules\Channels\Models\ChannelAccount::query()->value('id');"
# Then:
curl -s -o /dev/null -w "%{http_code}\n" -X POST \
  -H "X-Telegram-Bot-Api-Secret-Token: bogus" \
  https://acme.qrf.vn/webhooks/telegram/<that-uuid>
# expect 404 (route domain mismatch)
```

If `/up` is 200 and the wrong-host POST is 404, ingress is healthy.

## Register a bot (one-time per workspace)

### Telegram

1. Workspace admin -> Channels -> Add channel -> Telegram.
2. Paste bot token from `@BotFather`. Save.
3. Click "Register webhook" on the channel account. The app calls
   `setWebhook` with:
   - `url`: `https://webhook.qrf.vn/webhooks/telegram/<uuid>`
   - `secret_token`: 64-char hex generated on save (stored in
     `channel_accounts.webhook_secret`)
   - `allowed_updates`: `message, edited_message, callback_query, my_chat_member`
   - `drop_pending_updates`: true on re-register

Verify the registration took effect on Telegram's side:

```bash
curl -s "https://api.telegram.org/bot<bot_token>/getWebhookInfo" | jq
# Expect:
#   "url": "https://webhook.qrf.vn/webhooks/telegram/<uuid>"
#   "pending_update_count": 0
#   "last_error_date": absent
```

Send a test message from a real Telegram client to the bot. The Inbox should
show a new conversation within ~1 second.

### Zalo OA

1. Workspace admin -> Channels -> Add channel -> Zalo OA.
2. Paste OA ID, App ID, App Secret, Access Token, Refresh Token. Save.
3. In the Zalo OA dashboard, set the webhook URL to
   `https://webhook.qrf.vn/webhooks/zalo/<uuid>` and the verify token to the
   value stored in `webhook_secret`.
4. Send a test message from a Zalo user to the OA.

### Zalo Personal (via sidecar)

The sidecar calls the CRM, not Zalo directly. No URL to register.

1. Workspace admin -> Channels -> Add channel -> Zalo Personal.
2. Click "Login QR" — the sidecar returns a QR code; scan with the Zalo app.
3. The sidecar's webhook URL is `http://127.0.0.1/webhooks/zalo/<uuid>` and
   its shared token matches the channel account's `webhook_secret`. The
   sidecar runs on the VPS only; nothing is exposed to the public internet.

### Shopee (VN region)

OAuth-based; one connected channel account = one Shopee shop.

1. Workspace admin -> Channels -> Add channel -> Shopee.
2. Paste `partner_id` + `partner_key` (platform-level credentials, ask
   the workspace owner; they registered the dev account on
   https://open.shopee.vn/developer).
3. Click "Connect Shopee" — the app redirects to Shopee's OAuth consent
   screen. The shop owner authorizes; Shopee redirects back to
   `https://<tenant>.qrf.vn/admin/channels/shopee/callback?code=...`.
4. The callback handler exchanges the code for `access_token` (4h TTL) +
   `refresh_token` (30d TTL), stores them encrypted, and the channel
   account flips to `ACTIVE`.
5. The app calls `set_webhook_url` on Shopee's side with
   `https://webhook.qrf.vn/webhooks/shopee/<uuid>`. Shopee pushes every
   event with an `X-Shopee-Signature` header containing the bare HMAC-SHA256
   hex digest of the raw body, keyed by the connected shop's `webhook_secret`.
6. Send a test message from a real Shopee buyer to the connected shop.
   The Inbox should show a new conversation within ~2s.

If the refresh token expires (>30d idle) or the shop owner revokes access,
the channel account flips to `DEGRADED` with `REAUTH_REQUIRED`. Re-run the
OAuth round-trip from the channel account page.

### Shopee sandbox testing

Use the Shopee Open Platform sandbox for pre-pilot testing without touching
production shops. See `docs/SHOPEE_SANDBOX_SETUP.md` for full setup.

Quick smoke test:

```bash
# 1. Configure sandbox credentials for a test workspace
php artisan shopee:sandbox-smoke set --slug=sandbox-test \
    --partner-id=$SHOPEE_SANDBOX_PARTNER_ID --partner-key=$SHOPEE_SANDBOX_PARTNER_KEY

# 2. Set SHOPEE_API_BASE to the sandbox endpoint in .env.local
SHOPEE_API_BASE=https://partner.test-shopeemobile.com/api/v2
php artisan config:clear

# 3. Run OAuth round-trip via the admin UI (https://<slug>.qrf.vn/admin/channels)

# 4. Synthetic inbound + outbound smoke
scripts/shopee_sandbox_smoke.sh health
scripts/shopee_sandbox_smoke.sh inbound --tenant=sandbox-test --account=$UUID
scripts/shopee_sandbox_smoke.sh outbound --account=$UUID --text="hello"
```

Sandbox endpoints do NOT push auto webhooks the way production does — use
the `inbound` smoke command to simulate Shopee pushes. Real push testing
requires your sandbox webhook URL to be reachable from the internet (use
the deployed `webhook.qrf.vn`, or a tunnel for local dev).

### Facebook Messenger

1. Create a Facebook App + Messenger product. Get Page Access Token and App
   Secret.
2. Workspace admin -> Channels -> Add channel -> Facebook. Paste Page Access
   Token + App Secret. Save.
3. In the FB app dashboard -> Webhooks -> Add Callback URL:
   - URL: `https://webhook.qrf.vn/webhooks/facebook/<uuid>`
   - Verify Token: the value in `webhook_secret`
4. Subscribe to `messages` and `messaging_postbacks`.

### TikTok Shop (VN region)

OAuth-based; one connected channel account = one TikTok Shop. Spikes (W1/W2)
are complete; full registration flow lands in W3 alongside `TikTokShopAdapter`.

1. Workspace admin -> Channels -> Add channel -> TikTok Shop.
2. Paste `app_key` + `app_secret` (partner-level credentials, ask the workspace
   owner; they registered the partner app on
   https://partner.tiktokshop.com/ws?_lang=en).
3. Click "Connect TikTok Shop" — the app redirects to TikTok's OAuth consent
   screen at `https://auth.tiktok-shops.com/oauth/authorize`. The shop owner
   authorizes; TikTok redirects back to
   `https://<tenant>.qrf.vn/admin/channels/tiktok/callback?auth_code=...&code=...`.
4. The callback handler exchanges `auth_code` for `access_token` (TTL per
   `expires_in`) + `refresh_token` via
   `https://auth.tiktok-shops.com/api/v2/token/get` (grant_type=authorized_code),
   stores them encrypted, and the channel account flips to `ACTIVE`.
5. The app registers the webhook URL with TikTok Shop Partner API and points
   it at `https://webhook.qrf.vn/webhooks/tiktok-shop/<uuid>`. TikTok pushes
   every event with a `TikTok-Signature: t=<unix>,s=<hex>` header where
   `s = HMAC-SHA256(t . raw_body, app_secret)`. Requests with timestamp
   drift > 300s are rejected (replay window).

If the refresh token expires or the shop owner revokes access, the channel
account flips to `DEGRADED` with `REAUTH_REQUIRED`. Re-run the OAuth round-trip
from the channel account page.

## Monitoring

### Key signals

| Signal                          | Where to look                                            | Healthy        |
|---------------------------------|----------------------------------------------------------|----------------|
| Webhook 5xx rate                | `crm-webhook.access.log` (status `5..`) + Laravel logs   | < 0.5% of POST |
| Provider signature failures     | Laravel logs: `INVALID_WEBHOOK_SECRET` / `INVALID_SIGNATURE` | 0 (or expected rotation noise) |
| Pending updates (Telegram)      | `getWebhookInfo.pending_update_count`                    | 0              |
| Last error timestamp (Telegram) | `getWebhookInfo.last_error_date`                         | absent or stale |
| Queue depth (after ingest)      | `redis-cli LLEN queues:default` (or your queue name)     | < 100          |
| Inbound ingest latency          | Laravel log structured field `ingest_ms`                 | p95 < 500ms    |

### Health-check script (cron-friendly)

Add to `/etc/cron.d/crm-webhook-health`:

```cron
*/5 * * * * www-data /usr/local/bin/crm-webhook-health.sh
```

`/usr/local/bin/crm-webhook-health.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
DOMAIN="${APP_TENANT_DOMAIN:-qrf.vn}"
URL="https://webhook.${DOMAIN}/up"
CODE=$(curl -fsS -o /dev/null -w "%{http_code}" "$URL" || echo "000")

# Suppress repeated alerts: only fire when state changes from healthy to
# unhealthy (or unhealthy for 3 consecutive checks).
STATE_FILE="/var/lib/crm-webhook-health.state"
PREV=$(cat "$STATE_FILE" 2>/dev/null || echo "unknown")

if [ "$CODE" != "200" ]; then
    echo "[$(date -Iseconds)] webhook ingress unhealthy: HTTP $CODE on $URL" \
        | tee -a /var/log/crm-webhook-health.log
    if [ "$PREV" != "unhealthy" ]; then
        echo "unhealthy" > "$STATE_FILE"
        # Concrete alert path: use the sidecar to send a Telegram message to
        # the on-call. Replace <TELEGRAM_BOT_TOKEN> and <CHAT_ID> with real
        # values from the on-call Telegram group.
        curl -fsS -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/sendMessage" \
            -d chat_id="<CHAT_ID>" \
            -d text="🚨 webhook.${DOMAIN} unhealthy: HTTP ${CODE} on ${URL}" \
            >/dev/null || true
    fi
else
    if [ "$PREV" = "unhealthy" ]; then
        curl -fsS -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/sendMessage" \
            -d chat_id="<CHAT_ID>" \
            -d text="✅ webhook.${DOMAIN} recovered" \
            >/dev/null || true
    fi
    echo "healthy" > "$STATE_FILE"
fi
```

```bash
sudo chmod +x /usr/local/bin/crm-webhook-health.sh
sudo touch /var/lib/crm-webhook-health.state
sudo chown www-data:www-data /var/lib/crm-webhook-health.state
```

**Bot token management**: store `<TELEGRAM_BOT_TOKEN>` in
`/etc/crm-webhook-health.env` with mode `0600`, root:root, and `source` it
from the script before the curl call. Don't put secrets inline in the cron
job (which is world-readable).

### What "unhealthy" looks like in the wild

- 401 spike on Telegram: the `secret_token` doesn't match. Re-register the
  webhook (Channels -> Register webhook), or check if `webhook_secret` was
  rotated in the DB without `setWebhook` being re-called.
- 401 spike on Zalo OA: `app_secret` was rotated. Re-save the channel
  account with the new app secret; the secret hash in `credentials.app_secret`
  is encrypted at rest so rotation requires a fresh save.
- 403 on Facebook GET: `hub.verify_token` doesn't match `webhook_secret`.
  Re-paste in the FB dashboard.
- 5xx spike after deploy: usually a migration that changed the
  `webhook_events` schema. Check `php artisan migrate:status` and Laravel's
  `storage/logs/laravel.log`.

## Replay procedures

### Replay a single Telegram update

Telegram keeps `drop_pending_updates` updates for up to 24h on its servers.
If the webhook was unregistered, re-register (Channels -> Register webhook)
without `drop_pending_updates` and Telegram replays queued updates.

### Replay from `webhook_events`

Every webhook delivery creates a row in `webhook_events` with the raw payload
and the ingest outcome. **As of writing, no replay job exists** — when a
provider retries (or you re-register the webhook with
`drop_pending_updates=false`), the same `webhook_events` row is re-hit by
the inbound controller's idempotency check.

Inspect the failure queue:

```sql
SELECT id, provider, status, last_error, created_at
FROM webhook_events
WHERE channel_account_id = '<uuid>'
  AND status IN ('FAILED', 'IGNORED')
ORDER BY created_at DESC
LIMIT 20;
```

If the underlying ingest bug is fixed and the provider hasn't re-pushed,
you can re-dispatch manually with tinker (replace the controller call with
whichever one your adapter exposes):

```bash
php artisan tinker --execute='
  $event = \App\Modules\Channels\Models\WebhookEvent::find("<id>");
  app(\App\Modules\Channels\Services\InboundMessageIngestor::class)
    ->ingest($event->channelAccount, $event->raw_payload, $event->raw_headers);
'
```

Track this kind of manual replay in an incident doc — a proper replay job
is on the roadmap but not built.

### Replay from raw provider logs

If the CRM never received the webhook (provider retried, gave up, lost it):

- **Telegram**: support can resend from the last 24h of failed updates. Open
  a ticket via `@BotSupport` with the bot ID and approximate timestamps.
- **Zalo OA**: no replay API; the OA message is gone. Ask the customer to
  re-send.
- **Facebook**: Graph API `/{conversation_id}/messages` returns last 30 days.
  Manual reconciliation script (out of scope for this runbook).

## Secret rotation

| Secret                       | Where stored                              | Rotation procedure                                                                 |
|------------------------------|-------------------------------------------|------------------------------------------------------------------------------------|
| Telegram `secret_token`      | `channel_accounts.webhook_secret`         | Update value, click Register webhook — old value is overwritten server-side.       |
| Telegram bot token           | `channel_accounts.credentials.bot_token`  | Get new token from `@BotFather`, save in UI, re-register webhook.                  |
| Zalo OA `app_secret`         | `channel_accounts.credentials.app_secret` (encrypted) | Save new secret in UI; Laravel re-encrypts on update.                |
| Zalo OA access/refresh token | `channel_accounts.credentials` (encrypted) | Handled by `RefreshZaloAccessTokenJob`; manual rotation via UI if refresh fails.   |
| Facebook app secret          | `channel_accounts.credentials.app_secret` (encrypted) | Save new secret in UI; re-verify webhook in FB dashboard.              |
| Facebook page access token   | `channel_accounts.credentials.page_token` (encrypted) | Regenerate in FB dashboard, save in UI.                              |
| Shopee `webhook_secret`      | `channel_accounts.webhook_secret`         | Shopee rotates via `set_webhook_url` on the Shopee side; update DB and re-register. |
| Shopee `partner_key`         | `workspace_settings.shopee.partner_credentials.partner_key` (encrypted) | Save new key in admin UI; Laravel re-encrypts. Affects all Shopee accounts. |
| Shopee access/refresh token  | `channel_accounts.credentials` (encrypted) | Handled by `RefreshShopeeAccessTokenJob`; manual rotation via OAuth round-trip if refresh fails. |
| TikTok Shop `app_secret`     | `workspace_settings.tiktok.partner_credentials.app_secret` (encrypted) | Save new secret in admin UI; also re-register webhook so TikTok re-signs with new key. |
| TikTok Shop webhook secret   | `channel_accounts.webhook_secret`         | Update value, re-register webhook in TikTok Shop Partner dashboard. |
| TikTok Shop access/refresh token | `channel_accounts.credentials` (encrypted) | Handled by `RefreshTikTokAccessTokenJob`; manual rotation via OAuth round-trip if refresh fails. |
| Sidecar shared token         | `channel_accounts.webhook_secret` + `ZALO_SIDECAR_TOKEN` env | Update both sides; old token valid for in-flight retries (~60s). |

Secret values are encrypted at rest via Laravel's `Crypt` facade. Rotation
requires saving the channel account again; there is no DB-level key rotation
tooling yet — if you need to rotate the Laravel APP_KEY, do it during a
maintenance window and re-save every channel account.

## IP allowlist (optional)

Only Telegram publishes static outbound IP ranges (verify at
https://core.telegram.org/bots/webhooks before relying):

```
149.154.160.0/20
91.108.4.0/22
```

Zalo OA and Facebook use dynamic ranges — don't IP-allowlist them.

To enable a Telegram-only allowlist:

1. Copy `deploy/nginx/crm-webhook.conf` to a new file
   `crm-webhook-telegram-only.conf`.
2. Change `server_name webhook.qrf.vn;` to a new host (e.g.
   `webhook-tg.qrf.vn`).
3. Add inside `location /webhooks/telegram/`:

   ```nginx
   location /webhooks/telegram/ {
       allow 149.154.160.0/20;
       allow 91.108.4.0/22;
       deny all;
       try_files $uri /index.php?$query_string;
   }
   ```

4. Set `APP_WEBHOOK_SUBDOMAIN=webhook-tg` in `.env` (or keep the original
   `webhook` for Zalo/Facebook and use a parallel sub for Telegram-only).
5. `sudo nginx -t && sudo systemctl reload nginx`.

## Common failures & fixes

| Symptom                                              | Likely cause                                            | Fix                                                                                       |
|------------------------------------------------------|---------------------------------------------------------|-------------------------------------------------------------------------------------------|
| `401 INVALID_WEBHOOK_SECRET` from Telegram           | `webhook_secret` rotated in DB without re-registering  | Click "Register webhook" in the channel account UI                                      |
| `401 INVALID_SIGNATURE` from Zalo OA                 | `app_secret` was rotated                                | Re-save the channel account with the new app secret                                       |
| `403 Forbidden` on Facebook verify                   | `hub.verify_token` mismatch                             | Check the value in `webhook_secret`; re-paste in FB dashboard                             |
| Webhook URL rejected by Telegram with `INVALID_URL`  | nginx not serving on 443, or cert invalid               | `sudo nginx -t && curl -I https://webhook.qrf.vn/up`                                      |
| `last_error_message: "SSL handshake failed"`         | Cert chain incomplete                                  | `certbot renew --force-renewal`; nginx must include `fullchain.pem`, not just `cert.pem`  |
| `pending_update_count` keeps climbing                | FPM pool exhausted or DB writes failing                 | Check `supervisorctl status`; check `storage/logs/laravel.log` for DB errors               |
| Zalo personal events not arriving                    | Sidecar crashed or token mismatch                       | `systemctl status zalo-sidecar`; check `ZALO_SIDECAR_TOKEN` matches `webhook_secret`     |
| `404` from Laravel on every webhook                  | Route cache stale after route file change               | `sudo -u www-data php artisan route:clear`                                                |
| `405` on POST to webhook from anywhere               | Wrong nginx vhost matched (e.g. legacy IP-only block)  | `sudo nginx -T | grep -A2 server_name` and remove old / overlapping blocks                   |

## Incident response checklist

When "messages aren't coming in":

1. **Confirm scope.** One channel or all? One workspace or all?
2. **Check the provider side.**
   - Telegram: `getWebhookInfo` — what does `last_error_message` say?
   - Zalo OA: dashboard -> Webhook delivery log.
   - Facebook: App dashboard -> Webhooks -> Recent deliveries.
3. **Check the ingress.**
   - `curl -I https://webhook.qrf.vn/up` — 200?
   - `tail -n 200 /var/log/nginx/crm-webhook.error.log` — anything new?
   - `tail -n 200 /var/log/nginx/crm-webhook.access.log | grep ' 5[0-9][0-9] '` —
     5xx rate spike?
4. **Check the app.**
   - `supervisorctl status` — is `crm-worker` running?
   - `redis-cli LLEN queues:default` — queue backed up?
   - `tail -f storage/logs/laravel.log` — exceptions during ingest?
5. **If recent deploy:** `cd /var/www/crm && sudo -u www-data git log --oneline -5`.
   Rollback per `docs/DEPLOY_VPS.md` if a code change is the suspect.
6. **If secret rotation recently happened:** confirm both sides were updated
   (UI + provider dashboard).
7. **When fixed:** post-mortem in `docs/INCIDENTS/<date>-<slug>.md` with
   timeline, root cause, and follow-ups.

## On-call rotations and escalation

Not configured yet — out of scope for this runbook. When you add PagerDuty /
OpsGenie, point the alert at:

- Webhook 5xx rate > 5% for 5m
- `/up` non-200 for 3m
- Queue depth > 1000 for 10m
- Provider signature failure rate > 1% for 5m (possible brute-force scan)