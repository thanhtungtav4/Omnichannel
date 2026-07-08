# Pilot Checklist — Shopee Chat VN + TikTok Shop Chat VN (cut 1)

End-to-end checklist for piloting a real VN shop on either provider.
Read top-to-bottom on day 0; reuse the same shape for every pilot shop.

> **You are pilot-ready when every box in §0 (Pre-flight) is checked AND
> §1 (Smoke) runs clean against the production webhook ingress.**

---

## §0 — Pre-flight (one-time, before the pilot)

Run `php artisan pilot:check` from the VPS. Every line should be ✓.

- [ ] Workspace created (`/admin/workspaces`) with display name, slug, billing contact.
- [ ] Workspace admin user exists with role `owner` and 2FA enabled.
- [ ] Platform partner credentials set (per provider) — see provider section below.
- [ ] Workspace has at least one agent user with role `support_agent`.
- [ ] DNS resolves: `*.qrf.vn` wildcard A record → VPS, `webhook.qrf.vn` A record → VPS.
- [ ] TLS: `https://webhook.qrf.vn/up` returns 200 (Let's Encrypt wildcard auto-renewed).
- [ ] TLS: `https://<slug>.qrf.vn/admin` loads (login page reachable).
- [ ] Queue worker running: `systemctl status crm-queue` (or `supervisorctl status crm-worker:*`).
- [ ] Cron healthy: `php artisan schedule:list` shows `webhooks:health` (every 5m) and `webhook-secret-rotation-check` (daily).
- [ ] Backups: `php artisan backup:list --disk=s3` shows a fresh backup within last 24h.
- [ ] Monitoring: §Monitoring of `docs/OPS_WEBHOOKS.md` shows green / within SLO.

### §0.1 — Shopee-specific partner setup (one-time)

- [ ] Apply for Shopee Open Platform **production** partner account at https://open.shopee.vn/developer.
- [ ] Note `partner_id` (numeric) + `partner_key` (hex).
- [ ] Register webhook callback URL `https://webhook.qrf.vn/webhooks/shopee/<uuid>` per channel account **after** the admin user clicks "Connect Shopee". The app does this automatically during the OAuth round-trip; verify in Shopee dashboard → App → Webhook URL.
- [ ] Subscribe to events: `message`, `message_edit`. (Cut 1 doesn't render product/order webhooks — they go to `webhook_events` as IGNORED.)
- [ ] Confirm callback IP allow-list (Shopee publishes a CIDR list; nginx must accept those source IPs).

### §0.2 — TikTok Shop-specific partner setup (one-time)

- [ ] Apply for TikTok Shop Partner API access at https://partner.tiktokshop.com/ws?_lang=en (region = VN).
- [ ] Note `app_key` (numeric) + `app_secret` (hex).
- [ ] Confirm scopes: `seller.im.message`, `seller.im.basic`, `seller.shop.info`. If a different scope name is returned by TikTok's OAuth consent screen, update `config/services.php` `tiktok_shop.oauth_scopes` to match exactly (TikTok uses string-comparison, not normalization).
- [ ] **Spike to validate webhook signature scheme against a real partner webhook.** Cut 1 ships assuming TikTok Open Platform format (`TikTok-Signature: t=<unix>,s=<hex>`). On the first real push, capture the actual headers and re-run:
    ```
    php artisan pilot:check --provider=TIKTOK_SHOP --workspace=<slug>
    ```
    If it logs `INVALID_SIGNATURE`, check whether TikTok Shop Partner uses the `Authorization` header instead and file a new middleware variant. **Do not ship to pilot until this passes.**
- [ ] Verify shop_cipher is populated in `channel_accounts.credentials.shop_cipher` after OAuth round-trip (required for every send_message call).

---

## §1 — Smoke (every pilot shop, before letting them go live)

Run `scripts/pilot_smoke.sh --provider=<shopee|tiktok-shop> --workspace=<slug> [--account=<uuid>]`.

The script runs these checks in order and aborts on the first failure:

1. **Health probe**: `curl -fsS https://webhook.qrf.vn/up` → 200.
2. **DNS**: resolve `webhook.qrf.vn` to the expected VPS IP.
3. **Channel account**: load by `--account` UUID (or pick the most recent ACTIVE account for the workspace) — must be `status=ACTIVE`.
4. **No pending outbox**: `outbox_messages.status IN (QUEUED, RETRYING) COUNT = 0`. If non-zero, either run `php artisan queue:work --once` to drain or fix the underlying send failure.
5. **No REAUTH_REQUIRED**: `channel_accounts.last_error_code != REAUTH_REQUIRED`. If set, re-run the OAuth flow at `/admin/channels`.
6. **Token validity**: `credentials.access_token_expires_at > now() + 1h`. If within 1h, force-refresh: `php artisan "shopee:refresh-token" <account_uuid>` (no equivalent for TikTok yet — re-run OAuth if expired).
7. **Inbound**: POST a synthetic `NEW_MESSAGE` (Shopee: `message` event) to the webhook URL with valid HMAC. Verify:
   - HTTP 200 returned.
   - 1 row in `messages` table with the test `message_id`.
   - 1 row in `webhook_events` with `event_type=message, status=PROCESSED`.
8. **Outbound**: POST a synthetic outbound to the local `php artisan pilot:send-test` command (no actual API call). Verify the OutboxMessage row reaches status `SENT` after the job runs.
9. **Webhook freshness**: re-call `GET https://webhook.qrf.vn/up` and confirm a new log line appeared in `/var/log/nginx/crm-webhook.access.log` within 1s.

### §1.1 — What the smoke script does NOT do

- **Real provider round-trip**: the script never hits Shopee or TikTok's actual servers. The synthetic inbound is signed with the same secret the production HMAC middleware expects, so it exercises the verify + ingest path, but it doesn't confirm Shopee/TikTok will actually call our URL.
- **End-to-end with real buyer**: the smoke covers plumbing. The first real test is the pilot shop's first real buyer message.

---

## §2 — Pilot day (production rollout)

Do these in order on the day you enable a real shop.

### §2.1 — T-24h

- [ ] Notify the shop owner what to expect: first message from a real buyer will land in the Inbox within ~2s of being sent in Shopee/TikTok Shop.
- [ ] Provide the shop owner with the "what to send first" instructions:
    > Open Shopee Chat (or TikTok Shop inbox), find the buyer's chat, send any short text like "hello".
    > If the message doesn't appear in your dashboard Inbox within 5 seconds, contact [ops contact].
- [ ] Confirm the pilot shop's first inbound timestamp is within 5s of the test message.

### §2.2 — T+0h (live, first buyer message)

- [ ] Confirm `php artisan pilot:check --workspace=<slug>` reports ✓ for every line.
- [ ] Watch `/var/log/nginx/crm-webhook.access.log` for POSTs to `/webhooks/<provider>/<uuid>` — should match every buyer message in real-time.
- [ ] Open the Inbox dashboard at `https://<slug>.qrf.vn/admin/inbox` — should populate live.

### §2.3 — T+24h (first day review)

- [ ] Pull webhook_events counts grouped by status:
    ```sql
    SELECT status, COUNT(*) FROM webhook_events
    WHERE channel_account_id = '<account_uuid>'
      AND created_at > now() - interval '24 hours'
    GROUP BY status;
    ```
    Expected: `PROCESSED` dominates, `IGNORED` minor (unsupported types), `FAILED` ≈ 0.
- [ ] Pull outbox_messages counts grouped by status:
    ```sql
    SELECT status, COUNT(*) FROM outbox_messages
    WHERE channel_account_id = '<account_uuid>'
      AND created_at > now() - interval '24 hours'
    GROUP BY status;
    ```
    Expected: `SENT` dominates, `RETRYING` minor (transient), `FAILED` ≈ 0.
- [ ] If `FAILED > 0`: run `php artisan pilot:check` and check `docs/OPS_WEBHOOKS.md` §Troubleshooting.
- [ ] If `INVALID_SIGNATURE > 0`: re-check signature scheme — first sign of TikTok Shop Partner using a different header than Open Platform.
- [ ] First-day SLA target: **99% of buyer messages ingested within 5s**.

---

## §3 — Rollback

If the pilot needs to roll back to manual handling before cut 2 GA:

1. **Stop ingest**: `php artisan pilot:pause <account_uuid>` (custom command, ships in cut 2). For now, set `channel_accounts.status = DISABLED` from the admin UI — webhooks return 200 with `{"ok": true, "ignored": "channel disabled"}` but no new messages land in the Inbox.
2. **Drain outbox**: `php artisan queue:work --queue=default --max-jobs=0 --max-time=300` to finish in-flight sends.
3. **Re-enable in shop dashboard**: re-point the shop's webhook callback URL to its original /manual inbox endpoint.
4. **Notify shop owner** that we're back on manual handling, ETA to re-enable.
5. **Post-mortem**: write incident to `docs/incidents/YYYY-MM-DD-<provider>-<slug>.md` with root cause + cut 2 fix.

---

## §4 — Going from pilot → GA

Per spec 12 (Shopee readiness) and spec 14 (TikTok readiness), the pilot gate
is reached when the smoke runs clean for 14 consecutive days and at least
3 distinct VN shops have completed a full message round-trip.

GA requirements not covered by this checklist:

- [ ] Cut 2 features (typing/read indicators, attachment round-trip, cold-start conversation sync).
- [ ] Multi-tenant billing (workspace plan + message volume metering).
- [ ] Provider rotation: cut 2 introduces `provider_priority` so a workspace can route via Shopee first and TikTok as fallback.
- [ ] Audit log export (compliance for enterprise pilots).