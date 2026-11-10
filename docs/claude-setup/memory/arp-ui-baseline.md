---
name: arp-ui-baseline
description: Medyar nursing plugin UI must match the affiliate-referral (ARP) plugin on basic components — where the reference lives
metadata:
  node_type: memory
  type: project
  originSessionId: 69891550-153a-4663-bef1-3a7f26ff9dfb
  modified: 2026-11-09T22:03:37.224Z
---

For `medyar-nursing-services` front-end pages, the basic UI (fields, labels, buttons, cards, radii, shadows, type scale, spacing, focus/motion) must match the partner panel in `wp-content/plugins/affiliate-referral/`. Reference: `affiliate-referral/UI-UX.md` (tokens, form anatomy) and `assets/css/public.css`.

Key ARP values: field 50px min-height, 1px `--mns-border`, radius 16, label 0.875rem/400, input 0.875rem; submit button 52px/600, no shadow, hover #015a8c; cards radius 16 (form/how cards 24), no shadows; title 1.5rem/800; flat primary sidebar header. The shared `.mns-field-block` / `.mns-submit-btn` base in `assets/css/public.css` already matches ARP — page-specific blocks (e.g. the new-order wizard) should not override it with bigger/heavier values.

**Why:** the user wants both panels to feel like one product; ARP is the approved baseline.
**How to apply:** before styling an MNS page, compare against ARP tokens; prefer tokens (`--mns-*`) over hardcoded colors/sizes. The live dev site serves the deployed CSS, so local CSS edits are only visible after the FTPS deploy (don't deploy without asking).
