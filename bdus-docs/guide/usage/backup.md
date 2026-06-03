---
title: Database backup
---

# Database backup

The **Backup** module creates and manages snapshots of your database.
Admin privilege is required to access it.

Open **Backup** from the sidebar.

![TODO_SCREENSHOT: BackupView showing a list of existing backups with date, size, and action buttons](/images/v5/usage/backup-list.png)

## Creating a backup

Click **Create backup**. A timestamped dump is created and stored in
`projects/{app}/backups/`. The filename includes the date, time, and database
engine: `{prefix}_{YYYY-MM-DD_HH-MM-SS}_{engine}.sql`.

| Engine | How the dump is created | External tool required |
|---|---|---|
| SQLite | Native PHP/PDO — no CLI tools needed | No |
| MySQL | `mysqldump` | Yes — must be in `$PATH` |
| PostgreSQL | `pg_dump` | Yes — must be in `$PATH` |

## Downloading a backup

Click the **Download** icon next to a backup to save it to your computer.

## Restoring a backup

::: warning Super-admin required
Restore requires super-admin privilege.
:::

Click **Restore** next to a backup. A confirmation dialog warns you that the
current database will be **completely replaced** by the backup contents.

After confirmation, the restore runs and you are redirected to the login page.
Log in again to use the restored application.

## Deleting a backup

Click **Delete** next to a backup to permanently remove it from disk.

## Backup retention

BraDypUS does not automatically delete old backups. Manage disk usage by
periodically deleting older snapshots or downloading and archiving them externally.

## Recommended practice

- Create a backup **before any structural change** (adding/removing tables or fields).
- Create a backup **before a bulk import or find-and-replace** operation.
- Download and store backups externally on a regular schedule.
