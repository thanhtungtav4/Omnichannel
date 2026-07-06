// Holds one Zalo (zca-js) instance per channel account and owns the
// QR-login / session / reconnect / listener lifecycle.
//
// STUB mode (stub:true): zca-js is never loaded; QR/login/send are simulated so
// the pipe is testable without a real Zalo account.
// REAL mode (stub:false): uses zca-js 2.x.
//
// QR login is asynchronous (the user must scan). loginQr() kicks it off and
// returns the QR image immediately; the CRM/browser polls status() until the
// account flips to CONNECTED. On GotLoginInfo we persist credentials via
// onCredentials and attach the message listener.

import { imageMetadataGetter } from './image-meta.js';

const ThreadTypeUser = 0; // zca-js ThreadType.User
const ThreadTypeGroup = 1; // zca-js ThreadType.Group

// zca-js needs image dimensions to send photos; Node can't read them natively.
const zaloOptions = { selfListen: true, checkUpdate: false, imageMetadataGetter };

export class ZaloPool {
  constructor({ stub = true, onEvent, onCredentials, onReconnect } = {}) {
    this.stub = stub;
    this.onEvent = onEvent ?? (async () => true);
    this.onCredentials = onCredentials ?? (async () => {});
    // Called when a listener drops and we should reconnect from stored creds.
    this.onReconnect = onReconnect ?? (async () => {});
    /** @type {Map<string, any>} */
    this.accounts = new Map();
    /** @type {Map<string, {zalo:any, api:any}>} */
    this.instances = new Map();
    // Circuit breaker: timestamps of recent disconnects per account.
    /** @type {Map<string, number[]>} */
    this.disconnects = new Map();
  }

  list() {
    return [...this.accounts.entries()].map(([id, a]) => ({
      id,
      status: a.status,
      uid: a.uid,
      since: a.since,
    }));
  }

  status(id) {
    const a = this.accounts.get(id);
    if (!a) return { status: 'DISCONNECTED', uid: null, since: 0, qr: null };
    return { status: a.status, uid: a.uid, since: a.since, qr: a.qr ?? null };
  }

  async loginQr(id) {
    this.accounts.set(id, { status: 'QR_PENDING', uid: null, since: Date.now(), qr: null });

    if (this.stub) {
      const uid = `stub-uid-${id.slice(0, 8)}`;
      this.accounts.set(id, { status: 'CONNECTED', uid, since: Date.now(), qr: null });
      return { qr: 'data:image/png;base64,STUB_QR', status: 'CONNECTED', uid, note: 'stub mode' };
    }

    const { Zalo } = await import('zca-js');
    const zalo = new Zalo(zaloOptions);

    // Fire loginQR in the background; resolve this HTTP call as soon as the QR
    // image is generated so the browser can display it.
    return await new Promise((resolve, reject) => {
      let resolvedQr = false;

      zalo
        .loginQR({}, (event) => {
          // event.type: 0=QRCodeGenerated 1=Expired 2=Scanned 3=Declined 4=GotLoginInfo
          if (event.type === 0) {
            const image = event.data?.image
              ? `data:image/png;base64,${event.data.image}`
              : null;
            const a = this.accounts.get(id) ?? {};
            this.accounts.set(id, { ...a, status: 'QR_PENDING', qr: image, since: Date.now() });
            if (!resolvedQr) {
              resolvedQr = true;
              resolve({ qr: image, status: 'QR_PENDING' });
            }
          } else if (event.type === 1) {
            const a = this.accounts.get(id) ?? {};
            this.accounts.set(id, { ...a, status: 'QR_EXPIRED' });
          } else if (event.type === 2) {
            const a = this.accounts.get(id) ?? {};
            this.accounts.set(id, { ...a, status: 'QR_SCANNED', displayName: event.data?.display_name });
          } else if (event.type === 4) {
            // Persist credentials for reconnect on boot.
            this.onCredentials(id, {
              cookie: event.data.cookie,
              imei: event.data.imei,
              userAgent: event.data.userAgent,
            }).catch(() => {});
          }
        })
        .then((api) => this.onLoggedIn(id, api))
        .catch((err) => {
          console.error(`[loginQr ${id}] failed:`, err?.stack || err?.message || err);
          this.accounts.set(id, { status: 'DISCONNECTED', uid: null, since: Date.now(), qr: null });
          if (!resolvedQr) reject(err);
        });
    });
  }

  async reconnect(id, credentials = {}) {
    if (this.stub) {
      const uid = credentials.uid ?? `stub-uid-${id.slice(0, 8)}`;
      this.accounts.set(id, { status: 'CONNECTED', uid, since: Date.now(), qr: null });
      return { status: 'CONNECTED', uid };
    }

    const { Zalo } = await import('zca-js');
    const zalo = new Zalo(zaloOptions);
    const api = await zalo.login({
      cookie: credentials.cookie,
      imei: credentials.imei,
      userAgent: credentials.userAgent,
    });
    await this.onLoggedIn(id, api);
    return this.status(id);
  }

  // Shared post-login: record uid, attach listener, mark connected.
  async onLoggedIn(id, api) {
    const uid = await api.getOwnId();
    console.log(`[onLoggedIn ${id}] uid=${JSON.stringify(uid)}`);
    this.instances.set(id, { api });
    this.accounts.set(id, {
      status: 'CONNECTED',
      uid: typeof uid === 'object' ? String(uid?.uid ?? uid?.userId ?? '') : String(uid),
      since: Date.now(),
      qr: null,
    });

    api.listener.onMessage((msg) => {
      console.log(`[listener ${id}] message received`);
      this.handleInbound(id, msg).catch((e) => console.error(`[inbound ${id}]`, e.message));
    });

    // Backfill: on every (re)connect, pull recent messages so nothing that
    // arrived while we were disconnected is missed. The CRM's unique index
    // (channel, provider_message_id, direction) drops anything already stored.
    api.listener.on('old_messages', (messages) => {
      console.log(`[backfill ${id}] ${messages?.length ?? 0} old messages`);
      for (const m of messages ?? []) {
        this.handleInbound(id, m, { backfill: true }).catch((e) =>
          console.error(`[backfill ${id}]`, e.message),
        );
      }
    });

    api.listener.on('connected', () => {
      console.log(`[listener ${id}] connected -> requesting old messages`);
      try {
        api.listener.requestOldMessages(ThreadTypeUser);
      } catch (e) {
        console.error(`[backfill ${id}] request failed:`, e.message);
      }
    });

    // Detect drops: mark disconnected + auto-reconnect from stored creds unless
    // the circuit breaker has tripped (>5 drops in 5 min).
    const onDrop = (why) => {
      console.warn(`[listener ${id}] closed: ${why}`);
      this.accounts.set(id, { status: 'DISCONNECTED', uid: null, since: Date.now(), qr: null });
      this.instances.delete(id);

      const now = Date.now();
      const recent = (this.disconnects.get(id) ?? []).filter((t) => now - t < 5 * 60_000);
      recent.push(now);
      this.disconnects.set(id, recent);

      if (recent.length > 5) {
        console.error(`[listener ${id}] circuit breaker tripped (${recent.length} drops/5min) - QR needed`);
        this.accounts.set(id, { status: 'QR_REQUIRED', uid: null, since: Date.now(), qr: null });
        return;
      }
      // Auto-reconnect after 30s.
      setTimeout(() => {
        this.onReconnect(id).catch((e) => console.error(`[reconnect ${id}]`, e.message));
      }, 30_000);
    };

    // 'disconnected' fires on EVERY drop (even when zca-js would silently retry).
    // 'closed' only fires when it gives up. Listen to both so a dead-but-retrying
    // socket doesn't leave us "CONNECTED" while missing messages.
    let retrying = false;
    api.listener.on?.('disconnected', (code, reason) => {
      console.warn(`[listener ${id}] disconnected ${code} ${reason}`);
      this.accounts.set(id, { status: 'RECONNECTING', uid: this.accounts.get(id)?.uid ?? null, since: Date.now(), qr: null });
    });
    api.listener.on?.('connected', () => {
      retrying = false;
      const a = this.accounts.get(id);
      if (a) this.accounts.set(id, { ...a, status: 'CONNECTED' });
    });
    api.listener.on?.('closed', (code, reason) => onDrop(`closed ${code} ${reason}`));
    api.listener.on?.('error', (err) => onDrop(`error ${err?.message ?? err}`));

    // Watchdog: two failure modes.
    //  (a) stuck RECONNECTING >90s -> force reconnect.
    //  (b) status says CONNECTED but the socket died silently (zca-js retried and
    //      failed without emitting an event). Active-probe with a cheap API call
    //      (getGroupInfo of a dummy id / getOwnId) every 60s; on failure reconnect.
    if (this._watchdogs?.get(id)) clearInterval(this._watchdogs.get(id));
    this._watchdogs ??= new Map();
    let lastProbe = 0;
    this._watchdogs.set(id, setInterval(async () => {
      const a = this.accounts.get(id);
      if (!a) return;

      if (a.status === 'RECONNECTING' && Date.now() - a.since > 90_000 && !retrying) {
        retrying = true;
        console.warn(`[watchdog ${id}] stuck reconnecting -> forcing reconnect`);
        this.onReconnect(id).catch((e) => console.error(`[watchdog ${id}]`, e.message));
        return;
      }

      // Active health probe every ~60s while "CONNECTED".
      if (a.status === 'CONNECTED' && Date.now() - lastProbe > 60_000 && !retrying) {
        lastProbe = Date.now();
        try {
          const inst = this.instances.get(id);
          // Real round-trip probe (not the cached uid) so a dead session fails.
          await inst?.api?.fetchAccountInfo?.();
        } catch (e) {
          console.warn(`[watchdog ${id}] health probe failed (${e.message}) -> reconnect`);
          retrying = true;
          this.onReconnect(id)
            .then(() => { retrying = false; })
            .catch((err) => console.error(`[watchdog ${id}]`, err.message));
        }
      }
    }, 30_000));

    api.listener.start({ retryOnClose: true });
    console.log(`[onLoggedIn ${id}] listener started, CONNECTED`);
    return this.status(id);
  }

  // Normalize a zca-js message and push it to the CRM webhook.
  // opts.backfill = true for old_messages replayed on reconnect (CRM dedups).
  // Fetch + cache a group's display name via zca-js getGroupInfo.
  async groupName(id, groupId) {
    this._groupNames ??= new Map();
    const key = `${id}:${groupId}`;
    if (this._groupNames.has(key)) return this._groupNames.get(key);
    try {
      const inst = this.instances.get(id);
      const res = await inst?.api?.getGroupInfo?.(String(groupId));
      const name = res?.gridInfoMap?.[String(groupId)]?.name ?? null;
      if (name) this._groupNames.set(key, name);
      return name;
    } catch {
      return null;
    }
  }

  // Parse a zca-js message content into { text, type, attachmentUrl }.
  // Text is a plain string; media (image/file/sticker/video) is an object with
  // url fields. We surface a display text + the best URL for the CRM.
  parseContent(raw, msgType) {
    if (typeof raw === 'string') {
      return { text: raw, type: 'TEXT', attachmentUrl: null };
    }
    if (!raw || typeof raw !== 'object') {
      return { text: '', type: 'UNSUPPORTED', attachmentUrl: null };
    }
    // zca-js msgType: 'chat.photo', 'chat.video.msg', 'share.file', 'chat.sticker'...
    const t = String(msgType ?? '').toLowerCase();
    const params = (() => {
      try { return raw.params ? JSON.parse(raw.params) : {}; } catch { return {}; }
    })();
    const url =
        raw.hdUrl || params.hd || raw.href || raw.normalUrl || raw.fileUrl ||
        raw.url || raw.thumbUrl || raw.thumb || null;

    if (t.includes('photo') || t.includes('image') || t.includes('gif')) {
      return { text: raw.title || '[Hình ảnh]', type: 'IMAGE', attachmentUrl: url };
    }
    if (t.includes('video')) {
      return { text: raw.title || '[Video]', type: 'VIDEO', attachmentUrl: url };
    }
    if (t.includes('voice') || t.includes('audio')) {
      return { text: '[Tin nhắn thoại]', type: 'AUDIO', attachmentUrl: url };
    }
    if (t.includes('sticker')) {
      return { text: '[Sticker]', type: 'STICKER', attachmentUrl: url };
    }
    if (t.includes('file') || raw.fileName) {
      return { text: raw.title || raw.fileName || '[Tệp tin]', type: 'FILE', attachmentUrl: url };
    }
    // Unknown structured content (e.g. share card) — show its title if any.
    return { text: raw.title || raw.description || '[Nội dung không hỗ trợ]', type: 'UNSUPPORTED', attachmentUrl: url };
  }

  async handleInbound(id, msg, opts = {}) {
    const data = msg?.data ?? msg;
    const parsed = this.parseContent(data?.content, data?.msgType);
    const content = parsed.text;
    const isSelf = msg?.isSelf === true;

    // Group (type 1) vs direct (type 0). For groups the conversation is keyed
    // by the GROUP thread, not the individual sender — otherwise every member
    // spawns a separate 1:1 conversation.
    const isGroup = msg?.type === 1;
    const threadId = String(msg?.threadId ?? data?.idTo ?? data?.uidFrom ?? '');
    const senderId = String(data?.uidFrom ?? '');

    if (!opts.backfill) {
      console.log(`[inbound ${id}] group=${isGroup} thread=${threadId} sender=${senderId} isSelf=${isSelf} content=${String(content).slice(0, 40)}`);
    }

    // For a self message the thread is the RECIPIENT (idTo), not the sender (us).
    // We still push it so replies typed directly in the Zalo app show in the CRM
    // as outbound. The CRM's unique index drops the echo of CRM-sent replies.
    const selfThread = isSelf
      ? String(data?.idTo ?? threadId)
      : threadId;

    // Resolve the group name (zca-js messages don't include it) via getGroupInfo,
    // cached so we don't call it on every message.
    let groupName = data?.groupName ?? null;
    if (isGroup && !groupName) {
      groupName = await this.groupName(id, selfThread);
    }

    const ok = await this.onEvent(id, {
      event_name: 'user_send_text',
      timestamp: Number(data?.ts ?? Date.now()),
      thread_type: isGroup ? 'GROUP' : 'USER',
      thread_id: selfThread,
      is_self: isSelf, // -> CRM marks it OUTBOUND
      group_id: isGroup ? selfThread : null,
      group_name: isGroup ? groupName : null,
      message: {
        msg_id: String(data?.msgId ?? ''),
        seq: data?.msgId ? Number(data.msgId) : undefined,
        text: content,
        message_type: parsed.type,
        attachment_url: parsed.attachmentUrl,
      },
      sender: { id: senderId, name: data?.dName ?? 'Zalo user' },
    });
    if (!opts.backfill) console.log(`[inbound ${id}] pushed ok=${ok}`);
  }

  async send(id, { recipientUid, text, messageId, isGroup = false, attachmentPath = null } = {}) {
    const acct = this.accounts.get(id);
    if (!acct || acct.status !== 'CONNECTED') {
      return { ok: false, error: 'NOT_CONNECTED' };
    }

    if (this.stub) {
      const providerMessageId = `stub-msg-${Date.now()}`;
      await this.onEvent(id, {
        event_name: 'user_send_text',
        direction: 'echo',
        timestamp: Date.now(),
        message: { msg_id: providerMessageId, seq: Date.now(), text },
        sender: { id: acct.uid, name: 'self' },
        recipient: { id: recipientUid },
        client_message_id: messageId ?? null,
      });
      return { ok: true, providerMessageId };
    }

    const inst = this.instances.get(id);
    if (!inst) return { ok: false, error: 'NO_INSTANCE' };

    // zca-js 2.x expects a MessageContent object ({ msg }) for text, not a bare string.
    // attachments is an array of local file paths; caption goes in msg.
    // ThreadType: 0=User (DM), 1=Group.
    const content = { msg: String(text ?? '') };
    if (attachmentPath) content.attachments = [String(attachmentPath)];
    const result = await inst.api.sendMessage(
      content,
      String(recipientUid),
      isGroup ? ThreadTypeGroup : ThreadTypeUser,
    );
    const providerMessageId = String(
      result?.message?.msgId ?? result?.msgId ?? result?.attachment?.[0]?.msgId ?? '',
    );
    return { ok: true, providerMessageId };
  }

  // Fetch a user's current profile (name + avatar) from Zalo. Used by the CRM
  // to fix contacts whose name is still the raw UID (e.g. threads that started
  // with an outbound/self message, so no inbound name was ever seen).
  async getUserInfo(id, uid) {
    if (this.stub) {
      return { ok: true, displayName: `Zalo ${uid}`, avatar: null, uid };
    }
    const inst = this.instances.get(id);
    if (!inst) return { ok: false, error: 'NOT_CONNECTED' };
    try {
      const res = await inst.api.getUserInfo(String(uid));
      const profile = res?.changed_profiles?.[uid]
        ?? res?.changed_profiles?.[String(uid)]
        ?? Object.values(res?.changed_profiles ?? {})[0]
        ?? null;
      if (!profile) return { ok: false, error: 'NOT_FOUND' };
      return {
        ok: true,
        uid: String(uid),
        displayName: profile.displayName || profile.zaloName || null,
        avatar: profile.avatar || null,
      };
    } catch (e) {
      return { ok: false, error: String(e?.message ?? e) };
    }
  }

  // Manual history sync. Zalo only emits old_messages once after a fresh
  // connect, so calling requestOldMessages again does nothing. Instead we
  // reconnect the session, which re-triggers the automatic backfill. The CRM's
  // unique index drops anything already stored.
  //
  // opts.lastMsgId = paginate older messages before this id (deep history).
  // opts.threadType = 'USER' | 'GROUP' (defaults to both).
  // Without a lastMsgId, Zalo won't re-emit old_messages after connect, so we
  // fall back to a reconnect which re-triggers the automatic backfill.
  async syncHistory(id, opts = {}) {
    const inst = this.instances.get(id);
    if (!inst) return { ok: false, error: 'NOT_CONNECTED' };
    try {
      const { lastMsgId = null, threadType } = opts;

      if (lastMsgId) {
        // Deep pagination: pull messages OLDER than lastMsgId for one thread.
        const t = threadType === 'GROUP' ? ThreadTypeGroup : ThreadTypeUser;
        inst.api.listener.requestOldMessages(t, String(lastMsgId));
        return { ok: true, note: `requested history before ${lastMsgId}` };
      }

      // No cursor -> reconnect to force a fresh recent backfill.
      await this.onReconnect(id);
      return { ok: true, note: 'reconnected to backfill recent messages' };
    } catch (e) {
      return { ok: false, error: e.message };
    }
  }

  async disconnect(id) {
    const inst = this.instances.get(id);
    try {
      inst?.api?.listener?.stop?.();
    } catch { /* ignore */ }
    if (this._watchdogs?.get(id)) {
      clearInterval(this._watchdogs.get(id));
      this._watchdogs.delete(id);
    }
    this.instances.delete(id);
    this.accounts.set(id, { status: 'DISCONNECTED', uid: null, since: Date.now(), qr: null });
    return { status: 'DISCONNECTED' };
  }
}
