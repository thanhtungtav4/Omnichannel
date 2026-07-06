// Self-check for the sidecar pool contract in stub mode. Run: node --test
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ZaloPool } from './zalo-pool.js';

test('stub login connects and reports status', async () => {
  const pool = new ZaloPool({ stub: true });
  const r = await pool.loginQr('acct-1234abcd');
  assert.equal(r.status, 'CONNECTED');
  assert.ok(r.uid);
  assert.equal(pool.status('acct-1234abcd').status, 'CONNECTED');
});

test('send fails when not connected', async () => {
  const pool = new ZaloPool({ stub: true });
  const r = await pool.send('never-logged-in', { text: 'hi' });
  assert.equal(r.ok, false);
  assert.equal(r.error, 'NOT_CONNECTED');
});

test('send after login pushes a self-echo event to the CRM', async () => {
  const events = [];
  const pool = new ZaloPool({ stub: true, onEvent: async (id, p) => { events.push([id, p]); return true; } });
  await pool.loginQr('acct-x');
  const r = await pool.send('acct-x', { recipientUid: 'cust-1', text: 'hello', messageId: 'local-1' });
  assert.equal(r.ok, true);
  assert.ok(r.providerMessageId);
  assert.equal(events.length, 1);
  assert.equal(events[0][0], 'acct-x');
  assert.equal(events[0][1].message.text, 'hello');
  assert.equal(events[0][1].client_message_id, 'local-1');
});

test('disconnect clears the account', async () => {
  const pool = new ZaloPool({ stub: true });
  await pool.loginQr('acct-y');
  await pool.disconnect('acct-y');
  assert.equal(pool.status('acct-y').status, 'DISCONNECTED');
});
