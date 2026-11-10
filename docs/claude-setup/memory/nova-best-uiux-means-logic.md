---
name: nova-best-uiux-means-logic
description: "When the user asks for \"the best UI/UX\" / \"think\", they mean rethinking screen logic (defaults, progressive disclosure), shown as a mockup first — not restyling"
metadata:
  node_type: memory
  type: feedback
  originSessionId: 69891550-153a-4663-bef1-3a7f26ff9dfb
  modified: 2026-11-10T19:19:38.893Z
---

The user loved the answer to "can these patient cards be better?" where the real fix was logic, not looks: collapse the already-known answer (preselected "self" + prefilled phone) into a one-line summary with a "change" link, and show it as a before/after mockup before coding.

They also stated (2026-11-10) that soft, fitting animations matter to them: open/close of any form or panel must animate both ways (e.g. the patient "change" area expands in 420ms and collapses in 280ms). They merged the order wizard's patient and place/time steps into one (4 → 3 steps) on their own initiative.

**Why:** they want UX that makes the majority path effortless and justified by UX principles, not cosmetic polish; they also want to see a visual before anything is built.
**How to apply:** when Nova is called (see [[nova-persona-trigger]]), lead with logic over looks, prove findings on the live page, show a `visualize` mockup, offer light vs full variants, flag conflicts with client-approved design, then ask which to build. The method and worked example live in `~/.claude/skills/nova/SKILL.md` and `references/examples.md`. Baseline styling follows [[arp-ui-baseline]].
