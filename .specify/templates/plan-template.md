# Implementation Plan: [FEATURE]

**Branch**: `[###-feature-name]` | **Date**: [DATE] | **Spec**: [link]

**Input**: Feature specification from `/specs/[###-feature-name]/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

[Extract from feature spec: primary requirement + technical approach from research]

## Technical Context

<!--
  ACTION REQUIRED: Replace the content in this section with the technical details
  for the project. The structure here is presented in advisory capacity to guide
  the iteration process.
-->

**Language/Version**: PHP 8.3+ / Laravel 12, TypeScript / React / Inertia

**Primary Dependencies**: Laravel, Inertia React, PostgreSQL, Redis, Laravel Queue/Horizon, Laravel Reverb, shadcn/ui Base UI

**Storage**: PostgreSQL for relational data, Redis for queue/cache/presence/realtime coordination

**Testing**: PHPUnit/Pest feature and integration tests, frontend build checks, browser smoke tests after admin UI exists

**Target Platform**: Web admin application running on Linux/macOS local dev and Linux production

**Project Type**: Modular Laravel CRM web application

**Performance Goals**: Webhook request returns 2xx after raw persistence and queue dispatch; inbox updates appear within a few seconds; admin cockpit remains scannable under normal queue load

**Constraints**: Module boundaries must remain explicit; provider payloads must be validated/idempotent; shadcn composition rules are mandatory; secrets are encrypted and never fully displayed

**Scale/Scope**: First release supports one workspace, Telegram and Zalo OA, CRM Core, Omnichannel Inbox, Assignment Engine, Admin Cockpit, and future module extension points

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

[Gates determined based on constitution file]

### CRM Module Boundary Check

- **Owning Module**: [Which module owns this feature: Platform Core, CRM Core, Omnichannel Inbox, Channel Connectors, Assignment Engine, Admin Cockpit, or future named module]
- **Public Contracts**: [Services, events, jobs, API resources, or typed links exposed to other modules]
- **Data Ownership**: [Tables/models owned by this module and cross-module links it may create]
- **Permissions**: [Policies/roles required for read/write/admin actions]
- **Integration Behavior**: [Queue, retry, idempotency, provider adapter, or N/A]

### shadcn/ui Compliance Check

- **shadcn Context**: [Result of `npx shadcn@latest info --json` once frontend exists, or N/A before scaffold]
- **Components Planned**: [Installed shadcn components to use; custom UI requires justification]
- **Composition Rules**: [Confirm Field/Card/Dialog/Table/Tabs/Sidebar/etc. composition follows `AGENTS.md`]
- **Styling Rules**: [Confirm semantic tokens, `gap-*`, `size-*`, `truncate`, and `cn()`; no raw palette styling or replacement primitives]

## Project Structure

### Documentation (this feature)

```text
specs/[###-feature]/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)
<!--
  ACTION REQUIRED: Replace the placeholder tree below with the concrete layout
  for this feature. Delete unused options and expand the chosen structure with
  real paths (e.g., apps/admin, packages/something). The delivered plan must
  not include Option labels.
-->

```text
app/Modules/[Module]/
├── Actions/
├── Contracts/
├── DTO/
├── Events/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
├── Jobs/
├── Models/
├── Policies/
├── Providers/
└── Services/

routes/modules/[module].php
database/migrations/*_[module]_*.php
resources/js/modules/[module]/
resources/js/components/ui/
resources/js/components/admin/
tests/Feature/Modules/[Module]/
tests/Integration/Modules/[Module]/
tests/Unit/Modules/[Module]/
```

**Structure Decision**: Use the modular monolith structure from `specs/02_MODULE_STRUCTURE.md`. New features must update only their owning module plus documented public contracts/events/jobs/entity links.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| [e.g., 4th project] | [current need] | [why 3 projects insufficient] |
| [e.g., Repository pattern] | [specific problem] | [why direct DB access insufficient] |
