# Design Brief — CRM Contact Center

## 1. Product purpose

A modular contact-center CRM where every Zalo/Telegram customer message becomes a tracked conversation, an assignable support task, and linked CRM lead/deal work in one operator cockpit.

## 2. Primary user

A support lead or agent working an admin cockpit on a desktop 1440px+ screen, handling many live conversations across channels while monitoring queue health, assignment, and provider status throughout the shift.

## 3. Principles

In conflict-resolution order — when two apply to the same decision, the higher one wins.

1. **Status over decoration.** Color is a signal, not a mood — reserved for channel / SLA / assignment / delivery state. Neutral everywhere else. (Resolves: no accent-tinted cards "for polish"; a red badge always means something broke.)
2. **The queue is the product.** Tables and work rows dominate; charts shrink to sparklines. The operator acts on rows, not on dashboards. (Resolves: work queue takes 60%+ of viewport height; no large hero chart stealing row space.)
3. **Every operational state is visible without opening logs.** Failed jobs, token expiry, SLA breach, unassigned conversations surface on-screen. If it can break in production, it has a badge. (Resolves: cockpit shows failure counts inline, not "check Horizon".)
4. **Traceable over silent.** Every owner change, retry, close, and config edit shows who and when. No action happens invisibly. (Resolves: assignment history panel over silent owner swap.)
5. **shadcn primitives over custom markup.** The component library is the design system. Consistency beats bespoke. (Resolves: Badge over hand-built pill; ties every screen together.)

## 4. Success metric

An operator lands on Overview and identifies which channel, queue, agent, or SLA is in trouble within 30 seconds — and reaches the fix action (retry / replay / refresh / reassign) in one click from that screen.

## 5. Out of scope

- Does not serve as a marketing / landing surface — app-first only.
- Does not expose raw provider payloads in normal views (admin-only, behind an "unsupported" affordance).
- Does not render full secrets / tokens after save — masked value + last-updated timestamp only.
- Does not build bespoke replacements for existing shadcn primitives.
- Does not target mobile-first; desktop cockpit is primary (responsive collapse, not mobile-optimized).

---

_Governs UI decisions across all screens in `specs/07_ADMIN_UI.md` and `specs/04_OMNICHANNEL_INBOX.md`. Cite the principle a decision applies to. If a decision isn't covered, the brief is incomplete — surface the gap._
