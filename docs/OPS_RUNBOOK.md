# Ops Runbook — CRM (admin.qrf.vn)

Production VPS: `180.93.115.225` · app root `/var/www/crm` · PHP 8.4 · Node 20 · PostgreSQL · Redis.

Background work is split across three long-running processes. If any one is
down, part of the product silently stops working. Check all three first.

| Process | What it does | Supervised by | Down = |
| --- | --- | --- | --- |
| `php-fpm` (php8.4-fpm) | Serves the app | systemd | Site 502 |
| Queue worker (`queue:work redis`) | Outbound message delivery, jobs | supervisor `crm-queue` | Replies stay QUEUED, never sent |
| Zalo sidecar (`node server.js`) | Zalo Personal send/receive (:4501) | systemd `zalo-sidecar.service` | Zalo replies fail; no inbound Zalo |

## Queue worker

Outbound delivery runs on the **redis** queue (`QUEUE_CONNECTION=redis`) via
`SendChannelMessageJob` (`ShouldQueue`). No worker → agent replies are created
as `QUEUED` in `outbox_messages` but never dispatched to the provider. This is
the classic "gửi tin không hoạt động".

- Config: `/etc/supervisor/conf.d/crm-queue.conf` (source: `deploy/supervisor/crm-queue.conf`).
- Status: `sudo supervisorctl status crm-queue:*`
- Restart / reload code: `sudo supervisorctl restart crm-queue:*`
- Deploy also runs `php artisan queue:restart` to gracefully reload workers.
- Log: `/var/www/crm/storage/logs/queue-worker.log`

Install (one-time on a fresh box):
```
sudo apt-get update --allow-releaseinfo-change && sudo apt-get install -y supervisor
sudo cp /var/www/crm/deploy/supervisor/crm-queue.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start crm-queue:*
```

## Zalo sidecar

Zalo Personal send/receive goes through a Node service (zca-js) on `:4501`.

- Service: `systemctl status zalo-sidecar.service` (source unit lives on the box).
- Restart: `sudo systemctl restart zalo-sidecar.service`
- Log: `sudo journalctl -u zalo-sidecar.service -f`
- Deploy installs sidecar deps + restarts it (see `.github/workflows/deploy.yml`).
- `NOT_AUTHENTICATED` / code 3000 in the log = that Zalo session needs a fresh
  QR login from the Channels screen; it is **not** a deploy bug.

## Troubleshooting: "reply not sending"

1. Worker up? `sudo supervisorctl status crm-queue:*` → must be RUNNING.
2. Stuck outbox? Count by status:
   ```
   sudo -u www-data HOME=/tmp php8.4 artisan tinker --execute="foreach(DB::table('outbox_messages')->select('status',DB::raw('count(*) c'))->groupBy('status')->get() as \$r){echo \$r->status.': '.\$r->c.PHP_EOL;}"
   ```
   Many `QUEUED` with the worker up → check the worker log for errors.
3. Provider = ZALO_PERSONAL? Sidecar must be `active` and the session logged in.
4. Redis reachable? `redis-cli ping` → `PONG`.
5. Failed jobs: `sudo -u www-data HOME=/tmp php8.4 artisan queue:failed`.

Manually drain one job to test end-to-end:
```
sudo -u www-data HOME=/tmp php8.4 artisan queue:work redis --once
```

> Note: run artisan as `www-data` with `HOME=/tmp` on this box to avoid the
> psysh "cannot write to /var/www/.config" warning.

## Deploy (CI/CD)

Push to `main` triggers three GitHub Actions workflows:

- **tests.yml** — build assets, `composer types:check` (tsc), `php artisan test`. Gate on green before relying on a deploy.
- **lint.yml** — Pint + Prettier + ESLint.
- **deploy.yml** — SSH to the VPS: `git reset --hard origin/main` → `composer install --no-dev` → `migrate --force` → `npm ci && npm run build` → reload php-fpm → clear caches → `queue:restart` → sidecar deps + `restart zalo-sidecar.service`.

After a deploy, if the UI still shows old assets: hard-refresh the browser
(Vite content-hashes asset filenames per build).

## Access

VPS SSH creds currently live in `.vscode/sftp.json` (root + password). This is
a secret in the repo tree — it should be git-ignored and the password rotated.
