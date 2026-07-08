# Shopee Open Platform — Sandbox Setup

Sandbox lets us run the full OAuth + send + webhook flow against Shopee's
test environment **without affecting production shops** and without waiting
for partner program approval. Spec 11 § W3 G1.2 DoD #9.

## Sandbox vs Production

| Item              | Production                              | Sandbox                                |
|-------------------|-----------------------------------------|----------------------------------------|
| API base          | `https://partner.shopeemobile.com/api/v2` | `https://partner.test-shopeemobile.com/api/v2` |
| Partner portal    | https://open.shopee.vn/developer         | https://open.test.shopeemobile.com      |
| Token TTL         | 4h access / 30d refresh                  | 4h access / 30d refresh (same)          |
| Webhook delivery  | To your registered URL                  | To your registered URL (real HTTP)     |
| Test shop         | Real seller account                     | Auto-created test shop after authorize  |
| Quota             | 100 req/min/shop                        | Lower; bursts may 429 quickly          |

Cut 1 only uses the VN region. The sandbox endpoint above is the VN sandbox.

## Configuration

`config/services.php` reads the API base from `SHOPEE_API_BASE`. Override per
environment:

```bash
# .env (development)
SHOPEE_API_BASE=https://partner.test-shopeemobile.com/api/v2
SHOPEE_REGION=vn

# .env.production (default; do NOT change unless Shopee moves the endpoint)
SHOPEE_API_BASE=https://partner.shopeemobile.com/api/v2
SHOPEE_REGION=vn
```

Clear config cache after changing:

```bash
php artisan config:clear
```

## Sandbox credentials

Sandbox partner credentials are issued at https://open.test.shopeemobile.com
once your developer account is created. They're separate from production
partner credentials — never reuse them.

Per-tenant storage (encrypted) in `workspace_settings`:

```bash
php artisan tinker --execute='
  $ws = App\Modules\Platform\Models\Workspace::where("slug", "sandbox-test")->firstOrFail();
  app(App\Modules\Platform\Services\WorkspaceSettings::class)->set($ws, "shopee.partner_credentials", [
      "partner_id" => env("SHOPEE_SANDBOX_PARTNER_ID"),
      "partner_key" => env("SHOPEE_SANDBOX_PARTNER_KEY"),
  ]);
'
```

Or via the artisan command (preferred — it validates before writing):

```bash
php artisan shopee:sandbox-config --slug=sandbox-test \
    --partner-id=$SHOPEE_SANDBOX_PARTNER_ID \
    --partner-key=$SHOPEE_SANDBOX_PARTNER_KEY
```

## OAuth round-trip against sandbox

1. Open `https://acme.qrf.vn/admin/channels/shopee/connect` (or whichever
   tenant slug you're testing).
2. You should redirect to `https://partner.test.shopeemobile.com/api/v2/shop/auth_partner?...`.
3. Authorize with a sandbox test shop — Shopee will create one for you if
   you don't have one already.
4. Shopee redirects back to `/admin/channels/shopee/callback?code=...&state=...`.
5. CRM exchanges the code for an access_token. Verify in the admin UI that
   the channel account shows `ACTIVE` and `shop_id` is populated.

If Shopee returns `error=invalid_redirect_uri`:

- Check that the redirect URI in the sandbox partner dashboard matches
  EXACTLY (including scheme, host, path, query).
- Each tenant slug needs its own registered URI — bulk-register in
  pre-launch if you have many test tenants.

## Triggering webhook events in sandbox

The sandbox does **not** auto-push messages when you test in your dashboard —
you must trigger them manually via the sandbox "Send test message" UI OR
post a synthetic payload to your webhook URL via curl:

```bash
scripts/shopee_sandbox_smoke.sh inbound \
    --tenant=sandbox-test \
    --account=$CHANNEL_ACCOUNT_ID
```

This script:
1. Generates a Shopee-shaped payload
2. Computes HMAC-SHA256 over the body
3. POSTs to `https://webhook.qrf.vn/webhooks/shopee/{uuid}`
4. Prints response + idempotency key

The CR-side conversation appears in the Inbox within ~2s.

## Outbound smoke

```bash
php artisan tinker --execute='
  $ws = App\Modules\Platform\Models\Workspace::where("slug", "sandbox-test")->firstOrFail();
  $account = App\Modules\Channels\Models\ChannelAccount::query()
      ->withoutWorkspaceScope()
      ->where("workspace_id", $ws->id)
      ->where("provider", "SHOPEE")
      ->firstOrFail();

  $outbox = App\Modules\Channels\Models\OutboxMessage::create([
      "workspace_id" => $ws->id,
      "channel_account_id" => $account->id,
      "conversation_id" => "00000000-0000-0000-0000-000000000001",
      "message_id" => "00000000-0000-0000-0000-000000000001",
      "direction" => "OUTBOUND",
      "message_type" => "TEXT",
      "body_text" => "Hello from sandbox smoke test",
      "status" => "QUEUED",
      "recipient_external_id" => "SANDBOX-CONV-1",
      "payload" => ["conversation_id" => "SANDBOX-CONV-1", "text" => "Hello"],
  ]);

  App\Modules\Channels\Jobs\SendChannelMessageJob::dispatchSync($outbox->id);
  $outbox->refresh();
  echo "Status: ".$outbox->status."\n";
  if ($outbox->last_error_code) {
      echo "Error: ".$outbox->last_error_code." — ".$outbox->last_error_message."\n";
  }
  if ($outbox->provider_response) {
      echo "Response: ".json_encode($outbox->provider_response)."\n";
  }
'
```

## Token refresh test

```bash
# Force the token to look expired
php artisan tinker --execute='
  $account = App\Modules\Channels\Models\ChannelAccount::query()
      ->where("provider", "SHOPEE")->latest()->firstOrFail();
  $creds = $account->credentials;
  $creds["access_token_expires_at"] = now()->subMinutes(5)->toIso8601String();
  $account->update(["credentials" => $creds]);
'

# Send a message — the adapter should auto-refresh
# (use the outbound smoke command above; expect ok=true with new access_token in DB)
```

## Cleaning up

Disconnect a sandbox channel account via the admin UI ("Delete permanently")
or directly:

```bash
php artisan tinker --execute='
  App\Modules\Channels\Models\ChannelAccount::query()
      ->where("provider", "SHOPEE")
      ->where("name", "LIKE", "%sandbox%")
      ->delete();
'
```

The partner credentials in `workspace_settings` are reusable — don't delete
them unless you want to force a re-authorize.

## Sandbox limitations

- **No real customer traffic.** Sandbox test shops don't receive messages
  from real Shopee buyers.
- **Webhook retries are simulated.** Shopee's sandbox doesn't actually retry
  on 5xx; verify retry logic by killing PHP-FPM mid-test.
- **Image upload uses a stub CDN.** The URLs returned by `/media/upload_image`
  are not real; they're for verification only.
- **Token signing is identical to prod** but **rate limits are lower**. Stress
  tests should NOT run against sandbox — use a local mock for that.
- **Some partner-tier endpoints are stubbed.** Cut 1 doesn't use them, but if
  you explore beyond spec 11, expect 404s.

## Promoting sandbox config to production

After pilot signs off (spec 12 GA gate):

1. Apply for production partner tier at https://open.shopee.vn/developer.
2. Get real `partner_id` + `partner_key` from the prod dashboard.
3. Update `workspace_settings` per tenant with the production credentials.
4. Switch `SHOPEE_API_BASE` back to the production endpoint
   (`https://partner.shopeemobile.com/api/v2`).
5. Re-register the OAuth flow for each production tenant.

The code is identical for both environments — only credentials + base URL
change. Don't keep sandbox credentials in production.