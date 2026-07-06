# Modular Laravel CRM Constitution

## Core Principles

### I. Module-First CRM

Every feature MUST belong to a named module with clear ownership, routes, policies, requests, actions/services, models, migrations, tests, and frontend entry points. Modules MUST communicate through public contracts, Laravel events, queued jobs, or explicit application services. Direct cross-module coupling is forbidden unless documented in the feature plan and approved as a temporary compatibility bridge.

### II. Contract-First Integrations

External providers such as Telegram and Zalo MUST be isolated behind adapters that normalize inbound payloads into shared CRM conversation/message contracts. Webhook payloads MUST be validated at the boundary, persisted with idempotency keys, and processed through jobs/events. Outbound sends MUST use an outbox-style record with delivery status and retry behavior.

### III. shadcn/ui Compliance

Admin UI MUST use shadcn/ui with Base UI by default, Laravel + Inertia React + TypeScript, and `components.json` as the source of truth. Implementers MUST check shadcn project context before adding components and MUST use shadcn primitives before custom markup. Forms, overlays, cards, tabs, tables, badges, alerts, empty states, icons, spacing, and semantic tokens MUST follow project shadcn rules in `AGENTS.md`.

### IV. Domain Enforcement in Laravel

Business state transitions MUST be enforced in Laravel domain services/models, not only in UI. Policies, Form Requests, observers/services, events, and feature tests MUST protect lifecycle rules for contacts, leads, deals, conversations, assignments, channel accounts, and future modules.

### V. Operator Visibility

Every module MUST expose operational visibility: health, backlog, failed jobs/events, recent activity, permissions, and next action. Admin screens MUST be dense and scannable so an operator can understand system health and workload within 5 minutes.

## Required Module Plan Sections

Every feature plan MUST include:

- Module ownership and boundaries.
- Public contracts, events, jobs, and API resources.
- Data ownership and cross-module links.
- Permissions and policy rules.
- UI entry points and shadcn components to use.
- Queue/retry/idempotency behavior when external systems are involved.
- Feature tests and integration tests.

## shadcn/ui Project Rules

- Use installed shadcn components before custom markup.
- Use the project package runner for shadcn commands.
- Run `npx shadcn@latest info --json` before choosing imports, aliases, icon library, Tailwind version, or component paths.
- Run `npx shadcn@latest docs <component>` before implementing with a component.
- Forms MUST use FieldGroup, Field, InputGroup, validation states with `data-invalid` and `aria-invalid`.
- Cards MUST use CardHeader, CardTitle, CardDescription, CardContent, and CardFooter where applicable.
- Dialog, Sheet, and Drawer MUST include a Title, visually hidden if needed.
- TabsTrigger MUST live inside TabsList; SelectItem MUST live inside SelectGroup; Avatar MUST include AvatarFallback.
- Icons inside buttons MUST use the shadcn icon pattern with `data-icon`.
- Use semantic tokens and built-in variants. Avoid raw palette utilities as primary styling.
- Use `gap-*`, `size-*`, `truncate`, and `cn()`. Avoid `space-x-*`, `space-y-*`, manual overlay z-index, manual dark overrides, and hand-built replacements for existing shadcn primitives.

## Governance

This constitution supersedes informal implementation preferences. Any exception to module boundaries, provider adapter contracts, or shadcn/ui rules MUST be documented in the relevant spec/plan with the reason, risk, and follow-up cleanup task. Amendments require updating this file, `AGENTS.md`, and the master plan when the change affects project-wide architecture or UI rules.

**Version**: 1.0.0 | **Ratified**: 2026-07-04 | **Last Amended**: 2026-07-04
