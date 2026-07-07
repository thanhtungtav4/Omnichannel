# Deploy CRM to an Ubuntu VPS (nginx + PHP-FPM + PostgreSQL + Redis + Node sidecar)

Production runbook. Assumes a fresh Ubuntu 22.04/24.04 VPS, a domain pointed at
its IP, and root/sudo access. Replace `<YOUR_DOMAIN>` and `crm.example.com`
throughout.

Stack on the box:
- nginx (reverse proxy + TLS)
- PHP 8.3 FPM (Laravel app)
- PostgreSQL 16
- Redis (cache / queue / sessions)
- Node 20 (Zalo personal sidecar)
- supervisor (queue worker + sidecar as services)

---

## 0. DNS first

Point an A record `crm.example.com -> <VPS_IP>` before requesting TLS.
Verify: `dig +short crm.example.com` returns your VPS IP.

---

## 1. System packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx postgresql redis-server supervisor unzip git curl \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-redis php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl

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
# Edit .env: set APP_URL=https://crm.example.com, DB_PASSWORD, REDIS_PASSWORD,
# ZALO_SIDECAR_TOKEN=your_secure_shared_token,
# ZALO_SIDECAR_URL=http://127.0.0.1:4501,
# SESSION_DOMAIN=crm.example.com
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

## 6. nginx (HTTP first, TLS added by certbot)

`/etc/nginx/sites-available/crm`:
```nginx
server {
    listen 80;
    server_name crm.example.com;
    root /var/www/crm/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }

    client_max_body_size 25M;  # allow media uploads
}
```

```bash
sudo ln -s /etc/nginx/sites-available/crm /etc/nginx/sites-enabled/crm
sudo nginx -t && sudo systemctl reload nginx
```

## 7. TLS with Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d crm.example.com --redirect --agree-tos -m you@example.com -n
# certbot edits the nginx server block to add listen 443 ssl + redirect.
# Auto-renew is installed as a systemd timer; test it:
sudo certbot renew --dry-run
```

After this, `https://crm.example.com` is live. Webhooks now have a real HTTPS
URL — no tunnel needed.

## 8. Queue worker (supervisor)

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

## 9. Zalo personal sidecar (supervisor)

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
Environment=CRM_WEBHOOK_BASE="http://127.0.0.1"
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
   echo "127.0.0.1 qrf.vn admin.qrf.vn nhakhoa.qrf.vn" | sudo tee -a /etc/hosts
   ```


#### Option B: Running under Supervisor

`/etc/supervisor/conf.d/crm-sidecar.conf`:
```ini
[program:crm-sidecar]
command=node /var/www/crm/sidecar/server.js
directory=/var/www/crm/sidecar
user=www-data
environment=PORT="4501",SIDECAR_TOKEN="YOUR_SHARED_ZALO_SIDECAR_TOKEN",CRM_WEBHOOK_BASE="http://127.0.0.1",CRM_WEBHOOK_SECRET="YOUR_SHARED_ZALO_SIDECAR_TOKEN",ZALO_STUB="0"
autostart=true
autorestart=true
stdout_logfile=/var/log/crm-sidecar.log
```

Load the supervisor services:
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start crm-sidecar
```

*Note: Always keep the Zalo sidecar bound to localhost (port 4501) and never expose it to the internet.*

## 10. Scheduler (token refresh, cron jobs)

```bash
sudo crontab -u www-data -e
# add:
* * * * * cd /var/www/crm && php artisan schedule:run >> /dev/null 2>&1
```

## 11. Connect channels

1. Log in at `https://crm.example.com` (create the first user via /setup or seeder).
2. Admin -> Channels -> Add channel account, enter tokens.
3. Open Setup on the account:
   - Telegram: click Register webhook (URL is now real HTTPS).
   - Facebook: paste the callback URL + verify token into the FB app dashboard.
   - Zalo OA: paste the webhook URL into the Zalo OA dashboard.
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

## Pre-launch checks (spec 09 + shipping skill)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` generated, real DB/Redis passwords set, no secrets in git
- [ ] `php artisan migrate --force` applied
- [ ] TLS valid (`https://crm.example.com` padlock), auto-renew dry-run passes
- [ ] Queue worker + sidecar running under supervisor (`supervisorctl status`)
- [ ] Health: `curl -I https://crm.example.com` returns 200/302
- [ ] Sidecar :4501 NOT reachable from the internet
- [ ] A test webhook creates a message (Setup dialog shows "receiving")
