#!/usr/bin/env bash
# scripts/shopee_sandbox_smoke.sh
#
# Smoke-test the Shopee webhook ingress + outbound send against the sandbox
# (or production once you've promoted credentials). Designed to be run from
# the project root on the VPS where the app is deployed.
#
# Usage:
#   scripts/shopee_sandbox_smoke.sh inbound --tenant=sandbox-test --account=<uuid>
#   scripts/shopee_sandbox_smoke.sh outbound --account=<uuid> --text="hello"
#   scripts/shopee_sandbox_smoke.sh health
#
# Requirements: jq, curl. Reads APP_WEBHOOK_SUBDOMAIN + APP_TENANT_DOMAIN from
# the .env file in the project root, or accepts overrides via --webhook-subdomain
# and --tenant-domain.

set -euo pipefail

usage() {
  cat <<EOF
Usage: $0 <command> [options]

Commands:
  inbound    POST a synthetic Shopee-shaped payload to the webhook ingress.
  outbound   Dispatch an outbound send job and report its outcome.
  health     Probe /up on both the main app and webhook ingress.
  config     Print resolved Shopee config (env + workspace_settings).

Common options:
  --tenant=<slug>             Workspace slug (e.g. sandbox-test)
  --account=<uuid>            ChannelAccount.id
  --tenant-domain=<host>      Override APP_TENANT_DOMAIN
  --webhook-subdomain=<host>  Override APP_WEBHOOK_SUBDOMAIN
  --message-id=<id>           Override the synthetic message_id
  --text=<text>               Outbound message body (default: "sandbox smoke test")

Examples:
  $0 health
  $0 inbound --tenant=sandbox-test --account=00000000-0000-0000-0000-000000000001
  $0 outbound --account=00000000-0000-0000-0000-000000000001 --text="ping"
EOF
  exit 1
}

[[ $# -ge 1 ]] || usage
CMD=$1; shift

# ---- Load .env if present ----
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(cd "$SCRIPT_DIR/.." && pwd)
ENV_FILE="$PROJECT_ROOT/.env"
if [[ -f "$ENV_FILE" ]]; then
  set -a; source "$ENV_FILE"; set +a
fi

# ---- Defaults from env ----
TENANT_DOMAIN="${APP_TENANT_DOMAIN:-qrf.vn}"
WEBHOOK_SUBDOMAIN="${APP_WEBHOOK_SUBDOMAIN:-webhook}"

# ---- Parse options ----
TENANT=""
ACCOUNT=""
MESSAGE_ID="MSG-SANDBOX-$(date +%s)"
TEXT="sandbox smoke test"
while [[ $# -gt 0 ]]; do
  case $1 in
    --tenant=*) TENANT="${1#*=}";;
    --account=*) ACCOUNT="${1#*=}";;
    --tenant-domain=*) TENANT_DOMAIN="${1#*=}";;
    --webhook-subdomain=*) WEBHOOK_SUBDOMAIN="${1#*=}";;
    --message-id=*) MESSAGE_ID="${1#*=}";;
    --text=*) TEXT="${1#*=}";;
    -h|--help) usage;;
    *) echo "Unknown option: $1" >&2; usage;;
  esac
  shift
done

# ---- Helpers ----
need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing dep: $1" >&2; exit 2; }; }
need curl
need jq

tenant_host() {
  echo "${1}.${TENANT_DOMAIN}"
}

fetch_webhook_secret() {
  # Reads webhook_secret from the DB for a given channel account.
  # Uses php artisan tinker for portability — no DB driver needed in shell.
  local account_id=$1
  php artisan tinker --execute='
    $a = App\Modules\Channels\Models\ChannelAccount::query()
        ->withoutWorkspaceScope()
        ->whereKey("'"$account_id"'")->first();
    if (!$a) { fwrite(STDERR, "no account\n"); exit(1); }
    echo $a->webhook_secret;
  ' 2>/dev/null
}

fetch_shop_id() {
  local account_id=$1
  php artisan tinker --execute='
    $a = App\Modules\Channels\Models\ChannelAccount::query()
        ->withoutWorkspaceScope()
        ->whereKey("'"$account_id"'")->first();
    echo $a->credentials["shop_id"] ?? "";
  ' 2>/dev/null
}

hmac_sha256() {
  local body=$1 secret=$2
  printf '%s' "$body" | openssl dgst -sha256 -hmac "$secret" -hex | awk '{print $2}'
}

# ---- Commands ----

cmd_health() {
  echo "== Main app health =="
  curl -fsS -o /dev/null -w "GET https://${TENANT_DOMAIN}/ -> %{http_code}\n" \
      "https://${TENANT_DOMAIN}/up" || echo "  FAIL"
  echo
  echo "== Webhook ingress health =="
  curl -fsS -o /dev/null -w "GET https://${WEBHOOK_SUBDOMAIN}.${TENANT_DOMAIN}/up -> %{http_code}\n" \
      "https://${WEBHOOK_SUBDOMAIN}.${TENANT_DOMAIN}/up" || echo "  FAIL"
  echo
  echo "== Method-restricted check (PUT should 405) =="
  curl -s -o /dev/null -w "PUT https://${WEBHOOK_SUBDOMAIN}.${TENANT_DOMAIN}/webhooks/telegram/00000000-0000-0000-0000-000000000000 -> %{http_code}\n" \
      -X PUT "https://${WEBHOOK_SUBDOMAIN}.${TENANT_DOMAIN}/webhooks/telegram/00000000-0000-0000-0000-000000000000"
}

cmd_inbound() {
  [[ -n $TENANT ]] || { echo "--tenant required"; usage; }
  [[ -n $ACCOUNT ]] || { echo "--account required"; usage; }

  local secret shop_id
  secret=$(fetch_webhook_secret "$ACCOUNT")
  shop_id=$(fetch_shop_id "$ACCOUNT")
  if [[ -z $secret ]]; then
    echo "Could not fetch webhook_secret for account $ACCOUNT" >&2; exit 3
  fi
  if [[ -z $shop_id ]]; then
    echo "Account $ACCOUNT has no shop_id in credentials" >&2; exit 3
  fi

  local ts=$MESSAGE_ID
  local body
  body=$(cat <<EOF
{"message_id":"$ts","conversation_id":"SANDBOX-CONV-1","shop_id":$shop_id,"buyer_id":55555,"buyer_name":"Sandbox Buyer","buyer_portrait_url":"https://cf.shopee.vn/avatar","message_type":"text","content":{"text":"Hello CRM from sandbox smoke"},"created_timestamp":$(date +%s),"version":1}
EOF
)
  local sig
  sig=$(hmac_sha256 "$body" "$secret")

  echo "== POST ${WEBHOOK_SUBDOMAIN}.${TENANT_DOMAIN}/webhooks/shopee/$ACCOUNT =="
  echo "Body: $body"
  echo "HMAC: $sig"
  echo

  local resp
  resp=$(curl -s -w "\nHTTP_CODE:%{http_code}" \
      -X POST "https://${WEBHOOK_SUBDOMAIN}.${TENANT_DOMAIN}/webhooks/shopee/$ACCOUNT" \
      -H "Authorization: HMAC-SHA256 Signature=$sig" \
      -H "Content-Type: application/json" \
      --data-raw "$body")
  local code=${resp##*HTTP_CODE:}
  local json=${resp%HTTP_CODE:*}
  echo "HTTP $code"
  echo "$json" | jq . 2>/dev/null || echo "$json"
}

cmd_outbound() {
  [[ -n $ACCOUNT ]] || { echo "--account required"; usage; }
  echo "== Dispatching SendChannelMessageJob for account $ACCOUNT =="
  php artisan tinker --execute='
    $accountId = "'"$ACCOUNT"'";
    $account = App\Modules\Channels\Models\ChannelAccount::query()
        ->withoutWorkspaceScope()->whereKey($accountId)->firstOrFail();
    $ws = $account->workspace_id;
    $outbox = App\Modules\Channels\Models\OutboxMessage::create([
        "workspace_id" => $ws,
        "channel_account_id" => $accountId,
        "conversation_id" => "00000000-0000-0000-0000-000000000001",
        "message_id" => "00000000-0000-0000-0000-000000000001",
        "direction" => "OUTBOUND",
        "message_type" => "TEXT",
        "body_text" => "'"$TEXT"'",
        "status" => "QUEUED",
        "recipient_external_id" => "SANDBOX-CONV-1",
        "payload" => ["conversation_id" => "SANDBOX-CONV-1", "text" => "'"$TEXT"'"],
    ]);
    App\Modules\Channels\Jobs\SendChannelMessageJob::dispatchSync($outbox->id);
    $outbox->refresh();
    echo "STATUS=".$outbox->status."\n";
    if ($outbox->last_error_code) {
        echo "ERR=".$outbox->last_error_code.": ".$outbox->last_error_message."\n";
    }
    if ($outbox->provider_response) {
        echo "RESP=".json_encode($outbox->provider_response)."\n";
    }
  '
}

cmd_config() {
  echo "== Config snapshot =="
  echo "TENANT_DOMAIN        = $TENANT_DOMAIN"
  echo "WEBHOOK_SUBDOMAIN    = $WEBHOOK_SUBDOMAIN"
  echo "APP_ENV              = ${APP_ENV:-}"
  echo "SHOPEE_API_BASE      = ${SHOPEE_API_BASE:-}"
  echo "SHOPEE_REGION        = ${SHOPEE_REGION:-}"
  echo "Webhook ingress URL  = https://${WEBHOOK_SUBDOMAIN}.${TENANT_DOMAIN}/up"
  echo "Sample webhook URL   = https://${WEBHOOK_SUBDOMAIN}.${TENANT_DOMAIN}/webhooks/shopee/{uuid}"
}

case "$CMD" in
  health) cmd_health;;
  inbound) cmd_inbound;;
  outbound) cmd_outbound;;
  config) cmd_config;;
  -h|--help) usage;;
  *) echo "Unknown command: $CMD" >&2; usage;;
esac