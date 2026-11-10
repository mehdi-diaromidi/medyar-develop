---
name: gold-standard-new-order-page
description: "The patient new-order wizard is the user's approved visual/UX reference for every other MNS page"
metadata:
  node_type: memory
  type: feedback
  originSessionId: 69891550-153a-4663-bef1-3a7f26ff9dfb
  modified: 2026-11-10T22:33:13.824Z
---

The patient order page (`mns_page=new-order`, `public/views/patient/page-new-order.php` + the `.mns-owizard` blocks in `assets/css/public.css`) is declared **completely correct** by the user (confirmed after the 3-step rewrite, commit 00e4915).

**Why:** after many feedback rounds it matches their taste; they asked whether nova "has full mastery" and answered by naming this page as the model.

**How to apply:** when styling or reviewing any other Medyar nursing page, measure it against this page first: stepper/timeline solid track, 14/10/8 radii, one accent colour on neutral surfaces, compact rows with centred icon/text/action, start-aligned selects, fade-then-spinner loading button, soft collapses, 50/50 button rows on phones, exact-field "edit" links. Reuse its classes/tokens instead of inventing new ones. Don't regress it while editing shared CSS. See [[nova-best-uiux-means-logic]] and [[arp-ui-baseline]].
