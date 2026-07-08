#!/usr/bin/env bash
# Pilot smoke test for a single Shopee or TikTok Shop channel account.
#
# Exercises the FULL inbound + outbound path without hitting Shopee/TikTok
# production servers. Synthetic webhooks are signed with the same secret the
# production HMAC middleware expects, so this validates the verify + ingest
# pipeline end-to-end.
#
# Usage:
#   scripts/pilot_smoke.sh --provider=shopee --workspace=<slug> [--account=<uuid>]
#   scripts/pilot_smoke.sh --provider=tiktok-shop --workspace=<slug> [--account=<uuid>]
#
# Exit codes:
#   0  = all checks passed
#   1  = a check failed
#   2  = usage error (missing flags, bad provider)
#
# Reads from .env or env file:
#   APP_URL          — e.g. https://acme.qrf.vn
#   WEBHOOK_HOST     — e.g. webhook.qrf.vn (default: webhook.<APP_DOMAIN>)
#   DB_*             — connection for the channel-account lookup
#   APP_KEY          — Laravel app key (for HMAC if needed)
#
# Most checks ALSO can be run in-app via `php artisan pilot:check` — this
# script is the operator-friendly shell wrapper that does the HTTP probes
# the artisan command can't do (real curl against webhook.qrf.vn).

set -euo pipefail

# ---------- parse args ----------
PROVIDER=""
WORKSPACE=""
ACCOUNT=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --provider=*)   PROVIDER="${1#*=}" ;;
        --workspace=*)  WORKSPACE="${1#*=}" ;;
        --account=*)    ACCOUNT="${1#*=}" ;;
        --help|-h)      sed -n '2,28p' "$0"; exit 0 ;;
        *)              echo "Unknown arg: $1" >&2; exit 2 ;;
    esac
    shift
done

if [[ -z "$PROVIDER" || -z "$WORKSPACE" ]]; then
    echo "ERROR: --provider and --workspace are required." >&2
    echo "Usage: $0 --provider=<shopee|tiktok-shop> --workspace=<slug> [--account=<uuid>]" >&2
    exit 2
fi

# Normalize provider to the URL path the route uses.
case "$PROVIDER" in
    shopee)        ROUTE_PATH="shopee" ;;
    tiktok-shop|TTIKTOK_SHOP|tiktok) ROUTE_PATH="tiktok-shop" ;;
    *)             echo "Unknown provider: $PROVIDER (use 'shopee' or 'tiktok-shop')" >&2; exit 2 ;;
esac

# ---------- load env ----------
if [[ -f .env ]]; then
    set -a; source .env; set +a
fi

WEBHOOK_HOST="${WEBHOOK_HOST:-${APP_TENANT_WEBHOOK_SUBDOMAIN:-webhook}.${APP_TENANT_DOMAIN:-qrf.vn}}"
WEBHOOK_BASE="https://${WEBHOOK_HOST}"

# ---------- helpers ----------
RED=$'\033[0;31m'; GRN=$'\033[0;32m'; YLW=$'\033[0;33m'; RST=$'\033[0m'
PASS=0; FAIL=0

ok()   { echo "${GRN}✓${RST} $*"; PASS=$((PASS+1)); }
fail() { echo "${RED}✗${RST} $*"; FAIL=$((FAIL+1)); }
info() { echo "${YLW}→${RST} $*"; }
hdr()  { echo; echo "${YLW}=== $* ===${RST}"; }

require() {
    command -v "$1" >/dev/null 2>&1 || { echo "Missing required command: $1" >&2; exit 2; }
}
require curl
require php
require jq || true   # optional — fall back to grep if missing

# ---------- §1.1 webhook ingress reachable ----------
hdr "1. Webhook ingress reachable"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${WEBHOOK_BASE}/up" || echo "000")
if [[ "$HTTP_CODE" == "200" ]]; then
    ok "/up returned 200 from ${WEBHOOK_BASE}"
else
    fail "/up returned ${HTTP_CODE} from ${WEBHOOK_BASE} (expected 200)"
    echo "Aborting — webhook ingress must be reachable before smoke." >&2
    exit 1
fi

# ---------- §1.2 DNS sanity ----------
hdr "2. DNS resolves"
RESOLVED_IP=$(getent hosts "${WEBHOOK_HOST}" 2>/dev/null | awk '{print $1}' | head -1)
if [[ -n "$RESOLVED_IP" ]]; then
    ok "${WEBHOOK_HOST} -> ${RESOLVED_IP}"
else
    fail "${WEBHOOK_HOST} did not resolve"
    exit 1
fi

# ---------- §1.3 channel account lookup (via artisan) ----------
hdr "3. Channel account lookup"
if [[ -z "$ACCOUNT" ]]; then
    info "No --account given; resolving via artisan pilot:check"
    ACCOUNT=$(php artisan pilot:check --provider="$PROVIDER" --workspace="$WORKSPACE" --resolve-account 2>/dev/null | tail -1)
    if [[ -z "$ACCOUNT" || "$ACCOUNT" == *"No active"* ]]; then
        fail "No ACTIVE channel account found for provider=$PROVIDER workspace=$WORKSPACE"
        exit 1
    fi
    ok "resolved account = ${ACCOUNT}"
else
    ok "using account = ${ACCOUNT}"
fi

# Pull the channel account details via artisan so we get the webhook_secret too.
ACCT_JSON=$(php artisan pilot:check --provider="$PROVIDER" --workspace="$WORKSPACE" --account="$ACCOUNT" --json 2>/dev/null || true)
STATUS=$(echo "$ACCT_JSON" | jq -r '.status // empty' 2>/dev/null || true)
SECRET=$(echo "$ACCT_JSON" | jq -r '.webhook_secret // empty' 2>/dev/null || true)

if [[ "$STATUS" == "ACTIVE" ]]; then
    ok "account status = ACTIVE"
else
    fail "account status = ${STATUS:-<unknown>} (expected ACTIVE)"
    [[ -n "$STATUS" ]] || echo "artisan output was: $ACCT_JSON" >&2
    exit 1
fi

if [[ -z "$SECRET" ]]; then
    fail "could not read webhook_secret"
    exit 1
fi
ok "loaded webhook_secret (length=${#SECRET})"

# ---------- §1.4 no pending outbox ----------
hdr "4. No pending outbox"
PENDING=$(php artisan pilot:check --provider="$PROVIDER" --account="$ACCOUNT" --pending-outbox 2>/dev/null | tail -1)
if [[ "$PENDING" == "0" ]]; then
    ok "pending outbox = 0"
else
    fail "pending outbox = ${PENDING} (drain with: php artisan queue:work --once)"
fi

# ---------- §1.5 no REAUTH_REQUIRED ----------
hdr "5. No REAUTH_REQUIRED"
ERR=$(php artisan pilot:check --provider="$PROVIDER" --account="$ACCOUNT" --last-error 2>/dev/null | tail -1)
if [[ "$ERR" == "REAUTH_REQUIRED" ]]; then
    fail "last_error_code = REAUTH_REQUIRED (re-run OAuth flow at /admin/channels)"
else
    ok "last_error_code = ${ERR:-<none>}"
fi

# ---------- §1.6 token validity ----------
hdr "6. Access token validity"
EXPIRES_IN=$(php artisan pilot:check --provider="$PROVIDER" --account="$ACCOUNT" --token-ttl 2>/dev/null | tail -1)
if [[ "$EXPIRES_IN" == "<missing>" || -z "$EXPIRES_IN" ]]; then
    info "no access_token_expires_at on file — skip"
elif (( EXPIRES_IN > 3600 )); then
    ok "token expires in ${EXPIRES_IN}s (>1h)"
else
    fail "token expires in ${EXPIRES_IN}s (<1h; force-refresh before pilot)"
fi

# ---------- §1.7 synthetic inbound ----------
hdr "7. Synthetic inbound (signed webhook)"
MSG_ID="smoke-$(date +%s)-$$"
case "$ROUTE_PATH" in
    shopee)
        BODY=$(cat <<EOF
{"message_id":"${MSG_ID}","shop_id":0,"conversation_id":"SMOKE","message_type":"text","content":{"text":"smoke test"},"buyer_id":"smoke-buyer","buyer_name":"Smoke Test","created_timestamp":$(date +%s),"version":1}
EOF
        )
        SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
        HEADERS=(-H "X-Shopee-Signature: ${SIG}" -H "Content-Type: application/json")
        ;;
    tiktok-shop)
        TS=$(date +%s)
        BODY=$(cat <<EOF
{"event_type":"NEW_MESSAGE","message_id":"${MSG_ID}","conversation_id":"SMOKE","shop_id":"","message_type":"text","sender":{"open_id":"smoke","nickname":"Smoke"},"content":{"text":"smoke test"},"created_at":${TS},"version":1}
EOF
        )
        SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
        HEADERS=(-H "TikTok-Signature: t=${TS},s=${SIG}" -H "Content-Type: application/json")
        ;;
esac

RESP=$(curl -s -o /tmp/smoke_resp.$$ -w "%{http_code}" \
    -X POST "${WEBHOOK_BASE}/webhooks/${ROUTE_PATH}/${ACCOUNT}" \
    "${HEADERS[@]}" \
    --data-binary "$BODY" || echo "000")

if [[ "$RESP" == "200" ]]; then
    ok "inbound POST returned 200 (msg_id=${MSG_ID})"
    info "response: $(cat /tmp/smoke_resp.$$)"
else
    fail "inbound POST returned ${RESP} (expected 200)"
    cat /tmp/smoke_resp.$$ >&2
fi
rm -f /tmp/smoke_resp.$$

# ---------- §1.8 outbound dry run ----------
hdr "8. Outbound dry-run"
SEND_RESULT=$(php artisan pilot:check --provider="$PROVIDER" --account="$ACCOUNT" --send-test 2>&1 | tail -1)
case "$SEND_RESULT" in
    SENT)        ok "outbox reached SENT after send-test" ;;
    QUEUED)      ok "outbox QUEUED (worker drained it to SENT between checks?)" ;;
    RETRYING)    info "send-test returned RETRYING — likely rate-limited; check backoff" ;;
    FAILED)      fail "send-test returned FAILED — see provider_response in outbox" ;;
    *)           info "send-test output: $SEND_RESULT" ;;
esac

# ---------- §1.9 webhook freshness ----------
hdr "9. Webhook ingress freshness (post-smoke)"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${WEBHOOK_BASE}/up" || echo "000")
[[ "$HTTP_CODE" == "200" ]] && ok "/up still 200" || fail "/up returned ${HTTP_CODE}"

# ---------- summary ----------
echo
echo "${YLW}=== Summary ===${RST}"
echo "  passed: ${GRN}${PASS}${RST}"
echo "  failed: ${RED}${FAIL}${RST}"
echo

if (( FAIL > 0 )); then
    echo "${RED}PILOT SMOKE FAILED${RST}"
    echo "Fix the failing checks above before letting real buyers in. See docs/PILOT_CHECKLIST.md."
    exit 1
fi

echo "${GRN}PILOT SMOKE OK${RST}"
echo "Channel account ${ACCOUNT} is ready for pilot. See docs/PILOT_CHECKLIST.md §2 for first-buyer rollout."
exit 0