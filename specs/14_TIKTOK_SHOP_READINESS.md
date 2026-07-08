# 14 TikTok Shop Chat VN Cut 1 Readiness

> Companion to `specs/13_TIKTOK_SHOP_VN.md` (technical contract). Mirrors
> the spec 12 / Shopee readiness structure so both connectors use the same
> DoD / gate / risk register pattern.
>
> **Target: TikTok Shop VN cut 1 in pilot by end of August 2026** (parallel
> to Shopee cut 1 — same milestones, shared infrastructure).

## Locked decisions (decision log)

| # | Decision | Rationale | Alternatives rejected |
|---|----------|-----------|------------------------|
| 1 | Chat only (NEW_MESSAGE event only) | Cut scope; spec 11 set the pattern | Big-bang w/ orders + products |
| 2 | VN region only | Validate pattern before multi-region | All-region from day 1 |
| 3 | OAuth 2.0 per seller | Industry standard for multi-tenant SaaS | Static API key (rejected unless Tùng confirms tier bypass) |
| 4 | Parallel build with Shopee cut 1 | Shared infra already in place; no serialization | Sequential after Shopee GA |
| 5 | Reuse webhook.qrf.vn ingress | Same vhost binding pattern works | New ingress subdomain (overkill) |
| 6 | Reuse workspace_settings for `tiktok.partner_credentials` | Same pattern as Shopee partner creds | New table per platform |
| 7 | Reuse ChannelAdapterRegistry + SendChannelMessageJob | Existing contract; per-provider adapter only | New job per platform |
| 8 | Provider enum extended with `TIKTOK_SHOP` | Migration 2026_07_08_000001 already done | Per-platform columns |

## Definition of Done per milestone

### W1 — G0: Prep
- [ ] **Auth model spike**: confirm whether chat endpoints need full seller OAuth or app-only auth
- [ ] **Signature scheme spike**: confirm exact header name + HMAC input format against current TikTok Open Platform docs
- [ ] spec 13 + 14 merged
- [ ] `TikTokShopAdapter` skeleton (constructor + 6 stub methods that throw with spec reference)
- [ ] `VerifyTikTokSignature` middleware skeleton (HMAC verify stub)
- [ ] `ChannelAdapterRegistry` already wired (verified in W1 of Shopee)
- [ ] `.env.production.example` documents `TIKTOK_API_BASE`, `TIKTOK_REGION`
- [ ] All existing tests still green

### W2 — G1.1: OAuth + tokens
- [ ] OAuth state token (CSRF, single-use, 10-min TTL) — reuse `ShopeeOAuthState` pattern as `TikTokOAuthState`
- [ ] `/admin/channels/tiktok/connect` redirects to TikTok with state + app_id + redirect_uri + scope
- [ ] `/admin/channels/tiktok/callback` handles `code` exchange; persists `access_token` + `refresh_token` + `access_token_expires_at` encrypted
- [ ] Callback handles missing `code`, expired `code`, `invalid_redirect_uri`, `access_denied`
- [ ] `RefreshTikTokAccessTokenJob` at 75% of TTL
- [ ] Failed refresh flips channel account to `DEGRADED` + `REAUTH_REQUIRED`
- [ ] Unit + integration tests

### W3 — G1.2: Inbound
- [ ] `ProviderWebhookController::tiktok` mounted on `webhook.qrf.vn`
- [ ] `VerifyTikTokSignature` middleware: HMAC-SHA256 over `timestamp.body`, keyed by `app_secret`; reject timestamp drift > 5 min
- [ ] Idempotency: `tiktok:{account_id}:msg:{message_id}` enforced
- [ ] Text + image normalized via adapter
- [ ] Unsupported types persisted as `IGNORED`
- [ ] Edit (version > 1): in-place update of existing message
- [ ] Sender info mapped to external identity
- [ ] Smoke test: real TikTok sandbox pushes NEW_MESSAGE → conversation appears in Inbox within 2s

### W4 — G1.3: Outbound + hardening
- [ ] `SendTikTokMessageJob` posts to im/send_message
- [ ] Image: pre-upload via im/media/upload, then send with image_url
- [ ] Outbox status transitions `QUEUED → SENDING → SENT` on success
- [ ] 5xx and network timeout retry with backoff 1m, 5m, 15m, 1h; max 5 attempts
- [ ] HTTP 429 honors `retry_after`
- [ ] auth_error: refresh + 1 retry, then `DEGRADED`
- [ ] `recipient_blocked` / `conversation_closed` / `recipient_not_found`: `FAILED`, no retry
- [ ] Admin health card: status, last inbound timestamp, pending message count, last error
- [ ] Channel account CRUD UI shows TikTok-specific fields
- [ ] All W1-W3 DoDs green

### Pilot gate (W5+) — ready for real VN shop
- [ ] All W4 DoDs green
- [ ] 24h staging soak: 5xx rate < 0.5%
- [ ] Inbound p95 latency < 2s
- [ ] Outbound p95 latency < 3s
- [ ] Zero data loss in synthetic failure scenarios
- [ ] At least 1 pilot shop committed

### GA gate (post-pilot)
- [ ] Pilot ran for >= 2 weeks
- [ ] Pilot uptime >= 99%
- [ ] Token refresh success >= 99% over pilot
- [ ] 0 P0/P1 bugs open for 1 week
- [ ] >= 3 pilot shops completed >= 50 conversations each without manual intervention
- [ ] Doc site updated with merchant-facing TikTok onboarding guide

## Estimate breakdown

| # | Task | Dev-days | Confidence | Depends on |
|---|------|----------|-----------|------------|
| 1 | Auth model + signature spike | 0.5 | medium | docs |
| 2 | `TikTokShopAdapter` skeleton + registry wiring | 0.5 | high | none |
| 3 | `VerifyTikTokSignature` middleware | 0.5 | medium | #1 |
| 4 | OAuth state token + controller + callback routes | 2 | high | #1 |
| 5 | Token refresh job + DEGRADED transition | 1 | high | #4 |
| 6 | Inbound webhook controller + HMAC integration | 1.5 | high | #3 |
| 7 | Idempotency + edit handling | 1 | high | none |
| 8 | Outbound send + outbox wiring | 1.5 | medium | rate limits |
| 9 | Retry policy + 429 backoff | 1 | medium | rate limits |
| 10 | Admin health card + TikTok-specific UI fields | 1 | high | #4, #6, #8 |
| 11 | Unit tests | 2 | high | code |
| 12 | Integration tests (HTTP + fakes) | 2 | high | code |
| 13 | Sandbox setup doc + smoke script | 0.5 | high | reuse Shopee pattern |
| 14 | Buffer 30% | ~5 | - | - |
| | **Total** | **~20 dev-days** | medium | |

**Shared infrastructure already built (Shopee cut 1 paid for it):**
- `webhook.qrf.vn` ingress + binding
- `ChannelAdapterRegistry` + `InboundMessageIngestor` + `SendChannelMessageJob`
- `workspace_settings` + `WorkspaceSettings` service
- `VerifyShopeeSignature` middleware pattern (will be replicated for TikTok with different header/HMAC)

**With 1 dev:** ~4 weeks calendar.
**With 2 devs (parallelizing W3 + W4):** ~2-2.5 weeks calendar.

**Confidence:** medium — signature scheme and auth requirements both need spikes against current TikTok docs. The implementation pattern is well-understood (mirrors Shopee) so the structural risk is low; the integration-shape risk is moderate.

## Go/No-Go gates

### W1 → W2
- All W1 DoDs green
- Auth model + signature spikes completed (the structural risk must be resolved before code)

### W2 → W3
- All W2 DoDs green
- OAuth round-trip works end-to-end (in sandbox if available)

### W3 → W4
- All W3 DoDs green
- Inbound ingest latency p95 < 2s
- Zero duplicate messages on 100-push stress test

### W4 → Pilot
- All W4 DoDs green
- 24h staging soak passes all SLA targets
- At least 1 pilot shop committed

### Pilot → GA
- All Pilot gate criteria met
- See GA gate list above

**A gate failure is a stop-the-line event**, not a "push through and fix later". Failed gates trigger a 30-min incident review before continuing.

## Risk register (TikTok-specific)

| ID | Risk | Likelihood | Impact | Owner | Mitigation |
|----|------|-----------|--------|-------|-----------|
| T1 | Auth model needs full partner tier not yet obtained | medium | high | Tùng | Spike in W1; fallback = defer until tier available |
| T2 | Signature scheme differs from assumed | medium | high | dev | W1 spike before W3 code |
| T3 | Sandbox not available for TikTok | medium | medium | Tùng | Manual test via partner dashboard only; document workaround |
| T4 | Rate limit (typically 100 req/min/shop) bottlenecks busy shop | medium | medium | dev | Outbox queues; surface rate pressure in health card |
| T5 | Refresh token loss | high | medium | dev | REAUTH_REQUIRED banner prominent |
| T6 | `NEW_MESSAGE` payload missing fields we need | medium | medium | dev | Defensive mapping + UNSUPPORTED fallback for missing keys |
| T7 | Shop-owner onboarding flow complex | medium | medium | dev | OAuth round-trip via existing connect button (Shopee pattern) |
| T8 | Build team context-switching between Shopee + TikTok | medium | low | Tùng | Sequential W1-W2 across both; parallel only after both have skeletons |

**Inherited from Shopee** (mitigated by shared infrastructure):
- webhook.qrf.vn security (closed)
- Worker queue back-pressure (closed)
- Ingestor cross-module debt (ponytail'd, closed)

## Rollout plan

Mirrors Shopee rollout (`specs/12_SHOPEE_CHAT_VN_READINESS.md § Rollout`):

### Phase 0: Internal alpha (W5)
- Tùng's own TikTok shop (test account) + 1 internal team member
- Daily monitoring via `/up` health + queue depth
- Bug bash at end of week

### Phase 1: Closed beta (pilot, 2 weeks)
- 3-5 hand-picked VN sellers
- Weekly check-in
- Survey at week 2

### Phase 2: Open beta
- In-app banner: "TikTok Shop Chat is now in beta"
- Doc site updated
- Email to existing customers

### Phase 3: GA
- Remove "beta" label
- Standard support tier

## Communication

In-app banner (Phase 2):

```
🎵 TikTok Shop Chat đã sẵn sàng (beta)
Kết nối shop TikTok VN của bạn để inbox CRM đồng bộ chat 2 chiều.
[Thêm TikTok Channel] [Đọc hướng dẫn]
```

Email (Phase 2):

Subject: `TikTok Shop Chat VN — kết nối shop của bạn với CRM`

## Support plan

Same L1/L2/L3 triage as Shopee:

- **L1 (admin self-service):** Re-register webhook, refresh token, view health card
- **L2 (dev):** Laravel logs, TikTok Open Platform dashboard, `webhook_events` SQL
- **L3 (Tùng):** Code changes, vendor escalation

Known limitations to communicate upfront:
- VN only
- Chat only (NEW_MESSAGE event)
- Refresh token re-auth required every ~24h (verify TTL)

Operational thresholds:
- Sustained `5xx > 1%` over 5 min → page on-call
- `last_inbound_at > 5 min` → page on-call
- Queue depth `> 1000` for 10 min → page on-call

## Success metrics

### Adoption (3 months post-GA)
- **Primary:** 30% of active workspaces connect >= 1 TikTok shop
- **Leading indicator:** 50% of new workspaces try TikTok within first week

### Operational (rolling 30 days)
- 5xx rate < 0.5%
- Inbound ingest p95 < 2s
- Outbound send p95 < 3s
- Token refresh success >= 99%
- Zero data loss incidents

### Business
- Reply time parity with Shopee (baseline established in Phase 1)
- Conversion rate from TikTok chat >= Shopee baseline

## Next actions

| # | Action | Owner | Due | Blocks |
|---|--------|-------|-----|--------|
| 1 | Auth model + signature scheme spike | Tùng (review docs) + dev | this week | W1 finish |
| 2 | Apply TikTok Shop Partner app (VN) | Tùng | this week | W2 |
| 3 | Lock credentials storage key (`tiktok.partner_credentials`) | dev | this week | W2 |
| 4 | Skeleton `TikTokShopAdapter` + verify middleware | dev | W1 close | W2 |
| 5 | OAuth round-trip code | dev | W2 start | W2 close |
| 6 | Sandbox access (if TikTok has one) | Tùng | before W3 | W3 smoke |
| 7 | Pilot shop recruitment | Tùng | before W5 | Pilot gate |
| 8 | Doc site entry (merchant onboarding) | dev | W4 end | Phase 2 |

**Critical path:** #1 (spike) and #2 (Partner app approval) are the only
externally-blocked items. Everything else parallelizes internally and
reuses Shopee's shared infrastructure.