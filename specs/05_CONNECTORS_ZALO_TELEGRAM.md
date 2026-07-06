# 05 Zalo And Telegram Connector Spec

> **Extended by `specs/10_OMNICHANNEL_SUPPORT_PLAN.md`.** This spec covers the
> official-webhook connectors (Telegram, Zalo OA) and the shared
> `ChannelAdapter` contract. Spec 10 adds two more adapters implementing the
> same contract: `ZALO_PERSONAL` (zca-js via a Node sidecar - QR login, session
> persist, reconnect/circuit-breaker, anti-block rate limiter) and `FACEBOOK`
> (Messenger webhook + Graph send). The `provider` enum is
> `TELEGRAM, ZALO_PERSONAL, ZALO_OA, FACEBOOK`. The `ChannelAdapter` interface
> below is now resolved through a `ChannelAdapterRegistry` (spec 10 Task 1),
> not a single if/else. See spec 10 for the sidecar contract and dedup/media rules.

## Goal

Sync Zalo OA and Telegram customer messages into CRM Inbox, allow agents to reply from CRM, and expose enough provider health for admins to debug without reading logs.

## Shared Adapter Contract

Every provider adapter implements:

```php
interface ChannelAdapter
{
    public function verifyWebhook(ChannelAccount $account, Request $request): WebhookVerificationResult;

    public function extractIdempotencyKey(ChannelAccount $account, array $payload, array $headers): string;

    public function normalizeInbound(ChannelAccount $account, WebhookEvent $event): NormalizedInboundMessageCollection;

    public function buildOutboundPayload(ChannelAccount $account, OutboxMessage $message): ProviderOutboundPayload;

    public function sendOutbound(ChannelAccount $account, ProviderOutboundPayload $payload): ProviderSendResult;

    public function checkHealth(ChannelAccount $account): ProviderHealthResult;
}
```

Normalized inbound message fields:

- `provider`
- `providerAccountId`
- `providerEventId`
- `providerMessageId`
- `providerConversationId`
- `providerUserId`
- `providerChatId`
- `senderDisplayName`
- `senderAvatarUrl`
- `messageType`
- `bodyText`
- `attachments`
- `providerTimestamp`
- `rawPayload`

## Shared Webhook Flow

1. Receive POST request at provider webhook endpoint.
2. Resolve `channel_account` by URL UUID.
3. Verify request secret/signature when provider supports it.
4. Compute idempotency key.
5. Insert `webhook_events` row.
6. If duplicate, return 2xx and mark duplicate ignored.
7. Dispatch `NormalizeInboundMessageJob`.
8. Return 2xx quickly.
9. Job validates provider payload shape again before normalization.
10. Normalized messages enter CRM/Inbox/Assignment flow.

## Telegram Connector

### Endpoint

- `POST /webhooks/telegram/{channelAccount:uuid}`

### Required Configuration

- Bot token, encrypted.
- Webhook secret token.
- Allowed update types: start with `message`, `edited_message`, `callback_query`, `my_chat_member`.
- Bot username from `getMe`.
- Webhook URL.

### Verification

- Use Telegram `secret_token` when calling `setWebhook`.
- Incoming requests must include `X-Telegram-Bot-Api-Secret-Token` matching the channel account secret.
- Reject mismatched requests with 401 but do not log token value.

### Idempotency

- Primary idempotency key: `telegram:{channel_account_id}:update:{update_id}`.
- Message idempotency key: `telegram:{chat_id}:{message_id}`.
- Store raw `Update` payload on `webhook_events`.

### Inbound Mapping

- `message.from.id` -> provider user id.
- `message.chat.id` -> provider chat id.
- `message.message_id` -> provider message id.
- `message.text` or caption -> body text.
- photos/files/audio/video/stickers become attachments or unsupported message types if not yet downloadable.

### Outbound Send

- Text reply uses `sendMessage`.
- Store Telegram API response in `outbox_messages.provider_response`.
- Mark local message `SENT` when Telegram returns ok.
- On API error, store error code/description and mark `FAILED` or `RETRYING` depending on retry policy.

### Health

Admin health check uses:

- `getMe` to verify token.
- `getWebhookInfo` to show URL, pending update count, last error date/message.
- Last inbound webhook time.

## Zalo OA Connector

### Endpoint

- `POST /webhooks/zalo/{channelAccount:uuid}`

### Required Configuration

- OA ID.
- App ID.
- App secret, encrypted.
- OA access token, encrypted.
- OA refresh token, encrypted.
- Token expiry timestamp.
- Webhook URL.
- Optional verify token or configured app-level verification material when available.

### Verification

- Persist request headers and payload.
- Validate using Zalo-provided signature/verification fields when configured.
- If official signature verification cannot be enabled in environment, require an unguessable account UUID URL and admin warning `WEBHOOK_SIGNATURE_NOT_CONFIGURED`.

### Idempotency

- Primary idempotency key: `zalo:{channel_account_id}:event:{event_name}:{message_id_or_timestamp_hash}`.
- If payload has message id, use it.
- If no stable id exists, hash provider event name, sender id, timestamp, and body/attachment identifiers.

### Inbound Mapping

- User sends text/image/file/sticker/etc. -> normalized inbound message.
- Zalo user id -> provider user id.
- OA id -> provider account id.
- Message id -> provider message id when present.
- Timestamp -> provider timestamp.
- Unsupported event types are stored as webhook events with status `IGNORED` and visible to admins as unsupported.

### Outbound Send

- Use Zalo OA message API for consultation/support messages.
- Create local message + outbox row before provider call.
- On token expired/invalid, mark channel account `DEGRADED`, dispatch token refresh when refresh token is available, then retry according to policy.
- Store provider response and Zalo error code.

### Token Lifecycle

- Token values are encrypted.
- Never show tokens after save.
- Health check warns before expiry.
- `RefreshZaloAccessTokenJob` refreshes access token before expiry.
- Failed refresh sets account status `DEGRADED` and creates admin action.

## Retry Policy

Inbound normalization:

- Retry transient DB/queue errors.
- Do not retry invalid payload shape until manually replayed.

Outbound:

- Retry network timeouts and provider 5xx.
- Retry token-expired only after refresh attempt.
- Do not retry permanent recipient errors such as user blocked/unreachable unless admin manually retries.
- Default backoff: 1 minute, 5 minutes, 15 minutes, 1 hour.
- Max automatic attempts: 5.

## Admin Recovery Actions

- Test account credentials.
- Re-register Telegram webhook.
- Refresh Zalo token.
- Replay failed webhook event.
- Retry failed outbound message.
- Disable channel account.
- Mark unsupported event as reviewed.

## Provider Health States

- `ACTIVE`: credential valid, webhook healthy, recent checks passing.
- `DEGRADED`: token near expiry, webhook has recent failures, pending updates high, or outbound failures elevated.
- `DISABLED`: admin disabled or credentials removed.
- `DRAFT`: not fully configured.

## External References

- Telegram Bot API: https://core.telegram.org/bots/api
- Telegram `setWebhook`, `secret_token`, and webhook info behavior: https://core.telegram.org/bots/api#setwebhook
- Zalo OA webhook overview: https://developers.zalo.me/docs/official-account/webhook/tong-quan
- Zalo user message webhook: https://developers.zalo.me/docs/official-account/webhook/tin-nhan/su-kien-nguoi-dung-gui-tin-nhan
- Zalo OA access token docs: https://developers.zalo.me/docs/api/official-account-api/phu-luc/official-account-access-token-post-4307
- Zalo OA message API docs: https://developers.zalo.me/docs/api/official-account-api/api/gui-tin-nhan-post-2343
