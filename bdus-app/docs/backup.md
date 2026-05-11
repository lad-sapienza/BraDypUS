# Backup module — v5 documentation

## UI spec

Accessible via the **Backup** nav item (super-admin only).

`BackupView.vue` presents a single-page panel with:

- **Create backup** button — triggers a dump of the current database; shows a progress/loading state.
- **Backup list** — table of existing backup files with columns: date, engine, size, actions.
- **Per-row actions**: Download (saves file to browser), Restore (with confirmation dialog), Delete (with confirmation dialog).
- Toast notifications for all operations (success / error).

---

## API endpoints (`backup_ctrl`)

All endpoints require `super_admin` privilege.

| Method | HTTP | Params | Response |
|---|---|---|---|
| `listBackups` | GET | — | `{ status, backups: [{ filename, date, engine, size, size_human }] }` |
| `doBackup` | GET | — | `{ status, code, filename? }` |
| `downloadBackup` | GET | `file` | Raw file download (sets `Content-Disposition` header); on error: `{ status, code }` |
| `deleteBackup` | GET | `file` | `{ status, code }` |
| `restoreBackup` | GET | `file` | `{ status, code }` |

### Backup filename convention

`{prefix}_{Y-m-d_H-i-s}_{engine}.sql` (or `.dump` for PostgreSQL).

Parsed by the private `parseFileName()` helper into `{ prefix, date, engine }`.

### Engine behaviour

| Engine | Dump tool | Notes |
|---|---|---|
| SQLite | PHP/PDO native (`dumpSqliteNative`) | No external binary required |
| MySQL | `mysqldump` CLI | Must be in `$PATH` |
| PostgreSQL | `pg_dump` CLI | Must be in `$PATH` |

Backup files are stored in `projects/{app}/backups/`.

---

## Legacy → v5 mapping

The entire `backup_ctrl` was rewritten for v5 — there are no legacy v4 methods. The v4 backup UI was an HTML form inside a Twig template; it is fully replaced by `BackupView.vue`.

---

## Future improvements

- **Scheduled backups**: cron-style automatic daily/weekly dumps.
- **Remote storage**: upload backups to S3-compatible storage or SFTP.
- **Backup diff**: show what changed between two backup points.
- **Compression**: gzip backup files to reduce disk usage.
