# 07 Admin UI Spec

> **See `specs/10_OMNICHANNEL_SUPPORT_PLAN.md`.** Where this spec says
> "Telegram, Zalo", the real channel set is four:
> `TELEGRAM, ZALO_PERSONAL, ZALO_OA, FACEBOOK`. Channel-health and recovery
> actions apply per provider (e.g. Zalo personal adds QR-relogin +
> connect/disconnect status; token refresh applies to `ZALO_OA` only). The
> inbox screen follows the 4-column blueprint in spec 10.

## Goal

Create an admin that is dense, operational, and easy to understand in 5 minutes. The UI is a working cockpit, not a marketing page.

## UI Foundation

- Laravel + Inertia React + TypeScript.
- shadcn/ui Base UI.
- `components.json` is source of truth.
- Use shadcn components before custom markup.
- Use semantic tokens and built-in variants.
- No raw palette styling for core UI.
- No card-inside-card layouts.

## Admin Shell

Persistent layout:

- Left `Sidebar` navigation.
- Top context bar with workspace, global search, queue health, notifications, user menu.
- Main content area with module routes.
- Optional right panel for contextual details.

Navigation groups:

- Overview
- Inbox
- CRM
- Leads
- Deals
- Channels
- Routing
- Reports
- Settings

## Screen Inventory

### Overview Cockpit

Purpose: operations in one glance.

Widgets:

- Channel health: Telegram, Zalo.
- Queue lag: jobs waiting, failed jobs.
- Inbox load: open, waiting agent, breached SLA.
- Agent workload: online, busy, overloaded.
- Failed inbound events.
- Failed outbound messages.
- Token expiry warnings.
- Recent critical audit events.

Primary actions:

- Retry failed outbound.
- Replay failed webhook.
- Refresh Zalo token.
- Re-register Telegram webhook.
- Open overloaded queue.

shadcn:

- `Card`, `Badge`, `Table`, `Button`, `Tooltip`, `Alert`, `Progress`, `Chart`, `Skeleton`.

### Inbox

Purpose: handle customer conversations.

Layout:

- Resizable 3-pane layout: list, thread, customer context.
- List supports filters and search.
- Thread supports reply, note, transfer, close, retry.
- Context panel shows contact, identities, lead/deal links, timeline.

shadcn:

- `Resizable`, `ScrollArea`, `Card`, `Badge`, `Avatar`, `Tabs`, `Textarea`, `InputGroup`, `Button`, `Sheet`, `Dialog`, `Empty`, `Skeleton`, `Sonner`.

### Contacts

Purpose: customer database.

Table columns:

- Name
- Phone/email
- Owner
- Source
- Last inbound
- Active lead/deal
- Tags
- Status

Actions:

- Create contact.
- Edit contact.
- Merge contacts.
- Open timeline.
- Link company.

### Lead Pipeline

Purpose: sales follow-up from conversations.

Views:

- Kanban by stage.
- Table with filters.

Actions:

- Create lead.
- Advance stage.
- Mark won/lost.
- Link conversation.
- Assign owner.

### Channels

Purpose: configure and monitor Telegram/Zalo.

Table columns:

- Provider
- Account name
- Status
- Last webhook
- Last health check
- Token expiry
- Last error

Actions:

- Add channel account.
- Test credentials.
- Register webhook.
- Refresh token.
- Disable account.
- View recent webhook events.

Forms:

- Use `FieldSet`, `FieldGroup`, `Field`, `InputGroup`, `Switch`, `Select`.
- Secrets are write-only after save.

### Routing

Purpose: configure assignment behavior.

Screens:

- Queue list.
- Queue detail.
- Member ordering.
- Assignment attempts.
- SLA settings.

Controls:

- Mode `ToggleGroup`: sticky/even/queue order/broadcast.
- Max active conversations numeric input.
- Timeout seconds input.
- Requires online switch.
- Member reorder.

### Settings

Purpose: workspace-level configuration.

Sections:

- Workspace profile.
- Users and roles.
- Permissions.
- Audit logs.
- Webhook/security settings.
- Retention settings.

## UI State Requirements

Every page must define:

- Loading state using `Skeleton`.
- Empty state using `Empty`.
- Error state using `Alert`.
- Permission-denied state.
- Realtime reconnecting state when relevant.
- Optimistic action state for reply/retry/assign.

## shadcn Compliance Checklist

- Buttons use shadcn `Button`; icons use `data-icon`.
- Forms use `FieldGroup` and `Field`.
- Input with actions uses `InputGroup`.
- Status labels use `Badge`.
- Tables use shadcn `Table`.
- Empty views use `Empty`.
- Loading placeholders use `Skeleton`.
- Toasts use `Sonner`.
- Dialog/Sheet/Drawer always include Title.
- Cards use full Card composition.
- TabsTrigger stays inside TabsList.
- Avatar includes AvatarFallback.
- Use `gap-*`, `size-*`, `truncate`, and `cn()`.

## Accessibility

- Keyboard navigation for inbox list and thread.
- Visible focus states from shadcn defaults.
- ARIA labels for icon-only actions.
- Dialogs/sheets have titles and close behavior.
- Message composer sends with button; keyboard shortcut can be added but must not be the only path.

## Acceptance Criteria

- Admin can identify channel/token/queue/SLA problems from Overview without opening logs.
- Support agent can process assigned conversations from Inbox without leaving the screen.
- Support lead can rebalance workload from Routing/Inbox.
- shadcn compliance can be reviewed from component usage and page structure.
