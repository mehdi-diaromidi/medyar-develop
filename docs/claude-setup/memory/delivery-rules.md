---
name: delivery-rules
description: "How the user wants changes delivered in this repo: no commit/push unless told, FTP via deploy.ps1 when asked, CDN caches assets for a year"
metadata:
  node_type: memory
  type: feedback
  originSessionId: 69891550-153a-4663-bef1-3a7f26ff9dfb
  modified: 2026-11-10T20:39:29.620Z
---

Do not commit or `git push` unless the user says so (they said "تا نگفتم پوش نکن" on 2026-11-10 after an unrequested push to fix caching). When they say "FTP only" / "فقط از طریق ftp", run `.\deploy.ps1 <relative paths>` from the repo root for exactly the changed files (it reads `.ftp.env`; never read or print credentials), then verify on https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=new-order.

**Why:** the user controls what reaches their repo and server; they also want to see changes on the dev site quickly.
**How to apply:** make the change, upload by FTP only if asked (or after they said FTP is the standing method), test on the live page with a plain URL (no forced cache reload), leave the tree uncommitted. Assets are versioned by file mtime (`MNS_Assets::asset_ver`) because the host sends `max-age=31536000`; an FTP upload changes mtime, so the URL changes automatically. Related: [[nova-persona-trigger]], [[arp-ui-baseline]].
