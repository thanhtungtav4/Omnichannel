# CRM Contact Center — Tài liệu triển khai & vận hành

Tài liệu tổng cho CRM đa kênh (Omnichannel Inbox). Chi tiết deploy VPS ở
`docs/DEPLOY_VPS.md`. Đặc tả kỹ thuật ở `specs/` (đặc biệt
`specs/10_OMNICHANNEL_SUPPORT_PLAN.md` — nhật ký tiến độ ở cuối file).

---

## 1. CRM này là gì

CRM contact-center kiểu Bitrix24: mọi tin nhắn khách từ Zalo/Telegram/Facebook
trở thành 1 hội thoại có thể gán cho nhân viên, liên kết với contact/lead. Lộ
trình: `lead → khách hàng → (sau này) module bệnh nhân nha khoa`.

**Kênh hỗ trợ:** Zalo cá nhân (zca-js), Zalo OA, Telegram, Facebook Messenger.
Tất cả chảy vào 1 inbox chung qua 1 adapter contract — thêm kênh mới = 1 class.

---

## 2. Kiến trúc

```
Khách nhắn (Zalo/Telegram/FB)
   │
   ├─ Telegram/FB/Zalo-OA ──webhook──┐
   │                                  ▼
   └─ Zalo cá nhân ──> [Node sidecar] ──push webhook──> Laravel
                        (zca-js, QR login,              │
                         session, backfill)             ▼
                                             InboundMessageIngestor
                                             (dedup + contact + lead +
                                              conversation + message +
                                              auto-assign)
                                                        │
                                        PostgreSQL ◄────┤────► Redis (rate limit)
                                                        │
                                             Inbox UI (React/Inertia/shadcn)
                                             ← poll 3s → agent reply → outbox
                                                        │
                                        SendChannelMessageJob (queue) ──> provider
```

**Thành phần:**
- **Laravel 12 + Inertia/React + shadcn** — app chính, admin cockpit
- **PostgreSQL** — DB (68 FK, 30+ CHECK constraint, enum enforced ở DB)
- **Redis** — anti-block rate limiter cho Zalo cá nhân
- **Node sidecar** (`sidecar/`) — cầu nối zca-js cho Zalo cá nhân (process riêng)
- **Queue worker** — gửi tin outbound (reply)
- **Cloudflare tunnel / domain** — URL public cho webhook

**Module** (`app/Modules/`): `Channels` (adapter, webhook, outbox), `Inbox`
(conversation, message), `Crm` (contact, lead), `Routing` (assignment,
presence), `Admin` (cockpit), `Platform` (workspace, entity_links).

---

## 3. Chạy môi trường dev

Yêu cầu: PHP 8.3, PostgreSQL 16, Redis, Node 20, Composer.

```bash
# lần đầu
composer install
npm install
cp .env.example .env            # sửa DB/Redis/ZALO_SIDECAR_TOKEN
php artisan key:generate
php artisan migrate --seed        # tạo schema + user demo
npm run build

# chạy mọi tiến trình bằng 1 lệnh (Laravel + queue + sidecar)
./dev.sh
# thêm tunnel công khai:
TUNNEL=1 ./dev.sh
```

`dev.sh` chạy: `php artisan serve :8001` + `queue:work` + Node sidecar
(`ZALO_STUB=0`) + (tuỳ chọn) `cloudflared`. Ctrl+C dừng hết.

**3 tiến trình BẮT BUỘC** (nếu chạy tay, không dùng dev.sh):
1. `php artisan serve --port=8001` — app
2. `php artisan queue:work` — **thiếu cái này reply bị kẹt**
3. `sidecar` real mode — Zalo cá nhân

**User demo:** `owner@example.com` / `password` (roles: owner, admin,
support_lead, support_agent, sales).

---

## 4. URL công khai cho webhook

Webhook (Telegram/FB/Zalo) cần **URL public HTTPS** — provider ở internet phải
gọi tới được. `localhost` KHÔNG đủ (kể cả có HTTPS tự ký).

**Dev — Cloudflare tunnel (miễn phí, không cần VPS):**
```bash
cloudflared tunnel --config /dev/null --url http://127.0.0.1:8001
# hoặc named tunnel đã cấu hình:
cloudflared tunnel run crm-local     # -> https://crm.nttung.dev
```
Set `APP_URL=https://<domain>` trong `.env` để webhook URL sinh đúng.

> Máy chạy tunnel phải bật 24/7 — tắt máy = CRM tắt. Muốn chạy liên tục dùng VPS
> (`docs/DEPLOY_VPS.md`).

**Lưu ý session:** `SESSION_SECURE_COOKIE=true` → chỉ đăng nhập được qua HTTPS
(domain/tunnel), **không login được qua localhost HTTP**.

---

## 5. Kết nối từng kênh

Vào **Admin → Channels → Add channel account**, nhập token, rồi bấm **Setup**
để xem checklist + webhook URL.

### Telegram
1. Tạo bot qua **@BotFather**, lấy token → nhập vào CRM.
2. Bấm **Setup → Register webhook** (CRM tự gọi setWebhook).
3. Nhắn bot để test.
4. **Group Telegram:** BotFather → `/mybots` → bot → **Bot Settings → Group
   Privacy → Turn off**, RỒI **xoá bot khỏi group và thêm lại** (privacy chỉ
   đổi khi bot re-join). Kiểm tra `can_read_all_group_messages=true` qua
   `getMe`. Không tắt → bot không thấy tin thường trong group.

### Zalo cá nhân (zca-js sidecar)
1. Add channel `ZALO_PERSONAL`. `webhook_secret` tự đặt = `ZALO_SIDECAR_TOKEN`.
2. Chạy sidecar real mode (dev.sh đã lo).
3. **Setup → Đăng nhập bằng QR** → quét bằng app Zalo trên điện thoại.
4. Nick chuyển ACTIVE → nhận tin. Session lưu ở `sidecar/sessions/` (reconnect
   khi restart).
5. ⚠️ zca-js là thư viện không chính thức — có thể bị khoá nick. Rate limiter
   (300 tin/ngày/nick) + adapter thay được bằng Zalo OA nếu cần.

### Zalo OA
1. Add channel `ZALO_OA`, nhập app_id/app_secret/access_token/refresh_token.
2. Dán webhook URL (từ Setup) vào Zalo OA dashboard.
3. Token tự refresh qua `RefreshZaloAccessTokenJob`.

### Facebook Messenger
1. Add channel `FACEBOOK`, nhập app_secret + page_access_token, đặt verify token.
2. Dán callback URL + verify token vào Facebook app (Webhooks → Messenger).
3. Subscribe page.

---

## 6. Nhiều nhân viên dùng cùng lúc (đã xử lý)

- **Quyền reply:** agent chỉ trả lời hội thoại của mình hoặc chưa gán;
  owner/admin/support_lead trả lời bất kỳ. Áp cho reply/close/transfer.
- **Chống race:** close/transfer chạy trong transaction + lockForUpdate.
- **Presence:** inbox gửi heartbeat mỗi 20s → agent ONLINE; đóng tab → OFFLINE;
  cron quét agent quá 90s không heartbeat → OFFLINE.
- **Auto-assign:** khách mới chia đều agent đang ONLINE (round-robin qua
  routing queue). Cấu hình queue + thành viên trong DB (`routing_queues`,
  `routing_queue_members`).

Cron cần chạy (`php artisan schedule:run` mỗi phút — production: crontab).

---

## 7. Chống miss tin (Zalo cá nhân)

zca-js websocket hay rớt. 3 lớp bảo vệ:
1. **Health probe** mỗi 60s (`fetchAccountInfo`) → phát hiện WS chết → reconnect.
2. **Auto-reconnect** + circuit breaker (>5 rớt/5 phút → cần QR lại).
3. **Backfill** khi reconnect — kéo lại tin gần đây; DB unique index chống trùng.
4. Nút **Sync lịch sử** thủ công trong Setup dialog.

---

## 8. Vận hành — kiểm tra nhanh khi có sự cố

```bash
# tiến trình còn chạy?
lsof -ti:8001; lsof -ti:4501; pgrep -f queue:work; pgrep -f cloudflared

# nick Zalo còn kết nối?
curl -s http://127.0.0.1:4501/health

# job kẹt / lỗi?
php artisan tinker --execute="echo DB::table('jobs')->count().' '.DB::table('failed_jobs')->count();"

# outbox reply lỗi?
# (Admin → Inbox → metric "Gửi lỗi", hoặc query outbox_messages status=FAILED)
```

**Triệu chứng thường gặp:**

| Triệu chứng | Nguyên nhân | Xử lý |
|---|---|---|
| Reply kẹt, không gửi | queue worker không chạy | chạy `queue:work` (hoặc `./dev.sh`) |
| Reply lỗi 419 | session cookie sai domain | login lại qua HTTPS domain |
| Zalo không nhận tin mới | WS rớt / listener chết | health probe tự reconnect ≤60s; hoặc restart sidecar + login QR |
| Tin bị miss sau khi rớt | chưa backfill | tự backfill khi reconnect, hoặc nút Sync lịch sử |
| Telegram group không nhận | bot privacy mode ON | tắt privacy ở BotFather + re-add bot |
| Telegram "chat not found" | recipient sai (update rác) | đã fix: bỏ qua my_chat_member; dùng chat.id |
| Tên khách = tên nick | hội thoại bắt đầu bằng tin self | tự sửa khi khách nhắn thật |

---

## 9. Deploy production

Xem `docs/DEPLOY_VPS.md` — hướng dẫn đầy đủ Ubuntu VPS (nginx + PHP-FPM +
PostgreSQL + Redis + Node sidecar + supervisor + Let's Encrypt).

Điểm khác dev:
- `.env.production.example` → `.env`; `APP_ENV=production`, `APP_DEBUG=false`.
- Redis cho session/cache/queue (thay database driver).
- Queue worker + sidecar chạy dưới **supervisor** (không phải dev.sh).
- Cron `schedule:run` mỗi phút (presence sweep, token refresh).
- TLS thật (Let's Encrypt) hoặc Cloudflare tunnel.

---

## 10. Còn tồn / bổ sung sau

- Realtime Reverb (thay poll 3s — giảm tải DB khi nhiều agent).
- Mirror media Zalo lên S3/MinIO (nếu URL CDN hết hạn).
- Backfill group (hiện chỉ DM).
- UI: badge "agent đang xử lý", màn quản lý routing queue, multi-nick picker,
  emoji, tìm tin trong 1 hội thoại.
- Module bệnh nhân nha khoa (cắm vào `contacts` qua `entity_links`).

---

## 11. Bản đồ code nhanh

| Cần sửa | File |
|---|---|
| Thêm/sửa kênh | `app/Modules/Channels/Adapters/*Adapter.php` + registry |
| Luồng nhận tin | `app/Modules/Channels/Services/InboundMessageIngestor.php` |
| Gửi tin (reply) | `app/Modules/Channels/Jobs/SendChannelMessageJob.php` |
| Zalo sidecar | `sidecar/server.js`, `sidecar/zalo-pool.js` |
| Inbox UI | `resources/js/pages/admin/inbox.tsx` + `inbox/{lib,MessageBubble,QueueParts,ThreadPanel}` |
| Assignment/presence | `app/Modules/Routing/` |
| DB schema | `database/migrations/2026_07_04_000001_create_modular_crm_tables.php` (+ additive migrations) |
| Đặc tả | `specs/` (10 = kế hoạch omnichannel, có nhật ký tiến độ) |
