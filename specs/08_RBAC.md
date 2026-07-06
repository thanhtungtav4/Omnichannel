# 08 RBAC And Permissions Spec

## Goal

Protect customer data, provider credentials, routing controls, and support workflows with explicit roles and policies.

## Roles

### owner

Full workspace control.

### admin

Operational admin excluding destructive owner-only workspace actions.

### support_lead

Manages conversations, assignments, queues, and support workload.

### support_agent

Handles assigned conversations and CRM records they are allowed to see.

### sales

Manages contacts, leads, deals, and sees linked conversations.

### viewer

Read-only access to permitted CRM and reporting screens.

## Permission Matrix

| Capability | owner | admin | support_lead | support_agent | sales | viewer |
| --- | --- | --- | --- | --- | --- | --- |
| View cockpit | yes | yes | yes | yes | yes | yes |
| Manage users/roles | yes | yes | no | no | no | no |
| View all contacts | yes | yes | yes | assigned/linked | yes | yes |
| Create/edit contacts | yes | yes | yes | limited | yes | no |
| Merge contacts | yes | yes | yes | no | yes | no |
| View all conversations | yes | yes | yes | assigned/queue | linked | no |
| Reply assigned conversation | yes | yes | yes | yes | no | no |
| Reply any conversation | yes | yes | yes | no | no | no |
| Transfer conversation | yes | yes | yes | no | no | no |
| Close conversation | yes | yes | yes | assigned | no | no |
| Retry outbound message | yes | yes | yes | assigned | no | no |
| Replay webhook event | yes | yes | no | no | no | no |
| Configure channel account | yes | yes | no | no | no | no |
| View provider secrets | no full reveal | no full reveal | no | no | no | no |
| Refresh Zalo token | yes | yes | no | no | no | no |
| Register Telegram webhook | yes | yes | no | no | no | no |
| Configure routing queues | yes | yes | yes | no | no | no |
| Manage pipelines/stages | yes | yes | no | no | yes | no |
| View audit logs | yes | yes | limited | no | no | no |

## Permission Names

Platform:

- `platform.users.manage`
- `platform.roles.manage`
- `platform.audit.view`
- `platform.settings.manage`

CRM:

- `crm.contacts.view`
- `crm.contacts.view_all`
- `crm.contacts.create`
- `crm.contacts.update`
- `crm.contacts.merge`
- `crm.leads.manage`
- `crm.deals.manage`
- `crm.pipelines.manage`

Inbox:

- `inbox.conversations.view`
- `inbox.conversations.view_all`
- `inbox.reply_assigned`
- `inbox.reply_any`
- `inbox.transfer`
- `inbox.close`
- `inbox.retry_outbound`
- `inbox.notes.create`

Channels:

- `channels.accounts.view`
- `channels.accounts.manage`
- `channels.webhooks.replay`
- `channels.outbox.retry`
- `channels.health.view`

Routing:

- `routing.queues.view`
- `routing.queues.manage`
- `routing.assignments.override`

## Policy Rules

Conversation access:

- Owner/admin/support lead can view all.
- Support agent can view assigned conversations and conversations in queues they belong to.
- Sales can view conversations linked to contacts/leads/deals they can view.

Reply:

- User must have reply permission and conversation access.
- `support_agent` can reply only assigned conversations.
- Conversation cannot be `CLOSED` unless user has reopen/override permission.

Channel settings:

- Only owner/admin can create/update channel accounts.
- Secrets are write-only; show masked value and last updated timestamp.
- Every token/webhook change writes audit log.

Webhook replay:

- Only admin/owner.
- Replay must keep original raw payload immutable.
- Replay creates new processing attempt metadata, not a new raw event.

## Audit Requirements

Audit these actions:

- Login/logout failure for admin routes.
- Create/update/delete channel account.
- Token refresh success/failure.
- Webhook replay.
- Outbox retry.
- Conversation assign/transfer/close/reopen.
- Contact merge.
- Lead/deal stage changes.
- Permission/role changes.

## Acceptance Criteria

- Permission matrix is implemented as seed data.
- Policies block unauthorized reply/transfer/retry/configuration actions.
- Sensitive provider credentials are never returned in API responses.
- Audit logs show who changed routing/channel/assignment state.
