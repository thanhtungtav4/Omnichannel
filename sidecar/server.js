// Zalo personal sidecar. Holds one zca-js instance per nick, exposes an HTTP
// command API to the Laravel CRM, and pushes listener events back to the CRM's
// webhook. zca-js is loaded lazily and can be stubbed for local dev.
//
// Config (env):
//   PORT                 sidecar listen port (default 4501)
//   SIDECAR_TOKEN        shared secret; every request must send X-Sidecar-Token
//   CRM_WEBHOOK_BASE     e.g. http://127.0.0.1:8001  (Laravel base URL)
//   CRM_WEBHOOK_SECRET   sent as X-Sidecar-Token to the CRM zalo webhook
//   ZALO_STUB=1          skip real zca-js; simulate QR/login/send (dev default)
//
// See specs/10 "Task 5b". ponytail: raw node:http, no express — a handful of
// routes doesn't need a framework. Swap in a router only if routes multiply.

import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';
import { ZaloPool } from './zalo-pool.js';

const PORT = Number(process.env.PORT ?? 4501);
const TOKEN = process.env.SIDECAR_TOKEN ?? '';
const CRM_BASE = process.env.CRM_WEBHOOK_BASE ?? 'http://127.0.0.1:8001';
const CRM_SECRET = process.env.CRM_WEBHOOK_SECRET ?? '';

// Push a normalized listener event to the CRM zalo webhook (idempotent there).
async function pushToCrm(channelAccountId, payload) {
  const url = `${CRM_BASE}/webhooks/zalo/${channelAccountId}`;
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-sidecar-token': CRM_SECRET,
      },
      body: JSON.stringify(payload),
    });
    return res.ok;
  } catch (err) {
    console.error(`[push] ${channelAccountId} failed:`, err.message);
    return false;
  }
}

// Persist login credentials to a local file so the nick reconnects on restart.
// ponytail: local file store, fine for one sidecar host. Move to the CRM DB
// (encrypted) if you run multiple sidecar instances.
import { mkdirSync, writeFileSync, readFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const SESSION_DIR = join(dirname(fileURLToPath(import.meta.url)), 'sessions');
mkdirSync(SESSION_DIR, { recursive: true });

async function saveCredentials(id, creds) {
  writeFileSync(join(SESSION_DIR, `${id}.json`), JSON.stringify(creds), { mode: 0o600 });
}

function loadCredentials(id) {
  const f = join(SESSION_DIR, `${id}.json`);
  return existsSync(f) ? JSON.parse(readFileSync(f, 'utf8')) : null;
}

const pool = new ZaloPool({
  stub: process.env.ZALO_STUB === '1',
  onEvent: pushToCrm,
  onCredentials: saveCredentials,
  // Reconnect a dropped nick from its stored credentials.
  onReconnect: async (id) => {
    const creds = loadCredentials(id);
    if (creds) {
      console.log(`[auto-reconnect ${id}]`);
      await pool.reconnect(id, creds);
    }
  },
});

// On boot, reconnect any nick that has saved credentials (real mode only).
if (process.env.ZALO_STUB !== '1') {
  for (const f of existsSync(SESSION_DIR) ? readdirSync(SESSION_DIR) : []) {
    if (!f.endsWith('.json')) continue;
    const id = f.replace('.json', '');
    const creds = loadCredentials(id);
    if (creds) pool.reconnect(id, creds).catch((e) => console.error(`[boot] reconnect ${id}:`, e.message));
  }
}

// --- tiny HTTP plumbing ------------------------------------------------------

function json(res, status, body) {
  const data = JSON.stringify(body);
  res.writeHead(status, { 'content-type': 'application/json' });
  res.end(data);
}

async function readJson(req) {
  const chunks = [];
  for await (const c of req) chunks.push(c);
  if (chunks.length === 0) return {};
  return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}

function authed(req) {
  return TOKEN === '' || req.headers['x-sidecar-token'] === TOKEN;
}

// route table: METHOD /path/:id -> handler
const routes = [
  ['GET', /^\/health$/, async () => ({ status: 200, body: { ok: true, accounts: pool.list() } })],
  ['POST', /^\/accounts\/([^/]+)\/login-qr$/, async (m) => ({ status: 200, body: await pool.loginQr(m[1]) })],
  ['POST', /^\/accounts\/([^/]+)\/reconnect$/, async (m, body) => ({ status: 200, body: await pool.reconnect(m[1], body) })],
  ['POST', /^\/accounts\/([^/]+)\/send$/, async (m, body) => ({ status: 200, body: await pool.send(m[1], body) })],
  ['DELETE', /^\/accounts\/([^/]+)$/, async (m) => ({ status: 200, body: await pool.disconnect(m[1]) })],
  ['GET', /^\/accounts\/([^/]+)\/status$/, async (m) => ({ status: 200, body: pool.status(m[1]) })],
  ['POST', /^\/accounts\/([^/]+)\/sync$/, async (m, body) => ({ status: 200, body: await pool.syncHistory(m[1], body) })],
  ['GET', /^\/accounts\/([^/]+)\/user\/([^/]+)$/, async (m) => ({ status: 200, body: await pool.getUserInfo(m[1], m[2]) })],
];

const server = createServer(async (req, res) => {
  // /health is open; everything else needs the shared token.
  const isHealth = req.method === 'GET' && req.url === '/health';
  if (!isHealth && !authed(req)) {
    return json(res, 401, { error: 'INVALID_SIDECAR_TOKEN' });
  }

  for (const [method, pattern, handler] of routes) {
    if (req.method !== method) continue;
    const m = req.url.match(pattern);
    if (!m) continue;
    try {
      const body = method === 'GET' || method === 'DELETE' ? {} : await readJson(req);
      const { status, body: out } = await handler(m, body);
      return json(res, status, out);
    } catch (err) {
      console.error(`[${method} ${req.url}]`, err);
      return json(res, 500, { error: 'SIDECAR_ERROR', message: err.message });
    }
  }

  json(res, 404, { error: 'NOT_FOUND' });
});

server.listen(PORT, () => {
  console.log(`[sidecar] listening on :${PORT} (stub=${process.env.ZALO_STUB === '1'})`);
});

export { server, pushToCrm };
