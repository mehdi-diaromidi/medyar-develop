# NEED-LINKS — RUN-D

Patient one-time login was consumed; browser session was lost after a chrome crash mid T-D.2 (steps 3–4: orders list/detail/cancel UI).

Please re-open `tools.php?page=mns-e2e` → regenerate one-time links into `.e2e/links.json` as:

```json
{"patient":"url","nurse_f":"url","nurse_m":"url"}
```

Notes:
- `nurse_f` and `nurse_m` from the previous file were already used successfully.
- Prefer fresh links for all three so remaining patient UI steps and any re-checks can finish.
- WAF currently returns «Upstream Error - Forbidden» on `/wp-admin/tools.php?page=mns-e2e` from the automation browser; supervisor may need to open the page from a normal admin session.
