# 02 Module Structure Spec

## Decision

Use a Laravel modular monolith with module code under `app/Modules`. Do not introduce a third-party module package in v1. This keeps the project easy to understand while still enforcing boundaries.

## Folder Layout

```text
app/
├── Modules/
│   ├── Platform/
│   │   ├── Actions/
│   │   ├── Contracts/
│   │   ├── DTO/
│   │   ├── Events/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   ├── Requests/
│   │   │   └── Resources/
│   │   ├── Jobs/
│   │   ├── Models/
│   │   ├── Policies/
│   │   ├── Providers/
│   │   └── Services/
│   ├── Crm/
│   ├── Inbox/
│   ├── Channels/
│   │   ├── Shared/
│   │   ├── Telegram/
│   │   └── Zalo/
│   ├── Routing/
│   └── Admin/
├── Providers/
└── Support/

routes/
└── modules/
    ├── platform.php
    ├── crm.php
    ├── inbox.php
    ├── channels.php
    ├── routing.php
    └── admin.php

database/
└── migrations/
    ├── YYYY_MM_DD_000001_platform_create_workspaces_table.php
    ├── YYYY_MM_DD_010001_crm_create_contacts_table.php
    ├── YYYY_MM_DD_020001_inbox_create_conversations_table.php
    ├── YYYY_MM_DD_030001_channels_create_channel_accounts_table.php
    └── YYYY_MM_DD_040001_routing_create_routing_queues_table.php

resources/js/
├── components/ui/
├── layouts/admin-layout.tsx
├── modules/
│   ├── platform/
│   ├── crm/
│   ├── inbox/
│   ├── channels/
│   ├── routing/
│   └── admin/
└── pages/
    └── admin/

tests/
├── Feature/Modules/
├── Integration/Modules/
└── Unit/Modules/
```

## Module Provider Rules

Each module has one provider registered by `AppServiceProvider` or a `ModuleServiceProvider`:

- Registers routes from `routes/modules/{module}.php`.
- Registers policies.
- Registers event listeners.
- Registers module nav items and health cards with Platform Core.
- Does not boot another module's private services.

## Module Public Surface

Each module may expose only these as public integration points:

- `Contracts/*` interfaces.
- `DTO/*` immutable data objects.
- Domain events in `Events/*`.
- Queued jobs explicitly documented as public.
- API resources for JSON response shape.
- Module registry metadata such as nav items, permissions, health checks.

Everything else is private to the module.

## Naming Rules

- PHP namespace: `App\Modules\{Module}`.
- Module names: `Platform`, `Crm`, `Inbox`, `Channels`, `Routing`, `Admin`.
- Entity codes for cross-module links use lowercase dot notation:
  - `platform.user`
  - `crm.contact`
  - `crm.company`
  - `crm.lead`
  - `crm.deal`
  - `inbox.conversation`
  - `inbox.message`
  - `channels.channel_account`
  - `routing.routing_queue`
- Enum values use uppercase snake case: `OPEN`, `ASSIGNED`, `WAITING_CUSTOMER`, `CLOSED`.
- Routes use plural resource names and kebab-case path segments.

## Dependency Direction

Allowed:

- Any module may depend on Platform contracts.
- Inbox may call CRM public contracts for contact/lead/deal linking.
- Channels may call Inbox public contracts after provider payload normalization.
- Routing may call Inbox and CRM public read contracts for assignment context.
- Admin may read public query/view contracts from all modules.

Forbidden:

- A module must not call another module's Eloquent model directly unless that model is explicitly documented as a public read model.
- A module must not write another module's tables directly.
- A module must not import another module's private action/service class.
- Frontend modules must not import private components from another module; shared UI belongs in `resources/js/components` or a documented shared module.

## Module Registration Contract

Platform Core owns `ModuleRegistry`.

Each module registers:

- `key`: stable code such as `crm`.
- `name`: human label.
- `version`: internal module schema version.
- `permissions`: permission strings.
- `navItems`: admin navigation entries.
- `healthChecks`: closures/services that return `OK`, `WARN`, or `FAIL`.
- `entityTypes`: linkable entity codes it owns.
- `timelineWriters`: optional activity timeline adapters.

## Entity Link Contract

Cross-module references use `entity_links`, not nullable foreign keys scattered across tables.

Required fields:

- `workspace_id`
- `source_type`
- `source_id`
- `target_type`
- `target_id`
- `relation`
- `metadata`
- `created_by_id`
- `created_at`

Examples:

- Conversation linked to contact: `inbox.conversation -> crm.contact`, relation `PRIMARY_CUSTOMER`.
- Conversation linked to lead: `inbox.conversation -> crm.lead`, relation `SALES_CONTEXT`.
- Future task linked to deal: `tasks.task -> crm.deal`, relation `FOLLOW_UP_FOR`.

## shadcn Frontend Structure

- shadcn components live in `resources/js/components/ui`.
- Shared admin layout components live in `resources/js/components/admin`.
- Module-specific pages and panels live in `resources/js/modules/{module}`.
- Page files under `resources/js/pages/admin` compose module components and route props.
- No module should duplicate a shadcn primitive. If a shared wrapper is needed, place it in `resources/js/components/admin` and document why.

## New Module Checklist

Before adding a module, define:

- Module key and owner.
- Tables owned.
- Public contracts.
- Events emitted and listened to.
- Jobs owned.
- Permissions.
- Admin navigation entries.
- Health checks.
- Entity link types.
- Tests.
- Data retention rules.
