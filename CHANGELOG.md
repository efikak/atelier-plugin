# Quiz Atelier 1.0.0 — Stability & Release Readiness

## Added

- Administrator-only **System Status** page.
- Database, cron, REST, uploads, permalink and server requirement checks.
- Safe repair action that reruns migrations, restores the scheduler and refreshes rewrite rules without deleting content.
- Conflict-safe quiz editing using the server `updated_at` value.
- Recovery actions when another browser tab or user saved a newer version:
  - load the server version,
  - save local changes as a separate draft copy.
- Offline-aware local recovery and automatic retry after the connection returns.
- Debounced autosave four seconds after the last change instead of repeated requests every two seconds.

## Fixed

- Old cached JavaScript/CSS assets could continue loading after an update.
- The plugin Settings screen still referenced the removed 0.9.9 stylesheet.
- Public directory results did not return the correct Organization and Template IDs.
- Autosave could overwrite a newer server version without warning.
- Repeated autosave requests generated unnecessary traffic on busy editing sessions.
- Offline edits did not clearly show their state.
- Internal build labels still reported older releases.

## Preserved

- Organizations, Workspaces and seat limits.
- Private / Organization / Universal visibility.
- Workspace domain whitelist and portable embeds.
- Complete question engine, scoring, preview and analytics.
- Creator Admin restrictions and protected WordPress Administrators.
