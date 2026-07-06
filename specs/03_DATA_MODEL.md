# 03 Data Model Spec

> **Amended by `specs/10_OMNICHANNEL_SUPPORT_PLAN.md`.** The omnichannel plan
> adds channels and fields not listed below. When they conflict, spec 10 wins:
> - `provider` enum everywhere is `TELEGRAM, ZALO_PERSONAL, ZALO_OA, FACEBOOK`
>   (was `TELEGRAM, ZALO`). `ZALO_PERSONAL` = zca-js sidecar; `ZALO_OA` = OA API.
> - `messages` adds `provider_message_seq` (BIGINT nullable) - the thread sort key.
> - `contacts` adds `phone_normalized` (indexed).
> - New table `sdk_limits` (rate-limit config; org-default + per-nick override).
> See spec 10 "Stolen Ideas" for rationale.

## Database Defaults

- PostgreSQL is the default database.
- All business tables include `workspace_id` even if v1 runs with one workspace.
- Primary keys use UUIDs for externally referenced business entities.
- High-volume append-only tables use UUID primary keys plus indexed provider IDs.
- Every status column uses canonical uppercase enum-like values.
- All external provider data is stored as raw JSON first, then normalized into typed domain tables.

## Platform Core Tables

### workspaces

- `id`
- `name`
- `slug`
- `status`: `ACTIVE`, `SUSPENDED`
- timestamps

### users

Laravel starter table plus:

- `workspace_id`
- `display_name`
- `status`: `ACTIVE`, `DISABLED`
- `last_seen_at`

### entity_links

Owns all cross-module links.

- `id`
- `workspace_id`
- `source_type`
- `source_id`
- `target_type`
- `target_id`
- `relation`
- `metadata` JSON
- `created_by_id`
- `created_at`

Indexes:

- unique: `workspace_id`, `source_type`, `source_id`, `target_type`, `target_id`, `relation`
- lookup: `workspace_id`, `source_type`, `source_id`
- lookup: `workspace_id`, `target_type`, `target_id`

### audit_logs

- `id`
- `workspace_id`
- `actor_id`
- `module`
- `action`
- `subject_type`
- `subject_id`
- `before` JSON
- `after` JSON
- `metadata` JSON
- `ip_address`
- `user_agent`
- `created_at`

## CRM Core Tables

### contacts

- `id`
- `workspace_id`
- `owner_id`
- `full_name`
- `phone`
- `email`
- `avatar_url`
- `status`: `ACTIVE`, `ARCHIVED`, `BLOCKED`
- `source`: `MANUAL`, `TELEGRAM`, `ZALO_PERSONAL`, `ZALO_OA`, `FACEBOOK`, `IMPORT`, `API`
- `phone_normalized` (canonical 84xxx; dedup key across 0xxx/84xxx/+84xxx - spec 10)
- `last_contacted_at`
- `last_inbound_at`
- timestamps

Indexes:

- `workspace_id`, `owner_id`
- `workspace_id`, `phone`
- `workspace_id`, `phone_normalized`
- `workspace_id`, `email`
- `workspace_id`, `last_inbound_at`

### companies

- `id`
- `workspace_id`
- `owner_id`
- `name`
- `tax_code`
- `phone`
- `email`
- `website`
- `status`
- timestamps

### external_identities

Maps provider users to CRM contacts.

- `id`
- `workspace_id`
- `contact_id`
- `provider`: `TELEGRAM`, `ZALO_PERSONAL`, `ZALO_OA`, `FACEBOOK`
- `provider_account_id`
- `provider_user_id`
- `provider_chat_id`
- `display_name`
- `avatar_url`
- `raw_profile` JSON
- `last_seen_at`
- timestamps

Indexes:

- unique: `workspace_id`, `provider`, `provider_account_id`, `provider_user_id`
- lookup: `workspace_id`, `contact_id`
- lookup: `workspace_id`, `provider_chat_id`

### pipelines

- `id`
- `workspace_id`
- `name`
- `type`: `LEAD`, `DEAL`
- `is_default`
- `sort_order`
- timestamps

### stages

- `id`
- `workspace_id`
- `pipeline_id`
- `name`
- `status_group`: `OPEN`, `WON`, `LOST`
- `sort_order`
- `color_token`
- timestamps

### leads

- `id`
- `workspace_id`
- `contact_id`
- `company_id`
- `owner_id`
- `pipeline_id`
- `stage_id`
- `title`
- `status`: `NEW`, `QUALIFYING`, `OPEN`, `WON`, `LOST`, `ARCHIVED`
- `source`: `TELEGRAM`, `ZALO_PERSONAL`, `ZALO_OA`, `FACEBOOK`, `MANUAL`, `IMPORT`, `API`
- `value_amount`
- `value_currency`
- `last_activity_at`
- timestamps

Indexes:

- `workspace_id`, `owner_id`, `status`
- `workspace_id`, `pipeline_id`, `stage_id`
- `workspace_id`, `contact_id`

### deals

Same shape as leads plus:

- `expected_close_date`
- `won_at`
- `lost_at`
- `lost_reason`

### timeline_activities

- `id`
- `workspace_id`
- `subject_type`
- `subject_id`
- `actor_id`
- `module`
- `type`
- `title`
- `body`
- `metadata` JSON
- `occurred_at`
- timestamps

## Omnichannel Inbox Tables

### conversations

- `id`
- `workspace_id`
- `channel_account_id`
- `contact_id`
- `owner_id`
- `routing_queue_id`
- `status`: `OPEN`, `ASSIGNED`, `WAITING_AGENT`, `WAITING_CUSTOMER`, `CLOSED`, `SPAM`
- `priority`: `LOW`, `NORMAL`, `HIGH`, `URGENT`
- `subject`
- `last_message_id`
- `last_message_at`
- `last_customer_message_at`
- `last_agent_message_at`
- `first_response_due_at`
- `next_response_due_at`
- `closed_at`
- timestamps

Indexes:

- `workspace_id`, `status`, `last_message_at`
- `workspace_id`, `owner_id`, `status`
- `workspace_id`, `contact_id`
- `workspace_id`, `channel_account_id`

### messages

- `id`
- `workspace_id`
- `conversation_id`
- `channel_account_id`
- `provider_message_id`
- `provider_message_seq` (BIGINT nullable; Zalo msgIdNum Snowflake - thread sort key, spec 10)
- `direction`: `INBOUND`, `OUTBOUND`
- `sender_type`: `CUSTOMER`, `AGENT`, `SYSTEM`
- `sender_id`
- `body_text`
- `message_type`: `TEXT`, `IMAGE`, `FILE`, `AUDIO`, `VIDEO`, `STICKER`, `LOCATION`, `RICH`, `UNSUPPORTED`
- `status`: `RECEIVED`, `QUEUED`, `SENDING`, `SENT`, `FAILED`, `DELIVERED`, `READ`
- `raw_payload` JSON
- `sent_at`
- `delivered_at`
- `read_at`
- timestamps

Indexes:

- unique nullable strategy: `workspace_id`, `channel_account_id`, `provider_message_id`, `direction`
- `workspace_id`, `conversation_id`, `created_at`
- `workspace_id`, `status`

### message_attachments

- `id`
- `workspace_id`
- `message_id`
- `file_id`
- `provider_file_id`
- `mime_type`
- `size_bytes`
- `metadata` JSON
- timestamps

### internal_notes

- `id`
- `workspace_id`
- `conversation_id`
- `author_id`
- `body`
- timestamps

### conversation_assignments

- `id`
- `workspace_id`
- `conversation_id`
- `from_user_id`
- `to_user_id`
- `routing_queue_id`
- `reason`: `AUTO_STICKY_OWNER`, `AUTO_EVEN`, `AUTO_QUEUE_ORDER`, `MANUAL_TRANSFER`, `TIMEOUT_REASSIGN`, `ADMIN_OVERRIDE`
- `metadata` JSON
- `created_at`

## Channel Connector Tables

### channel_accounts

- `id`
- `workspace_id`
- `provider`: `TELEGRAM`, `ZALO_PERSONAL`, `ZALO_OA`, `FACEBOOK`
- `name`
- `status`: `DRAFT`, `ACTIVE`, `DEGRADED`, `DISABLED`
- `credentials` encrypted JSON
- `settings` JSON
- `webhook_secret`
- `webhook_url`
- `last_webhook_at`
- `last_health_check_at`
- `last_error_code`
- `last_error_message`
- timestamps

### webhook_events

- `id`
- `workspace_id`
- `channel_account_id`
- `provider`
- `provider_event_id`
- `idempotency_key`
- `event_type`
- `headers` JSON
- `payload` JSON
- `status`: `RECEIVED`, `PROCESSING`, `PROCESSED`, `IGNORED`, `FAILED`, `REPLAYED`
- `error_code`
- `error_message`
- `processed_at`
- timestamps

Indexes:

- unique: `workspace_id`, `channel_account_id`, `idempotency_key`
- `workspace_id`, `status`, `created_at`

### outbox_messages

- `id`
- `workspace_id`
- `channel_account_id`
- `conversation_id`
- `message_id`
- `provider`
- `recipient_external_id`
- `payload` JSON
- `status`: `QUEUED`, `SENDING`, `SENT`, `FAILED`, `RETRYING`, `CANCELLED`
- `attempts`
- `next_attempt_at`
- `provider_response` JSON
- `last_error_code`
- `last_error_message`
- timestamps

### sdk_limits

Rate-limit config for the anti-block limiter (spec 10). One row = org-default
when `channel_account_id` is null, or a per-nick override when set.

- `id`
- `workspace_id`
- `channel_account_id` (nullable; null = org default)
- `category`: `MESSAGE`, `FRIEND_ADD`, `REACTION`, `CHAT_ACTION`, `STRANGER_MESSAGE`
- `daily_limit`
- `burst_limit`
- `burst_window_ms`
- timestamps

Indexes:

- partial unique: (`workspace_id`, `category`) where `channel_account_id` is null
- partial unique: (`workspace_id`, `channel_account_id`, `category`) where `channel_account_id` is not null

## Assignment Engine Tables

### routing_queues

- `id`
- `workspace_id`
- `name`
- `status`: `ACTIVE`, `DISABLED`
- `mode`: `STICKY_THEN_EVEN`, `EVEN`, `QUEUE_ORDER`, `BROADCAST`
- `timeout_seconds`
- `max_active_per_agent`
- `requires_online`
- timestamps

### routing_queue_members

- `id`
- `workspace_id`
- `routing_queue_id`
- `user_id`
- `sort_order`
- `status`: `ACTIVE`, `PAUSED`
- `last_assigned_at`
- timestamps

### agent_presence

- `id`
- `workspace_id`
- `user_id`
- `status`: `ONLINE`, `AWAY`, `BUSY`, `OFFLINE`
- `active_conversation_count`
- `last_seen_at`
- timestamps

### assignment_attempts

- `id`
- `workspace_id`
- `conversation_id`
- `routing_queue_id`
- `candidate_user_id`
- `result`: `ASSIGNED`, `SKIPPED_OFFLINE`, `SKIPPED_LIMIT`, `TIMEOUT`, `FAILED`
- `reason`
- `metadata` JSON
- `created_at`

## Data Retention

- Raw webhook payloads: keep 90 days by default.
- Message bodies: keep indefinitely until workspace retention policy exists.
- Audit logs: keep indefinitely in v1.
- Failed outbound payloads: keep 180 days.
- Token secrets: never log, never show in full after save.
