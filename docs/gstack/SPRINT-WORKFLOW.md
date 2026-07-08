# gstack Sprint Workflow — Think → Plan → Build → Review → Test → Ship → Reflect

This document summarizes the [gstack](https://github.com/garrytan/gstack) sprint
methodology as applied to this Laravel/CRM repo. Read it once; refer back when
starting a new feature or sprint.

> **Source of truth:** `AGENTS.md` (project rules), `ETHOS.md` (builder principles),
> `specs/00_MASTER_PLAN.md` (product direction). This doc maps those to skills.

---

## The sprint shape

```
        ┌─────────────┐
        │  Office     │ ←── validate problem before code
        │  Hours      │
        └──────┬──────┘
               ↓
        ┌─────────────┐
        │  Plan       │ ←── /plan-ceo-review + /plan-eng-review + /plan-design-review
        │  (3 reviews)│
        └──────┬──────┘
               ↓
        ┌─────────────┐
        │  Build      │ ←── implement against the locked plan
        └──────┬──────┘
               ↓
        ┌─────────────┐
        │  Review     │ ←── /review (PR-ready) + /investigate (if stuck)
        └──────┬──────┘
               ↓
        ┌─────────────┐
        │  Test       │ ←── /qa (real browser) + /qa-only (report)
        └──────┬──────┘
               ↓
        ┌─────────────┐
        │  Ship       │ ←── /ship (PR + CI) + /land-and-deploy (merge + verify)
        └──────┬──────┘
               ↓
        ┌─────────────┐
        │  Reflect    │ ←── /retro (weekly) + /document-release (docs sync)
        └─────────────┘
```

---

## Phase 1 — Think (validate problem before code)

**Skills:** `/office-hours`, `/spec`

**When:** Starting a new feature, validating a product direction, considering a
pivot, or untangling a vague ask.

**Output:** Design doc → `~/.gstack/projects/<slug>/ceo-plans/*.md` (or
`docs/decisions/*.md` for durable architecture decisions).

**For CRM:** When Tùng asks "should we add X?" — start here. The CRM has many
directions (Zalo/Telegram/Shopee/TikTok/Facebook) and `/office-hours` forces the
6-forcing-questions pass before any code.

---

## Phase 2 — Plan (3 reviews)

**Skills:** `/plan-ceo-review`, `/plan-eng-review`, `/plan-design-review`,
`/plan-devex-review`, `/autoplan`

**When:** Scope is clear, but architecture or design isn't locked yet.

**Output:** Plan file ending with `## GSTACK REVIEW REPORT`.

**For CRM — module-specific guidance:**

| Reviewer | Looks at |
|----------|----------|
| `/plan-ceo-review` | Scope (does this module earn its place in CRM roadmap?), 10-star-product framing |
| `/plan-eng-review` | Module boundaries (per `specs/02_MODULE_STRUCTURE.md`), data flow, Eloquent scopes, event contracts, queue jobs, multi-tenant isolation |
| `/plan-design-review` | Admin UI patterns (per `.ui-craft/brief.md` + `specs/07_ADMIN_UI.md`), shadcn compliance |
| `/plan-devex-review` | Developer onboarding (per `dev.sh`), TTHW for new dev to ship first feature |

Use `/autoplan` to chain all three.

---

## Phase 3 — Build

**No skill** — just disciplined implementation against the locked plan. Use the
project conventions in `AGENTS.md`:

- Modular boundaries (`app/Modules/{Module}/`)
- Form Requests + Policies + Events + Queue Jobs
- shadcn/ui with semantic tokens (no raw palette utilities)
- Every region: loading / empty / error / permission-denied

**Anti-patterns to avoid:**

- Adding controllers to `app/Http/Controllers/` directly when building module features
- Inline webhook payload handling (use jobs)
- Skipping `BelongsToWorkspace` scope on tenant-scoped queries

---

## Phase 4 — Review

**Skills:** `/review`, `/investigate`, `/codex` (second opinion)

**When:** PR is ready, or before pushing.

**For CRM:**

- `/review` looks for production-only bugs: tenant isolation leaks, webhook
  idempotency gaps, N+1 queries on Laravel Eloquent, RBAC bypasses, race
  conditions in assignment engine.
- `/investigate` if you're stuck: traces data flow, tests hypotheses, stops
  after 3 failed fixes.

---

## Phase 5 — Test

**Skills:** `/qa`, `/qa-only`, `/design-review`

**When:** Feature works locally and you want to verify in a real browser.

**For CRM:**

- Target staging: `https://{workspace-slug}.qrf.vn/admin` or local `http://localhost:8000/admin`
- Test priorities: omnichannel inbox, webhook ingestion, assignment engine, RBAC
- Mock webhooks via `Http::fake([...])` — never hit live Zalo/Telegram in tests

---

## Phase 6 — Ship

**Skills:** `/ship`, `/land-and-deploy`, `/canary`

**When:** Tests pass, review approved, ready to merge.

**For CRM:** The repo already has GitHub Actions auto-deploy on main (`code +
npm run build + migrate`). So `/ship` should:

1. Run `php artisan test` (all tests)
2. Run `npm test` + `npm run build`
3. Verify lint clean
4. Push branch + open PR
5. After PR approval + merge → GitHub Actions auto-deploys to VPS

Skip `/land-and-deploy` and `/canary` — they're tied to the upstream gstack
runtime and platform integrations we don't use.

---

## Phase 7 — Reflect

**Skills:** `/document-release`, `/retro`, `/learn`

**When:** After shipping, weekly, or end of sprint.

**For CRM:**

- `/document-release` updates `specs/*` and `AGENTS.md` to match what shipped
- `/retro` (when team grows) — solo for now, skip
- `/learn` — gstack has a per-project learnings file. For CRM, prefer
  `AGENTS.md` + per-module docs since OpenCode hosts read those automatically.

---

## Cross-cutting skills (use anytime)

- `/careful` — warn before destructive commands
- `/freeze` — lock edits to one directory
- `/guard` — `/careful` + `/freeze` together (max safety for prod work)
- `/spec` — turn vague intent into a GitHub-ready spec
- `/context-save` / `/context-restore` — survive across sessions
- `/make-pdf` / `/diagram` — for deliverables

---

## Skipped skills (rationale)

| Skill | Why skip |
|-------|----------|
| All `ios-*` skills | CRM has no iOS surface |
| `/open-gstack-browser` | Use Playwright (already configured in `.playwright-cli/`) |
| `/pair-agent` | Solo for now |
| `/setup-deploy`, `/setup-gbrain`, `/sync-gbrain`, `/setup-browser-cookies` | CRM has its own deploy pipeline (GitHub Actions → VPS) |
| `/gstack-upgrade` | Use `scripts/update-gstack-skills.sh` (CRM-adapted) |
| `/land-and-deploy`, `/canary` | Tied to gstack's runtime; GitHub Actions handles deploy |

---

## How to read this

1. **Starting a feature?** → `/office-hours` (validate) → `/plan-eng-review` (lock)
2. **Stuck on a bug?** → `/investigate` (3-fix cap before escalation)
3. **Ready to ship?** → `/review` → `/qa` → `/ship`
4. **Design question?** → `/plan-design-review` (plan) → `/design-shotgun` (variants)
   → `/design-html` (production component)
5. **Security question?** → `/cso` (full audit) → `/plan-eng-review` (lock)