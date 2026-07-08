# Architecture sketch — how gstack fits into this CRM repo

This doc explains where gstack vendored files sit and how they integrate with the
existing CRM architecture. For the **product** architecture, see
[`specs/01_ARCHITECTURE.md`](../01_ARCHITECTURE.md). This doc is about the **tooling**
layer.

## Top-level layout

```
crm/
├── AGENTS.md                          # ← existing project rules (now +gstack section)
├── CLAUDE.md                          # ← Claude Code entrypoint (new)
├── ETHOS.md                           # ← gstack builder principles (vendored)
├── specs/                             # ← existing product specs (00-14)
├── .ui-craft/                         # ← existing design contract
│
├── .agents/skills/gstack-*/SKILL.md   # ← gstack vendored (48 skills)
├── .claude/skills/gstack-*/SKILL.md   # ← gstack vendored (48 skills, sync copy)
│
├── docs/gstack/                       # ← gstack integration docs (new)
│   ├── SPRINT-WORKFLOW.md             # ← how to use gstack in CRM context
│   ├── ARCHITECTURE-SKETCH.md         # ← this file
│   └── HOT-SKILLS.md                  # ← 3 deep-adapted skills: qa, design-html, cso
│
├── scripts/
│   └── update-gstack-skills.sh        # ← refresh from upstream
│
├── app/Modules/                       # ← existing modular architecture
│   ├── Inbox/
│   ├── Channels/                      # ← Zalo, Telegram, Shopee, TikTok adapters
│   ├── Routing/                       # ← assignment engine, presence
│   └── ...
│
└── resources/js/                      # ← existing Inertia React frontend
    ├── Pages/{Module}/{Page}.tsx
    ├── components/ui/                 # ← shadcn
    └── components/{module}/
```

## Why duplicate `.agents/skills/` + `.claude/skills/`?

AI coding hosts look for skills at host-specific paths:

| Host | Skill path |
|------|------------|
| OpenCode | `.agents/skills/` (native) |
| OpenClaw | `.agents/skills/` (native) |
| Claude Code | `.claude/skills/` (native) |
| Codex CLI | `~/.codex/skills/` (global only — not vendored here) |
| Cursor | `.cursor/skills/` (or `~/.cursor/skills/`) |

By vendoring to **both** `.agents/skills/` and `.claude/skills/`, every host that
works in this repo can pick up the skills without per-host configuration.

**Trade-off:** double storage (~5 MB → ~10 MB on disk). Mitigated by
`scripts/update-gstack-skills.sh` which keeps both paths in sync atomically.

## Skill naming convention

Each skill folder is prefixed `gstack-` to:

1. Distinguish from non-gstack skills (e.g. OpenCode ships its own Doubt-style
   skills like `idea-refine`, `planning-and-task-breakdown` in `.agents/skills/`).
2. Allow the user to invoke by typing `/gstack-<skill>` if they want to be
   unambiguous about which system they're calling.

## Lifecycle

```
       ┌──────────────────────────────────────────────────────────────┐
       │                  Initial vendor (this commit)               │
       │   48 skills × 2 paths = 96 SKILL.md files, ETHOS.md,        │
       │   CLAUDE.md, scripts/update-gstack-skills.sh,                │
       │   docs/gstack/{SPRINT-WORKFLOW, ARCHITECTURE-SKETCH, ...}   │
       └──────────────────────┬───────────────────────────────────────┘
                              ↓
       ┌──────────────────────────────────────────────────────────────┐
       │              Upstream gstack releases new version           │
       │   (Garry Tan ships new skills, methodology updates)          │
       └──────────────────────┬───────────────────────────────────────┘
                              ↓
       ┌──────────────────────────────────────────────────────────────┐
       │  $ bash scripts/update-gstack-skills.sh                      │
       │    1. Re-clones gstack at pinned version                     │
       │    2. Re-runs vendor script for 45 minimal skills            │
       │    3. Re-applies deep-adapt for 3 hot skills (qa, design-   │
       │       html, cso) — preserves CRM-specific patterns           │
       │    4. Updates ETHOS.md (vendored verbatim)                   │
       │    5. Reports diff vs existing vendored copy for review     │
       └──────────────────────┬───────────────────────────────────────┘
                              ↓
       ┌──────────────────────────────────────────────────────────────┐
       │              Human review of script output                  │
       │   - Inspect any conflict in hot skill adaptations          │
       │   - Spot-check 1-2 minimal skills for upstream drift        │
       │   - Commit on a branch, PR review, merge                    │
       └──────────────────────────────────────────────────────────────┘
```

## Vendored-mode vs global-install trade-off

| Dimension | Vendored (this setup) | Global install (`--team`) |
|-----------|----------------------|---------------------------|
| Teammate onboarding | `git clone` only | `git clone` + `cd ~/.claude/skills/gstack && ./setup --team` |
| Storage | ~10 MB in repo | ~5 MB global, ~50 bytes in repo |
| Version drift | Manual via script | Auto-update once/hour |
| Customization | Easy (full source in repo) | Requires fork + re-vendor |
| Cross-machine consistency | Git-based (deterministic) | Network-dependent |
| Best for | Small teams, pinned versions, customization | Large teams, latest skills, less ops |

For a small team (1-3 devs) building a custom Laravel CRM, vendored wins.

## What lives where — quick reference

| Need | File |
|------|------|
| Project rules for AI agents | `AGENTS.md` |
| Claude Code-specific entrypoint | `CLAUDE.md` |
| Builder principles (Boil the Ocean, etc.) | `ETHOS.md` |
| Skill catalog + when to use | `docs/gstack/SPRINT-WORKFLOW.md` |
| 3 deep-adapted skills (CRM examples) | `.agents/skills/gstack-{qa,design-html,cso}/SKILL.md` |
| Other 45 skills (verbatim + preamble) | `.agents/skills/gstack-*/SKILL.md` |
| Refresh from upstream | `scripts/update-gstack-skills.sh` |
| OpenClaw / Codex / Cursor integration | Add later if needed — current setup covers OpenCode + Claude Code |