# Deploy CRM to an Ubuntu VPS (nginx + PHP-FPM + PostgreSQL + Redis + Node sidecar)

Production runbook for a multi-subdomain SaaS:

- `qrf.vn` — apex, redirects to `/admin`
- `*.qrf.vn` — tenant subdomains (`acme.qrf.vn`, `beta.qrf.vn`, ...)
- `admin.qrf.vn` — out-of-tenant platform admin console
- `webhook.qrf.vn` — provider webhook ingress (Telegram / Zalo / Facebook)

Each surface has its own nginx vhost (or shares `crm-main.conf` via SNI) and
its own operational profile. The Laravel app serves them all from one codebase
and routes by Host header. See `docs/OPS_WEBHOOKS.md` for the webhook runbook
and `specs/05_CONNECTORS_ZALO_TELEGRAM.md` for the connector contracts.

Replace `<DOMAIN>` with your root domain (default: `qrf.vn`) and `<VPS_IP>`
with the VPS IP throughout.

Stack on the box:
- nginx (reverse proxy + TLS termination)
- PHP 8.4 FPM (matches the GitHub Actions deploy workflow; 8.3 also works
  but pick one and stick with it — `php8.3-fpm` vs `php8.4-fpm` are not
  interchangeable)
- PostgreSQL 16
- Redis (cache / queue / sessions)
- Node 20 (Zalo personal sidecar)
- supervisor (queue worker + sidecar as services)

---

## 0. DNS first

Point A records at `<VPS_IP>`:

| Host                | Type | Value      | Notes                                  |
|---------------------|------|------------|----------------------------------------|
| `<DOMAIN>`          | A    | `<VPS_IP>` | Apex; redirects to `/admin`            |
| `*.<DOMAIN>`        | A    | `<VPS_IP>` | Wildcard; covers every tenant slug     |
| `admin.<DOMAIN>`    | A    | `<VPS_IP>` | Platform admin console                 |
| `webhook.<DOMAIN>`  | A    | `<VPS_IP>` | Provider webhook ingress               |

Verify:

```bash
dig +short <DOMAIN>           # <VPS_IP>
dig +short acme.<DOMAIN>      # <VPS_IP> (test wildcard)
dig +short webhook.<DOMAIN>   # <VPS_IP>
```

Wait for TTL to expire if you're switching providers.

---

## 1. System packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx postgresql redis-server supervisor unzip git curl \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-redis php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 20 (for the sidecar + building assets)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

## 2. PostgreSQL

```bash
sudo -u postgres psql <<'SQL'
CREATE DATABASE crm;
CREATE USER crm WITH ENCRYPTED PASSWORD 'CHANGE_ME_STRONG';
GRANT ALL PRIVILEGES ON DATABASE crm TO crm;
ALTER DATABASE crm OWNER TO crm;
SQL
```

## 3. Redis (set a password)

```bash
sudo sed -i 's/^# requirepass .*/requirepass CHANGE_ME_REDIS/' /etc/redis/redis.conf
sudo systemctl enable --now redis-server
sudo systemctl restart redis-server
```

## 4. Get the code

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <YOUR_REPO_URL> crm && cd crm
sudo chown -R www-data:www-data /var/www/crm
```

## 5. App configuration

```bash
cd /var/www/crm
cp .env.production.example .env
# Edit .env: set
#   APP_URL=https://<DOMAIN>
#   APP_TENANT_DOMAIN=<DOMAIN>
#   APP_ADMIN_SUBDOMAIN=admin
#   APP_WEBHOOK_SUBDOMAIN=webhook
#   DB_PASSWORD=<STRONG_DB_PASSWORD>
#   REDIS_PASSWORD=<STRONG_REDIS_PASSWORD_OR_null>
#   SESSION_DOMAIN=<DOMAIN>             # cookie scope
#   ZALO_SIDECAR_TOKEN=<STRONG_SIDECAR_TOKEN>
#   ZALO_SIDECAR_URL=http://127.0.0.1:4501
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan migrate --force
sudo -u www-data npm ci && sudo -u www-data npm run build

# Cache config/routes/views for production speed
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

Storage perms:
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

## 6. Diffie-Hellman params (one-time)

```bash
sudo openssl dhparam -out /etc/nginx/dhparam.pem 2048
```

## 7. TLS with Let's Encrypt (wildcard)

We need one wildcard cert that covers `*.qrf.vn` (every tenant slug), and we
need `qrf.vn`, `admin.qrf.vn`, `webhook.qrf.vn` to also validate against it.
A `*.qrf.vn` wildcard cert covers all of them — no need to issue separate
certs per host.

DNS-01 challenge is required for wildcards. The first issuance is interactive:

```bash
sudo apt install -y certbot python3-certbot-dns-cloudflare   # or your DNS plugin
# Configure ~/.secrets/certbot/cloudflare.ini with the API token:
#   dns_cloudflare_api_token = <CLOUDFLARE_API_TOKEN>
sudo chmod 600 ~/.secrets/certbot/cloudflare.ini

sudo certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials ~/.secrets/certbot/cloudflare.ini \
  -d qrf.vn -d "*.qrf.vn" \
  --agree-tos -m you@example.com -n

sudo certbot renew --dry-run
```

Renewals run via the certbot systemd timer and re-issue the same wildcard cert
— no extra config needed when new tenant slugs come online, and `webhook.qrf.vn`
is automatically covered as long as it falls under `*.qrf.vn`.

Cert paths you'll reference in nginx:

```
/etc/letsencrypt/live/qrf.vn/fullchain.pem
/etc/letsencrypt/live/qrf.vn/privkey.pem
```

The deploy/nginx templates reference `$LE_ROOT` for these. To make that
resolve, add an `env` directive in `/etc/nginx/nginx.conf` inside the
top-level `http {}` block:

```nginx
http {
    env LE_ROOT=/etc/letsencrypt/live/qrf.vn;
    # ... existing config ...
}
```

Then reload systemd-resolved nginx (env vars are picked up on full restart,
not `nginx -s reload`):

```bash
sudo systemctl restart nginx
```

If you skip this step, every vhost fails to start with
`SSL_CTX_use_PrivateKey_file("/$LE_ROOT/privkey.pem") failed`. Check
`sudo nginx -t` and look for that error if you see it.

> Alternative: skip `$LE_ROOT` entirely and replace the two `ssl_certificate`
> lines in each vhost with the absolute path. Simpler, just slightly more
> repetition if you ever move certs.

## 8. nginx — two vhosts

The repo ships reference configs in `deploy/nginx/`:

- `crm-main.conf` — apex + all `*.qrf.vn` (tenant subdomains + admin.qrf.vn
  by SNI fallback)
- `crm-webhook.conf` — `webhook.qrf.vn`, method-restricted, isolated logs

```bash
sudo cp deploy/nginx/crm-main.conf    /etc/nginx/sites-available/crm-main
sudo cp deploy/nginx/crm-webhook.conf /etc/nginx/sites-available/crm-webhook

sudo ln -s /etc/nginx/sites-available/crm-main    /etc/nginx/sites-enabled/crm-main
sudo ln -s /etc/nginx/sites-available/crm-webhook /etc/nginx/sites-enabled/crm-webhook

# Remove the default site if it's still enabled.
sudo rm -f /etc/nginx/sites-enabled/default

sudo nginx -t && sudo systemctl reload nginx
```

What each vhost enforces:

| Host family        | Vhost            | Key constraints                                  |
|--------------------|------------------|--------------------------------------------------|
| `qrf.vn`           | `crm-main`       | Full Laravel app                                 |
| `*.qrf.vn` (tenant)| `crm-main`       | Full Laravel app; tenant pinned by middleware    |
| `admin.qrf.vn`     | `crm-main`       | Same as tenants; tighter rate limits in Laravel  |
| `webhook.qrf.vn`   | `crm-webhook`    | POST/GET only, 1MB body cap, isolated log file   |

Defense in depth: Laravel `routes/web.php` also binds `/webhooks/*` to the
webhook host when `APP_WEBHOOK_SUBDOMAIN` is set, so even if a misconfigured
proxy forwards a webhook POST to a tenant subdomain, Laravel 404s it. See
`tests/Feature/Modules/WebhookHostBindingTest.php`.

## 9. Queue worker (supervisor)

`/etc/supervisor/conf.d/crm-worker.conf`:
```ini
[program:crm-worker]
command=php /var/www/crm/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
directory=/var/www/crm
user=www-data
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stdout_logfile=/var/log/crm-worker.log
stopwaitsecs=3600
```

## 10. Zalo personal sidecar (supervisor)

Only needed if you use ZALO_PERSONAL channels.

#### Option A: Running as a systemd service (Recommended, native on Ubuntu)

1. Install Node.js dependencies:
```bash
cd /var/www/crm/sidecar
sudo npm install --production
```

2. Create the systemd service file at `/etc/systemd/system/zalo-sidecar.service`:
```ini
[Unit]
Description=CRM Zalo Personal Sidecar Service
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/var/www/crm/sidecar
Environment=PORT=4501
Environment=SIDECAR_TOKEN="YOUR_SHARED_ZALO_SIDECAR_TOKEN"
Environment=CRM_WEBHOOK_BASE="https://webhook.qrf.vn"
Environment=CRM_WEBHOOK_SECRET="YOUR_SHARED_ZALO_SIDECAR_TOKEN"
Environment=ZALO_STUB=0
ExecStart=/usr/bin/node server.js
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

3. Enable and start the service:
```bash
sudo systemctl daemon-reload
sudo systemctl enable zalo-sidecar
sudo systemctl start zalo-sidecar
```

4. Verify the service is running and healthy:
```bash
# Check service status
sudo systemctl status zalo-sidecar

# Query local health check endpoint (should return ok: true)
curl http://127.0.0.1:4501/health
```

5. Integrate with the main Laravel application:
Make sure the following variables are configured in `/var/www/crm/.env` to link the main app to the Zalo sidecar:
```ini
ZALO_SIDECAR_URL=http://127.0.0.1:4501
ZALO_SIDECAR_TOKEN="YOUR_SHARED_ZALO_SIDECAR_TOKEN" # Must match SIDECAR_TOKEN in the service file
```
Clear the config cache afterwards so Laravel picks up the settings:
```bash
cd /var/www/crm
sudo -u www-data php8.4 artisan config:clear
```

6. **Bypass Cloudflare WAF / Loopback Configuration**:
   If your domains (e.g., `qrf.vn`, `*.qrf.vn`) are proxied behind Cloudflare, the sidecar's webhook POST requests to `https://qrf.vn/webhooks/zalo/...` will be blocked by Cloudflare's Bot Management WAF (returning a 403 Forbidden challenge page).
   To bypass Cloudflare and route webhook traffic locally on the VPS, map your domains directly to `127.0.0.1` in the VPS's `/etc/hosts` file:
   ```bash
   echo "127.0.0.1 qrf.vn admin.qrf.vn nhakhoa.qrf.vn webhook.qrf.vn" | sudo tee -a /etc/hosts
   ```


#### Option B: Running under Supervisor

`/etc/supervisor/conf.d/crm-sidecar.conf`:
```ini
[program:crm-sidecar]
command=node /var/www/crm/sidecar/server.js
directory=/var/www/crm/sidecar
user=www-data
environment=PORT="4501",SIDECAR_TOKEN="YOUR_SHARED_ZALO_SIDECAR_TOKEN",CRM_WEBHOOK_BASE="https://webhook.qrf.vn",CRM_WEBHOOK_SECRET="YOUR_SHARED_ZALO_SIDECAR_TOKEN",ZALO_STUB="0"
autostart=true
autorestart=true
stdout_logfile=/var/log/crm-sidecar.log
```

Load the supervisor services:
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start crm-sidecar
```

*Note: Always keep the Zalo sidecar bound to localhost (port 4501) and never expose it to the internet.*

## 11. Scheduler (token refresh, cron jobs)

```bash
sudo crontab -u www-data -e
# add:
* * * * * cd /var/www/crm && php artisan schedule:run >> /dev/null 2>&1
```

## 12. Connect channels

1. Log in at `https://admin.qrf.vn` (platform admin) and create your first
   workspace, or sign in to `https://<your-slug>.qrf.vn/admin` if the workspace
   already exists.
2. Workspace admin -> Channels -> Add channel account, enter tokens.
3. Open Setup on the account:
   - Telegram: click "Register webhook". The URL sent to Telegram is
     `https://webhook.qrf.vn/webhooks/telegram/{channel_account_uuid}`. The
     `secret_token` header value is generated automatically; store it in
     `webhook_secret` and Telegram will send it back as
     `X-Telegram-Bot-Api-Secret-Token`.
   - Facebook: paste the callback URL
     `https://webhook.qrf.vn/webhooks/facebook/{channel_account_uuid}` + the
     verify token into the FB app dashboard.
   - Zalo OA: paste the URL
     `https://webhook.qrf.vn/webhooks/zalo/{channel_account_uuid}` into the
     Zalo OA dashboard.
   - Zalo personal: the sidecar logs in by QR.
4. Send a test message -> the row shows "receiving".

---

## Deploy updates later

```bash
cd /var/www/crm
sudo -u www-data git pull
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache && sudo -u www-data php artisan view:cache
sudo supervisorctl restart all
# If using systemd for Zalo sidecar, restart it:
sudo systemctl restart zalo-sidecar
```

If you updated `deploy/nginx/*.conf`:

```bash
sudo cp deploy/nginx/crm-main.conf    /etc/nginx/sites-available/crm-main
sudo cp deploy/nginx/crm-webhook.conf /etc/nginx/sites-available/crm-webhook
sudo nginx -t && sudo systemctl reload nginx
```

## Rollback

```bash
cd /var/www/crm
sudo -u www-data git checkout <previous-tag-or-commit>
# if a migration must be undone: php artisan migrate:rollback --step=1
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm run build
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache && sudo -u www-data php artisan view:cache
sudo supervisorctl restart all
```

If you also rolled back the webhook host binding (re-introduced a route
registry change), reload nginx and clear route cache:

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo -u www-data php artisan route:clear
```

## Pre-launch checks (spec 09 + shipping skill)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` generated, real DB/Redis passwords set, no secrets in git
- [ ] `APP_TENANT_DOMAIN`, `APP_ADMIN_SUBDOMAIN`, `APP_WEBHOOK_SUBDOMAIN` set
- [ ] `php artisan migrate --force` applied
- [ ] Wildcard TLS valid for `*.qrf.vn`, auto-renew dry-run passes
- [ ] Queue worker + sidecar running under supervisor (`supervisorctl status`)
- [ ] All nginx vhosts reload clean (`sudo nginx -t` returns ok)
- [ ] Health: `curl -I https://qrf.vn` returns 301/200 (apex redirects)
- [ ] Health: `curl -I https://acme.qrf.vn` returns 200/302 for an active tenant
- [ ] Health: `curl -I https://webhook.qrf.vn/up` returns 200 (Laravel health probe)
- [ ] Health: `curl -X POST https://webhook.qrf.vn/webhooks/telegram/<bogus>` returns 405 or 404 (not 200)
- [ ] Sidecar :4501 NOT reachable from the internet
- [ ] A test webhook creates a message (Setup dialog shows "receiving")

For webhook-specific operational checks (replay, secret rotation, monitoring,
incident response), see **`docs/OPS_WEBHOOKS.md`**.