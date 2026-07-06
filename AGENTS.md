@/Users/macbook/.codex/RTK.md

# CRM Project Rules

## Product Direction

- Build a modular Laravel CRM inspired by Bitrix24 contact-center workflows, starting with customer management, omnichannel chat, Zalo/Telegram sync, and support assignment.
- Do not build a monolithic "all-in-one" module. Every feature must live inside a clear module boundary so future modules can be added, disabled, reused, or linked without rewriting the core.
- The first product slice is CRM + Omnichannel Inbox. Later modules may include campaigns, tasks, invoices, inventory, appointments, reporting, AI assistant, and workflow automation.

## Modular Architecture

- Each business module owns its routes, policies, requests, actions/services, models, migrations, tests, and frontend pages/components.
- Modules communicate through public contracts, Laravel events, queued jobs, or explicit application services. Avoid direct cross-module database assumptions.
- Shared concepts such as users, permissions, audit logs, notifications, tags, files, and activity timeline belong in platform/core modules.
- Cross-module links must be explicit: for example, a conversation can link to a contact, lead, deal, task, ticket, or future module entity through a typed relation/link table.
- Keep state machines canonical in the domain layer. UI may display or disable actions, but cannot be the only place enforcing lifecycle rules.

## Laravel Rules

- Prefer Laravel 12 conventions, Eloquent models, Form Requests, Policies, Queues, Events, Notifications, and feature tests.
- Use PostgreSQL as the default relational database and Redis for queues, cache, presence, and realtime coordination.
- Validate all external webhook/provider payloads at the boundary before persisting normalized data.
- Make inbound webhooks idempotent using provider event IDs, message IDs, update IDs, or payload hashes.
- Keep provider integrations behind adapters so Zalo, Telegram, and future channels expose one normalized internal contract.

## shadcn/ui Rules

- Use shadcn/ui with Base UI for the admin frontend unless a future decision record explicitly changes it.
- For Laravel, create the Laravel React/Inertia app first, then initialize shadcn in that app. Treat `components.json` as the source of truth.
- Use the project package runner for shadcn commands, for example `npx shadcn@latest ...` unless `packageManager` says otherwise.
- Before adding or using a shadcn component, check project context with `npx shadcn@latest info --json` and fetch component docs with `npx shadcn@latest docs <component>`.
- Use installed shadcn components before custom markup. Prefer Button, Field, InputGroup, Select, ToggleGroup, Table, Card, Badge, Avatar, Dialog, Sheet, Drawer, Tabs, Sidebar, Tooltip, Alert, Empty, Skeleton, Spinner, Sonner, Chart, Separator, ScrollArea, and Pagination where appropriate.
- Compose components according to shadcn rules: full Card structure, overlay Title always present, TabsTrigger inside TabsList, SelectItem inside SelectGroup, AvatarFallback always present.
- Forms must use FieldGroup/Field and data-invalid/aria-invalid validation states. Do not use raw layout divs as form structure.
- Icons in buttons must use the shadcn icon pattern with `data-icon`; do not manually size icons inside shadcn components.
- Use semantic tokens such as `bg-background`, `text-muted-foreground`, `bg-primary`, and variants. Do not hardcode raw palette utilities for core styling.
- Use `gap-*`, `size-*`, `truncate`, and `cn()` patterns. Avoid `space-x-*`, `space-y-*`, manual z-index for overlays, manual dark color overrides, and hand-built badges/alerts/empty states.

## Admin UX Rules

- Admin screens must be dense, operational, and scannable. The operator should understand current health, queues, workload, and blockers within 5 minutes.
- The primary admin layout is app-first, not a marketing landing page: sidebar navigation, top context bar, tables, filters, queues, status badges, and realtime panels.
- Every module dashboard must expose health, backlog, failed jobs/events, recent activity, and next action.

## Design System & UI Direction

Any AI touching UI MUST read these two files first (they are the design contract, not optional):

- `.ui-craft/brief.md` — product purpose, primary user, and 5 ranked principles. Cite the principle a design decision applies to. If a decision isn't covered, the brief is incomplete — surface the gap, don't improvise.
- `.ui-craft/tokens.css` — the token spine. Extends shadcn semantic tokens; does not create a parallel system. On scaffold, paste its `:root`/`.dark` blocks into `resources/css/app.css` after `shadcn init`.

Persona is **operator** ("what needs action now?"), not exec or analyst. Use the Command composition: the work queue/table dominates (60%+ viewport), metrics are a compact strip, charts are sparklines only.

Hard rules (mirror the brief principles):

- **Status over decoration.** Color is a signal only — spent on SLA / delivery / channel / assignment / job state. Accent (`--primary`) ≤5 placements per viewport; everything else neutral.
- **Every status uses one of the 5 status token sets** in `.ui-craft/tokens.css` (`--status-ok/warn/danger/info/idle`), never a raw palette utility. Follow the enum→token map at the bottom of that file — it is keyed to the canonical enums in `specs/03_DATA_MODEL.md`.
- **Numbers are tabular.** Every count / metric / amount uses `font-variant-numeric: tabular-nums` with `var(--font-mono)`, right-aligned in tables.
- **Every operational state visible without logs.** Failed jobs, token expiry, SLA breach, unassigned conversations get an on-screen badge with a one-click fix action.
- **Every data region defines loading (Skeleton), empty (Empty), error (Alert), and permission-denied states** — per `specs/07_ADMIN_UI.md`.
- Dark mode is intentional (tinted near-black canvas, reduced accent chroma, border rings over shadows) — never a raw inversion.

Screen specs live in `specs/07_ADMIN_UI.md` (inventory, layout, component mapping) and `specs/04_OMNICHANNEL_INBOX.md` (inbox behavior). Treat their component lists and UI-state requirements as acceptance criteria. Before shipping a screen, run the shadcn compliance checklist in `specs/07_ADMIN_UI.md` as a task gate.

## Quality Gates

- Every module needs focused feature tests for its public behavior and integration tests for cross-module contracts.
- External provider integrations must have mocked webhook ingestion and outbound delivery tests.
- Plans and specs must include module boundaries, public contracts, data ownership, events/jobs, permissions, UI entry points, and acceptance criteria.
