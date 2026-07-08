# CLAUDE.md — Claude Code vendor-mode entrypoint

This file is loaded by Claude Code automatically. It tells Claude how to find and
use the gstack skills vendored in this repo.

## gstack (vendored — no global install required)

This repo vendors [gstack](https://github.com/garrytan/gstack) v1.58.5.0 directly
into the source tree at two paths (both kept in sync):

- `.agents/skills/gstack-*/SKILL.md` — OpenCode + OpenClaw native
- `.claude/skills/gstack-*/SKILL.md` — Claude Code native

Teammates can use the full skill set without running `./setup` globally. Just open
Claude Code in this repo and invoke skills by name (e.g. `/office-hours`,
`/plan-eng-review`, `/qa`, `/cso`).

### Skill invocation

When the user's request matches an available skill, invoke it via the Skill tool.
When in doubt, invoke the skill. Skills are role-defined — Claude adopts the
specialist persona described in each SKILL.md and applies the methodology.

Key routing rules:

| User intent | Skill |
|-------------|-------|
| Product ideas / brainstorming / "is this worth building?" | `/office-hours` |
| Strategy / scope challenge ("too much? too little?") | `/plan-ceo-review` |
| Architecture / data flow / edge cases | `/plan-eng-review` |
| Design system / plan / mockup review | `/plan-design-review` |
| DX (TTHW, magical moments, friction points) | `/plan-devex-review` |
| Full review pipeline (CEO → design → eng → DX) | `/autoplan` |
| Bugs / errors / "this broke" | `/investigate` |
| QA / test site behavior / "does this work?" | `/qa` |
| Bug report only (no fixes) | `/qa-only` |
| Code review / diff check | `/review` |
| Visual polish / screenshot audit | `/design-review` |
| Design exploration (variants + comparison board) | `/design-shotgun` |
| Production React/Inertia component from a mockup | `/design-html` |
| Security audit / OWASP / threat model | `/cso` |
| Pre-PR prep (tests, review, push, open PR) | `/ship` |
| Update docs to match what shipped | `/document-release` |
| Author a backlog-ready spec/issue | `/spec` |
| Save progress for resume later | `/context-save` |
| Resume from a saved context | `/context-restore` |
| Lock edits to one directory | `/freeze` / `/guard` |
| Warn before destructive commands | `/careful` |

### Vendored-mode caveats

The original gstack skills reference `~/.claude/skills/gstack/bin/*` runtime
binaries which **do not exist in vendored mode**. Each vendored SKILL.md has a
CRM-context preamble that explains this. AI agents should:

1. **Skip the bash preamble** in each skill (it's metadata only — bash commands
   will fail silently via `|| true`).
2. **Apply the methodology described in the body** — phases, heuristics, output
   format. None of that requires the gstack runtime.
3. **Use Claude Code's native tools** (Read, Edit, Write, Bash, Grep, Glob)
   instead of the gstack binaries.

### Project conventions (read alongside skills)

Before running any skill, AI agents should read:

- `@AGENTS.md` — CRM project rules (Laravel, Inertia, shadcn, modular architecture)
- `@ETHOS.md` — gstack builder principles (Boil the Ocean, Search Before
  Building, User Sovereignty, Build for Yourself)
- `@specs/00_MASTER_PLAN.md` — product direction
- `@.ui-craft/brief.md` + `@.ui-craft/tokens.css` — design contract (for UI work)

### Refreshing from upstream

To pull latest gstack changes:

```bash
bash scripts/update-gstack-skills.sh
```

This re-clones gstack at the pinned version, re-runs the vendor script, and
preserves the deep-adaptations for the 3 hot skills (`qa`, `design-html`,
`cso`). See the script header for details.

### Pin

gstack version pinned: **v1.58.5.0**. Bump deliberately — diffs may conflict with
the CRM-adapted sections of the 3 hot skills.

### Auto-update note

The upstream gstack `--team` mode auto-updates from `~/.claude/skills/gstack/`
once per hour (network-failure-safe). Vendored mode does **not** auto-update —
run `scripts/update-gstack-skills.sh` manually when desired.