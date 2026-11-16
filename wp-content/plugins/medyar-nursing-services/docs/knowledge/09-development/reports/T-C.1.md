# Report T-C.1 — Self-test leftover purge

## Files
- `includes/class-mns-selftest.php` (`purge_leftovers`, `purge_user_rows`, `count_leftovers`, AC-TC-1 row)
- `includes/class-mns-selftest-scenarios.php` (`cleanup_users` → `purge_user_rows`)

## Notes
`run()` purges before scenarios and again after; final row reports `leftover=0`.

## Live check
Deferred to batched deploy at end of RUN-C (same health URLs).
