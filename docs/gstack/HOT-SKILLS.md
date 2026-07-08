# Hot Skills — 3 deep-adapted for CRM context

Most vendored skills (45 of 48) are kept verbatim from upstream gstack with only
a CRM-context preamble inserted. These 3 are special — they're **deep-adapted**
with Laravel/Inertia/shadcn-specific patterns appended.

## Why these 3?

| Skill | Why deep-adapt |
|-------|----------------|
| `/qa` | Most-touched skill for daily work. Tests in Laravel need backend (PHPUnit/Pest) + frontend (Playwright) + webhook simulation. Needs CRM-specific fixtures (ZaloPayloadFixtures, Conversation, Workspace). |
| `/design-html` | gstack generates vanilla HTML. CRM uses Inertia React + shadcn/ui — different output format. Translation table needed. |
| `/cso` | Security audits in Laravel need multi-tenant isolation checks, webhook idempotency validation, RBAC enforcement — generic patterns miss this. |

## What's different from upstream?

Each hot skill has **2 changes** vs upstream:

1. **Prepended CRM preamble** (replaces bash preamble that won't work in
   vendored mode). Explains the vendored-mode caveats and lists the
   project-specific files to read before invoking.

2. **Appended CRM appendix** with:
   - Project environment (local dev, staging URLs, test commands)
   - Cross-cutting concerns (modular architecture, tenant isolation, webhook
     idempotency, shadcn rules)
   - Common file locations
   - **For `/qa`:** CRM-specific test priorities + regression test format
   - **For `/design-html`:** Inertia translation table + shadcn components
     inventory + hard rules from `.ui-craft/brief.md`
   - **For `/cso`:** High-risk areas unique to multi-tenant Laravel CRM +
     standard Laravel audit additions + CRM-specific severity multipliers

## When upstream changes these 3

If upstream gstack ships a major update to `qa/SKILL.md`, `design-html/SKILL.md`,
or `cso/SKILL.md`:

1. `scripts/update-gstack-skills.sh` re-clones + re-runs the minimal vendor
2. Then it **re-applies the deep-adapt** for these 3 (preserves the CRM
   appendix + preamble)
3. **You** must review the merge:
   - Did upstream add new phases? → integrate into CRM appendix
   - Did upstream change heuristic? → check CRM example still applies
   - Did upstream remove sections? → preserve CRM-specific replacement

The script is idempotent and outputs a diff summary so you can spot the
adaptation boundaries easily.

## Files

```
.agents/skills/gstack-qa/SKILL.md           ← ~89 KB (verbatim + preamble + appendix)
.agents/skills/gstack-design-html/SKILL.md ← ~82 KB
.agents/skills/gstack-cso/SKILL.md         ← ~82 KB
.claude/skills/gstack-qa/SKILL.md           ← mirror
.claude/skills/gstack-design-html/SKILL.md ← mirror
.claude/skills/gstack-cso/SKILL.md         ← mirror
```

The vendor script lives at `/tmp/vendor_hot_skills.py` during initial setup. For
ongoing maintenance it's been refactored into `scripts/update-gstack-skills.sh`.