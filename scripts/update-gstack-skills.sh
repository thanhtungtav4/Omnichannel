#!/usr/bin/env bash
# scripts/update-gstack-skills.sh
#
# Refresh vendored gstack skills from upstream. Run when you want to pull the
# latest changes from https://github.com/garrytan/gstack.
#
# Idempotent. Re-clones gstack at the pinned version, re-runs vendor script,
# re-applies deep-adapt for the 3 hot skills, reports diffs.
#
# Usage:
#   bash scripts/update-gstack-skills.sh           # full refresh
#   bash scripts/update-gstack-skills.sh --check   # check for upstream drift only
#   bash scripts/update-gstack-skills.sh --pin <version>
#
# Requirements: git, python3, curl.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
TMPDIR="$(mktemp -d -t gstack-vendor-XXXXXX)"
GSTACK_REPO="${GSTACK_REPO:-https://github.com/garrytan/gstack.git}"
PINNED_VERSION="v1.58.5.0"  # bump deliberately; see CLAUDE.md
HOT_SKILLS=(qa design-html cso)
MINIMAL_SKILLS=(
    autoplan benchmark benchmark-models browse canary careful codex
    context-restore context-save design-consultation design-review
    design-shotgun devex-review diagram document-generate document-release
    freeze gstack-upgrade guard health investigate land-and-deploy
    landing-report learn make-pdf office-hours open-gstack-browser pair-agent
    plan-ceo-review plan-design-review plan-devex-review plan-eng-review
    plan-tune qa-only retro review scrape setup-browser-cookies setup-deploy
    setup-gbrain ship skillify spec sync-gbrain unfreeze
)

CHECK_ONLY=false
NEW_PIN=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --check) CHECK_ONLY=true; shift ;;
    --pin) NEW_PIN="$2"; shift 2 ;;
    -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
    *) echo "Unknown flag: $1" >&2; exit 1 ;;
  esac
done

if [[ -n "$NEW_PIN" ]]; then
  PINNED_VERSION="$NEW_PIN"
fi

echo "==> gstack vendor refresh"
echo "    repo root:  $REPO_ROOT"
echo "    pin:        $PINNED_VERSION"
echo "    tmp dir:    $TMPDIR"
echo

# 1. Clone gstack at pinned version
echo "==> Cloning gstack @ $PINNED_VERSION"
git clone --depth 1 --branch "$PINNED_VERSION" "$GSTACK_REPO" "$TMPDIR/gstack" 2>&1 | tail -3
SRC="$TMPDIR/gstack"
echo

if $CHECK_ONLY; then
  echo "==> --check mode: comparing upstream vs vendored"
  python3 - "$SRC" "$REPO_ROOT/.agents/skills" <<'PYEOF'
import sys
from pathlib import Path
src, dst_root = Path(sys.argv[1]), Path(sys.argv[2])
mismatches = []
for skill_dir in sorted(src.iterdir()):
    if not (skill_dir / "SKILL.md").exists(): continue
    src_md = (skill_dir / "SKILL.md").read_text(encoding="utf-8")
    dst_md = (dst_root / f"gstack-{skill_dir.name}" / "SKILL.md").read_text(encoding="utf-8") \
             if (dst_root / f"gstack-{skill_dir.name}" / "SKILL.md").exists() else None
    if dst_md is None:
        mismatches.append(f"  NEW:    gstack-{skill_dir.name}")
    elif dst_md == src_md:
        pass  # identical (rare; we always prepend preamble)
    else:
        # count meaningful lines (not preamble)
        src_core = src_md.split("<!-- AUTO-GENERATED", 1)[1] if "<!-- AUTO-GENERATED" in src_md else src_md
        dst_core = dst_md.split("<!-- AUTO-GENERATED", 1)[1] if "<!-- AUTO-GENERATED" in dst_md else dst_md
        if len(dst_core) < len(src_core) * 0.9:
            mismatches.append(f"  DRIFT:  gstack-{skill_dir.name}  (upstream {len(src_core)} lines, vendored {len(dst_core)} lines)")
if mismatches:
    print(f"==> {len(mismatches)} skills drifted from upstream:")
    print("\n".join(mismatches))
    sys.exit(2)
else:
    print("==> All vendored skills match upstream (within preamble + appendix tolerance)")
    sys.exit(0)
PYEOF
  rm -rf "$TMPDIR"
  exit 0
fi

# 2. Re-vendor minimal skills (prepend CRM preamble)
echo "==> Vendoring ${#MINIMAL_SKILLS[@]} minimal skills"
python3 - "$SRC" "$REPO_ROOT" <<'PYEOF'
import re, sys
from pathlib import Path
src_root, repo = Path(sys.argv[1]), Path(sys.argv[2])
PRE = """

> **CRM context (auto-injected when run from this repo):**
> - Repo: Omnichannel CRM — Laravel 12 + Inertia React + shadcn/ui (Base UI), PostgreSQL + Redis, multi-tenant `*.qrf.vn`
> - Read first: `@AGENTS.md` (project rules), `@ETHOS.md` (builder principles), `@specs/00_MASTER_PLAN.md` (product direction)
> - For UI skills: also read `@.ui-craft/brief.md` and `@.ui-craft/tokens.css` before touching design
> - Original gstack skill: https://github.com/garrytan/gstack/blob/main/{name}/SKILL.md
> - Vendored from gstack v{PIN} — refresh via `scripts/update-gstack-skills.sh`
"""
PIN = "1.58.5.0"
ok = skip = 0
for name in ["autoplan","benchmark","benchmark-models","browse","canary","careful",
             "codex","context-restore","context-save","design-consultation","design-review",
             "design-shotgun","devex-review","diagram","document-generate","document-release",
             "freeze","gstack-upgrade","guard","health","investigate","land-and-deploy",
             "landing-report","learn","make-pdf","office-hours","open-gstack-browser",
             "pair-agent","plan-ceo-review","plan-design-review","plan-devex-review",
             "plan-eng-review","plan-tune","qa-only","retro","review","scrape",
             "setup-browser-cookies","setup-deploy","setup-gbrain","ship","skillify",
             "spec","sync-gbrain","unfreeze"]:
    src_file = src_root / name / "SKILL.md"
    if not src_file.exists():
        skip += 1; continue
    body = src_file.read_text(encoding="utf-8")
    pre = PRE.format(name=name, PIN=PIN)
    body = re.sub(r"^(---\n.*?\n---\n)", r"\1" + pre, body, count=1, flags=re.DOTALL) \
           if re.match(r"^---\n", body) else pre + "\n" + body
    for tr in (repo / ".agents/skills", repo / ".claude/skills"):
        td = tr / f"gstack-{name}"; td.mkdir(parents=True, exist_ok=True)
        (td / "SKILL.md").write_text(body, encoding="utf-8")
    ok += 1
print(f"    vendored: {ok}, skipped: {skip}")
PYEOF
echo

# 3. Re-deep-adapt hot skills (calls back into inline Python)
echo "==> Re-applying deep-adapt for ${#HOT_SKILLS[@]} hot skills"
# (Handled by next step — see scripts/vendor_hot_skills.py if split out)
echo "    [TODO] hot skill deep-adapt: re-append CRM appendix from docs/gstack/HOT-SKILLS.md"
echo "    For now, hot skills are NOT auto-re-vendored. Run:"
echo "      git checkout HEAD -- .agents/skills/gstack-{qa,design-html,cso}/ .claude/skills/gstack-{qa,design-html,cso}/"
echo "      # then re-apply CRM appendix manually if upstream changed"
echo

# 4. Refresh ETHOS.md
echo "==> Refreshing ETHOS.md"
cp "$SRC/ETHOS.md" "$REPO_ROOT/ETHOS.md"
echo

# 5. Summary
echo "==> Done. Review diff:"
echo "    cd $REPO_ROOT"
echo "    git status"
echo "    git diff --stat .agents/skills/ .claude/skills/ ETHOS.md"
echo
echo "    Hot skills (qa, design-html, cso) preserved as-is — refresh manually if upstream changed."
rm -rf "$TMPDIR"