#!/usr/bin/env bash
# Start every dev process the CRM needs, in one terminal.
# Ctrl+C stops them all. See docs/DEPLOY_VPS.md for production (supervisor).
set -euo pipefail
cd "$(dirname "$0")"

PORT="${PORT:-8001}"
SIDECAR_PORT="${SIDECAR_PORT:-4501}"
SIDECAR_TOKEN="${ZALO_SIDECAR_TOKEN:-crm-sidecar-secret}"

# Read APP_URL so the sidecar pushes back to the right base.
CRM_BASE="http://127.0.0.1:${PORT}"

pids=()
cleanup() { echo; echo "stopping..."; kill "${pids[@]}" 2>/dev/null || true; }
trap cleanup EXIT INT TERM

echo "==> Laravel  http://127.0.0.1:${PORT}"
php artisan serve --port="${PORT}" & pids+=($!)

echo "==> Queue worker (reply/outbound jobs)"
php artisan queue:work --sleep=1 --tries=3 --timeout=30 & pids+=($!)

echo "==> Zalo sidecar :${SIDECAR_PORT} (real mode)"
( cd sidecar && ZALO_STUB=0 PORT="${SIDECAR_PORT}" SIDECAR_TOKEN="${SIDECAR_TOKEN}" \
    CRM_WEBHOOK_BASE="${CRM_BASE}" CRM_WEBHOOK_SECRET="${SIDECAR_TOKEN}" node server.js ) & pids+=($!)

# Optional Cloudflare tunnel for public webhooks (set TUNNEL=1 to enable).
if [ "${TUNNEL:-0}" = "1" ]; then
  echo "==> Cloudflare tunnel -> crm.nttung.dev"
  cloudflared tunnel run crm-local & pids+=($!)
fi

echo "==> all up. Ctrl+C to stop."
wait
