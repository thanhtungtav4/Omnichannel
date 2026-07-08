# 12 Shopee Chat VN Cut 1 Readiness

> Companion to `specs/11_SHOPEE_CHAT_VN.md` (technical contract) and the
> conversation in 2026-07-08. This doc turns "ready to code informed" into
> "ready to ship": DoD per milestone, estimate breakdown with confidence,
> go/no-go gates, project risk register, rollout plan, support model, and
> success metrics.
>
> **Target: Shopee Chat VN cut 1 in pilot by end of August 2026.**

## Locked decisions (decision log)

| # | Decision | Rationale | Alternatives rejected |
|---|----------|-----------|------------------------|
| 1 | Shopee Chat first, TikTok Shop later | Shopee Open Platform v2 more mature; webhooks stable | Parallel build (2 dev, more risk) |
| 2 | Chat only, no orders/products | Cut scope to ship; orders is a separate adapter | Big-bang integration |
| 3 | VN region only | Validate pattern before multi-region; OAuth simpler | All-SEA from day 1 |
| 4 | August 2026 target | External dependency (Shopee partner approval) sets ceiling | Q4 (delays revenue signal) |
| 5 | Dedicated `webhook.qrf.vn` vhost | Defense in depth, isolated logs, method restriction | Share with tenant vhost |
| 6 | Webhook routes bound to host via `Route::domain` | App-layer rejection if proxy misroutes | Trust nginx alone |
| 7 | Provider enum extended with both SHOPEE and TIKTOK_SHOP placeholder | One migration, two future adapters | Two migrations later |
| 8 | Adapter implements existing `ChannelAdapter` contract | Spec 05/10 contract already battle-tested for 4 adapters | New contract for Shopee |
| 9 | Smoke test in Shopee sandbox before production | Real Shopee shape vs. mocking | Mock-only tests |
| 10 | REAUTH_REQUIRED surfaced in admin UI | Refresh token expiry is unavoidable | Silent re-prompt |

## Definition of Done per milestone

Each milestone ships only when every box is checked AND the go/no-go gate
at its end passes.

### W1 — G0: Prep
- [ ] Shopee Open Platform VN dev account application submitted (Tùng)
- [ ] Sandbox access granted and tested manually (Tùng)
- [ ] spec 12 (this doc) merged
- [ ] spec 11 technical contract merged
- [ ] Decision locked on platform-level credentials storage
- [ ] Migration for credentials storage merged
- [ ] `App\Modules\Channels\Adapters\ShopeeAdapter` skeleton (constructor + 6 stub methods) merged
- [ ] HMAC signature verification middleware (`VerifyShopeeSignature`) merged with `INVALID_SIGNATURE` test
- [ ] `ChannelAdapterRegistry` has SHOPEE entry pointing at skeleton (throws `NotImplemented` for cut-1-not-ready methods)
- [ ] `.env.production.example` has `SHOPEE_API_BASE`, `SHOPEE_REGION` placeholders
- [ ] All existing tests still green

### W2 — G1.1: OAuth + tokens
- [ ] OAuth state token (CSRF protection) generated and verified per connect round-trip
- [ ] `/admin/channels/shopee/connect` redirects to Shopee with `partner_id`, `redirect_uri`, `state`, `scope`
- [ ] `/admin/channels/shopee/callback` handles `code` exchange; persists `access_token` + `refresh_token` + `access_token_expires_at` encrypted at rest
- [ ] Callback handles missing `code` (4xx with actionable error)
- [ ] Callback handles Shopee `error=invalid_redirect_uri` (clear message: register URI in dashboard)
- [ ] Callback handles expired `code` (Shopee returns error)
- [ ] `RefreshShopeeAccessTokenJob` runs at 75% of TTL; rotates tokens atomically
- [ ] Failed refresh flips channel account to `DEGRADED` + `REAUTH_REQUIRED` flag
- [ ] Unit tests: state token, callback happy path, callback error paths, refresh job
- [ ] Integration test: full OAuth round-trip via fake Shopee OAuth server

### W3 — G1.2: Inbound
- [ ] `ProviderWebhookController::shopee` mounted on `webhook.qrf.vn` only
- [ ] HMAC verify before any DB write
- [ ] Idempotency: `shopee:{account_id}:msg:{message_id}` enforced
- [ ] Text message: persists `messages` row with `body_text`, creates conversation if new
- [ ] Image message: persists attachments array
- [ ] Product message: persists as `IGNORED` in `webhook_events` (cut 1 non-goal)
- [ ] Edit message: updates existing message in place (no duplicate)
- [ ] Sender info (`buyer_id`, `buyer_name`, `buyer_portrait_url`) mapped to external identity
- [ ] Smoke test: real VN sandbox shop pushes message → conversation appears in Inbox within 2s
- [ ] Unit + integration tests cover above

### W4 — G1.3: Outbound + hardening
- [ ] `SendShopeeMessageJob` posts to `/api/v2/seller_chat/send_message`
- [ ] Image send: pre-upload via `/api/v2/media/upload_image`, then send with image_url
- [ ] `outbox_messages.status` transitions `QUEUED → SENDING → SENT` on success
- [ ] 5xx and network timeout retry with backoff 1m, 5m, 15m, 1h; max 5 attempts
- [ ] HTTP 429 honors `retry_after`
- [ ] Token-expired: refresh + 1 retry, then `DEGRADED`
- [ ] `buyer_blocked` / `shop_blocked` / `recipient_not_found`: `FAILED` without retry, surfaced in admin
- [ ] Admin health card surfaces: status, last inbound timestamp, pending message count (polled), last error
- [ ] Channel account CRUD UI shows Shopee-specific fields (`shop_id`, `merchant_id`, token expiry)
- [ ] All W1-W3 DoDs green

### Pilot gate (W5+) — ready for real VN shop
- [ ] All W4 DoDs green
- [ ] 24h staging soak: 5xx rate < 0.5%
- [ ] Inbound p95 latency < 2s
- [ ] Outbound p95 latency < 3s
- [ ] Zero data loss in synthetic failure scenarios
- [ ] At least 1 pilot shop agreed (Tùng)

### GA gate (post-pilot)
- [ ] Pilot ran for >= 2 weeks
- [ ] Pilot uptime >= 99%
- [ ] Token refresh success >= 99% over pilot
- [ ] 0 P0/P1 bugs open for 1 week
- [ ] >= 3 pilot shops completed >= 50 conversations each without manual intervention
- [ ] Doc site updated with merchant-facing onboarding guide
- [ ] Status page entry created (manual or via provider)

## Estimate breakdown

| # | Task | Dev-days | Confidence | Depends on |
|---|------|----------|-----------|------------|
| 1 | Shopee partner app approval | n/a (external) | low | Tùng action |
| 2 | Platform credentials storage migration | 0.5 | high | decision #11 |
| 3 | ShopeeAdapter skeleton + registry wiring | 1 | high | none |
| 4 | HMAC verify middleware | 0.5 | high | Shopee API doc |
| 5 | OAuth round-trip (state, callback, error paths) | 2 | high | #1 |
| 6 | Token refresh job + DEGRADED transition | 1 | high | #5 |
| 7 | Inbound webhook controller + HMAC integration | 2 | high | #4 |
| 8 | Idempotency + edit handling | 1 | high | none |
| 9 | Outbound send job + outbox wiring | 2 | high | #5 |
| 10 | Retry policy + 429 backoff | 1 | medium | rate limits from Shopee |
| 11 | Admin health card + Shopee-specific UI fields | 1 | high | #6, #7, #9 |
| 12 | Unit tests | 2 | high | code under test |
| 13 | Integration tests (HTTP + fakes) | 2 | high | code under test |
| 14 | Sandbox smoke test | 1 | medium | #1 sandbox access |
| 15 | Rollout comms (in-app banner, doc site entry) | 1 | medium | pilot shop recruitment |
| 16 | Buffer 30% (bug fixes, review rework, env weirdness) | ~5 | - | - |
| | **Total** | **~22 dev-days** | medium | |

**With 1 dev:** ~4-5 weeks calendar (realistic including weekends off + async review).
**With 2 devs (parallelizing W3 + W4):** ~2.5-3 weeks calendar.

**Confidence assessment:**
- High (>= 90%): items 2-9, 11-13 — well-understood pattern from existing adapters
- Medium (60-80%): items 10, 14, 15 — depend on Shopee specifics not yet validated

## Go/No-Go gates

### W1 → W2
- All W1 DoDs green
- Shopee sandbox credentials obtained

### W2 → W3
- All W2 DoDs green
- OAuth round-trip works end-to-end in sandbox

### W3 → W4
- All W3 DoDs green
- Inbound ingest latency p95 < 2s in sandbox
- Zero duplicate messages on 100-push stress test

### W4 → Pilot
- All W4 DoDs green
- 24h staging soak passes all SLA targets
- At least 1 pilot shop committed

### Pilot → GA
- All Pilot gate criteria met
- See GA gate list above

**A gate failure is a stop-the-line event**, not a "push through and fix later". Failed gates trigger a 30-min incident review before continuing.

## Risk register

| ID | Risk | Likelihood | Impact | Owner | Mitigation |
|----|------|-----------|--------|-------|-----------|
| R1 | Shopee partner app rejected | medium | high | Tùng | Apply immediately; backup = focus on Zalo OA expansion while reapplying |
| R2 | Shopee API signing format different from assumed body HMAC | low | medium | dev | Verify against current Shopee Open Platform docs during W1; HMAC verify is its own milestone so failure is caught early |
| R3 | Sandbox credentials delayed | low | low | Tùng | DONE — sandbox available; see `docs/SHOPEE_SANDBOX_SETUP.md` |
| R4 | Shopee rate limit (100 req/min/shop) bottlenecks busy shop | medium | medium | dev | Outbox queues naturally; surface rate pressure in health card |
| R5 | Refresh token loss (seller revokes, 30d idle) | high | medium | dev | REAUTH_REQUIRED banner prominent; documented re-auth UX |
| R6 | Shopee changes webhook payload format mid-cut | low | high | dev | HMAC verify catches early; payload version field logged for forensics |
| R7 | partner_key leak via log or git | medium | high | dev | Sanitize logging + secret scanning in CI |
| R8 | VM resource exhausted by webhook spike | low | medium | ops | FPM pool + supervisor numprocs sized for 2x peak; alert at 70% |
| R9 | VN OAuth region-specific bug (we miss a VN-only field) | medium | medium | dev | Smoke test with real VN sandbox shop, not just mock |
| R10 | Pilot shop goes silent mid-pilot | medium | low | Tùng | Recruit 3-5 shops, not just 1 |

## Rollout plan

### Phase 0: Internal alpha (W5)
- Tùng's own shop (test account)
- Daily monitoring via `/up` health + queue depth
- Bug bash at end of week
- Gate: 0 P0 bugs, < 5 P1 bugs

### Phase 1: Closed beta (pilot, 2 weeks)
- 3-5 hand-picked VN sellers (recruited by Tùng)
- Weekly check-in: latency, error rate, user feedback
- Survey at week 2: NPS, feature requests, blockers
- Gate: see Pilot → GA criteria

### Phase 2: Open beta
- In-app banner: "Shopee Chat is now in beta — connect your shop from Channels → Add"
- Doc site updated with merchant onboarding guide
- Email to existing customers (template drafted in § Communication)
- Monitor adoption daily for first 2 weeks, then weekly

### Phase 3: GA
- Remove "beta" label
- Standard support tier
- Success metrics tracked monthly

## Communication

### In-app banner (Phase 2)
```
🛒 Shopee Chat đã sẵn sàng (beta)
Kết nối shop Shopee VN của bạn để inbox CRM đồng bộ chat 2 chiều.
[Thêm Shopee Channel] [Đọc hướng dẫn]
```

### Email to existing customers (Phase 2)
Subject: `Shopee Chat VN — kết nối shop của bạn với CRM`
Body: 3-line teaser + CTA to doc + link to add channel.

### Status / incident comms
- In-app banner with incident state
- For extended outages (>1h): email to active workspaces
- Status page (deferred — out of scope for cut 1)

## Support plan

### On-call (cut 1 scope)
- Primary: Tùng (until team grows)
- Secondary: TBD
- Escalation: dev for code bugs, ops for infra

### Triage paths
1. **L1 (admin self-service):** Re-register webhook, refresh token, view health card, replay failed webhook event
2. **L2 (dev):** Laravel logs, Shopee partner dashboard, database query for `webhook_events` / `outbox_messages`
3. **L3 (Tùng):** Same as L2 plus code changes, vendor escalation

### Customer-facing limitations to communicate upfront
- VN only (not other Shopee regions)
- Chat only (no orders, products, vouchers in cut 1)
- Refresh token re-auth required every 30 days of idle
- Image send max 5MB

### Known operational thresholds
- Sustained `5xx > 1%` over 5 min → page on-call
- `last_inbound_at > 5 min` → page on-call (suggests provider broke or webhook URL unregistered)
- Queue depth `> 1000` for 10 min → page on-call
- Token expiry within 24h and no auto-refresh possible → notify admin in UI

## Success metrics

### Adoption (3 months post-GA)
- **Target primary:** 30% of active workspaces connect >= 1 Shopee shop
- **Leading indicator:** 50% of new workspaces try Shopee within first week
- **Source:** `workspace_settings` + `channel_accounts` aggregation

### Operational (rolling 30 days)
- 5xx rate < 0.5% on `webhook.qrf.vn`
- Inbound ingest p95 latency < 2s
- Outbound send p95 latency < 3s
- Token refresh success >= 99%
- Zero data loss incidents (manual log review monthly)

### Business (subject to pilot data — define baseline in Phase 1)
- Reply time median via Shopee <= reply time median via Zalo (baseline)
- Conversion rate from Shopee chat >= Zalo baseline
- Customer NPS for Shopee users vs. non-users

## Platform credentials storage decision

> Required decision before W1 finishes. Recommendation included.

### Recommendation: `workspace_settings` table

```sql
CREATE TABLE workspace_settings (
    id UUID PRIMARY KEY,
    workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    key VARCHAR(100) NOT NULL,
    value JSONB NOT NULL,  -- encrypted via app-layer Crypt
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(workspace_id, key)
);
CREATE INDEX workspace_settings_workspace_key ON workspace_settings(workspace_id, key);
```

Per-tenant keys we expect to store:
- `shopee.partner_credentials` → `{partner_id, partner_key}` (encrypted)
- `tiktok_shop.partner_credentials` → similar (placeholder for cut 2)
- `meta.app_credentials` → for FB (could move here too in cut 2)
- Workspace-level toggles, feature flags, etc.

### Pros
- Future-proof for multiple partner platforms
- Clean separation from `channel_accounts` (which holds per-shop data)
- No migration needed when adding new partner keys
- Encryption layer is uniform

### Cons
- One more table to maintain
- JSONB is harder to query specific sub-fields (but we don't need to — config lookup only)

### Alternatives rejected
- Extend `channel_accounts.credentials` with partner-level fields → confuses per-shop with platform-level, breaks invariants
- Per-key table (`workspace_partner_credentials_shopee`, `..._tiktok`) → table explosion
- ENV vars → doesn't work for multi-tenant SaaS

## Next actions

| # | Action | Owner | Due | Blocks |
|---|--------|-------|-----|--------|
| 1 | Apply Shopee Open Platform VN dev account | Tùng | **today** | W2, W3 |
| 2 | Lock credentials storage decision (recommend `workspace_settings`) | Tùng | this week | W1 finish |
| 3 | Implement `workspace_settings` migration after decision | dev | this week | W2 |
| 4 | Request sandbox access from Shopee | Tùng | DONE — sandbox available 2026-07-08 | W3 |
| 5 | Recruit 3-5 pilot shops (or commit own shop) | Tùng | before W5 | Pilot gate |
| 6 | Skeleton `ShopeeAdapter` + HMAC middleware | dev | next Monday | W1 close |
| 7 | OAuth round-trip code | dev | W2 start | W2 close |
| 8 | Pilot retro template | Tùng | W5 start | Phase 2 start |
| 9 | Doc site entry (merchant onboarding) | dev | W4 end | Phase 2 |

**Critical path:** #1 (Shopee partner approval) is the only externally-blocked item. Everything else parallelizes internally.

## Retrospective template (post-pilot)

After Phase 1 closes, fill in:

1. **What we shipped vs. planned:** spec 11 vs. reality
2. **What slipped and why:** every DoD that took longer than estimated
3. **What we cut and why:** any items removed mid-cut
4. **What we learned:** technical, product, operational surprises
5. **What changes for cut 2:** process, architecture, scope
6. **What's still risky:** residual risks going into Phase 2

Save under `docs/RETRO_SHOPEE_CUT1_<date>.md` and reference from spec 12.