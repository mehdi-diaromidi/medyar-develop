# RUN-D DONE

## Cards
- **T-D.1** — E2E sandbox admin tool shipped; supervisor seeded links via `.e2e/links.json`. Report: `reports/T-D.1.md`.
- **T-D.2** — Browser scenarios executed (desktop + 375px). Report: `reports/T-D.2.md`. Bugs: `reports/RUN-D-BUGS.md`.

## Highlight outcomes
- Patient order **19207** created (idempotent), OTP lock OK, cancelled once then Persian `ERR_BAD_STATE` on second cancel.
- nurse_f schedule/regions editable; nurse_m cannot see foreign order (404 / `ERR_NOT_FOUND`).
- A-13 green/red path verified earlier in the run.

## E2E cleanup
**Not executed by Cursor.** WAF returns Forbidden on `tools.php?page=mns-e2e` from the automation browser (B-D.4).

**Supervisor action required:** open `tools.php?page=mns-e2e` in a normal admin session → «پاک‌سازی E2E» (users `mns_e2e_*`, order 19207 and related rows, clear `mns_e2e_enabled_until`).

## Commit / push
None (per brief).

## Stop
RUN-D complete pending supervisor purge confirmation.
