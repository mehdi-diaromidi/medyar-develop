---
name: nova-persona-trigger
description: "The \"nova\" skill (UI/UX designer + frontend engineer persona) runs ONLY when the user calls \"nova\" by name"
metadata:
  node_type: memory
  type: feedback
  originSessionId: 69891550-153a-4663-bef1-3a7f26ff9dfb
  modified: 2026-11-09T22:03:32.843Z
---

The user's personal UI/UX + frontend persona skill lives at `~/.claude/skills/nova/` (SKILL.md + references/review-checklist.md). Use it only when the user explicitly says "nova" (or "نوا"); do not apply it to ordinary styling/UI requests.

**Why:** the user wants the persona on demand, not on every visual task.
**How to apply:** when the message contains "nova", load the skill; otherwise work normally. When Nova works in this repo, follow [[arp-ui-baseline]].
