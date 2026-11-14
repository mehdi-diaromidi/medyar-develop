---
name: always-vertically-centred
description: "House rule from the user: everything in every UI is vertically centred, always (rows, cells, buttons, chips, two-line pairs)"
metadata:
  type: feedback
---

The user stated it as a rule: "همیشه همه چیز از نظر عمودی وسط چین باشه" (always centre everything vertically). It came right after the invoices list rows had top/bottom-aligned cells and unequal grid rows.

**Why:** misaligned centre lines were the recurring visual complaint on list rows, table cells and buttons beside text.

**How to apply:** default `align-items:center` / `vertical-align:middle` everywhere; for two-line pairs in a grid use equal-height rows and hug the pair to the middle; measure centres in the browser. See [[nova-persona-trigger]] and [[gold-standard-new-order-page]].
