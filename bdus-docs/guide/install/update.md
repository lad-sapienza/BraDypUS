---
title: Updating BraDypUS
---

# Updating BraDypUS

## Docker Compose (recommended)

```bash
git pull                          # update the orchestration repo
docker compose up -d --build      # rebuild images with latest code
```

After an update, database migrations wait for an administrator, unless you apply
them yourself: see [Upgrade the apps without logging in](#upgrade-the-apps-without-logging-in)
and [What happens on first login after an update](#what-happens-on-first-login-after-an-update).

## Manual installation

```bash
# In bdus-api/
git pull
composer install --no-dev

# In bdus-app/
git pull
npm install
npm run build
```

After updating, copy the new `dist/` contents to your web server.

::: warning Backup first
Always [create a backup](/guide/usage/backup) before updating to protect
against unexpected migration issues.
:::

## What happens on first login after an update

An update can include database migrations (new system tables, columns, indexes).
They are **not** applied silently:

- When an **administrator** logs in and migrations are pending, the app signs
  them in and offers the upgrade; the migrations run when the administrator
  confirms it.
- Any other user who logs in meanwhile is refused with an *upgrade pending*
  message, until an administrator has run the upgrade.

Migrations are idempotent — safe to run more than once. The migration history
is visible in the admin-only **Migrations** view.

## Upgrade the apps without logging in <Badge type="tip" text="v5.21.0" />

If you run the server, you do not have to wait for an administrator of each app.
This applies the pending migrations of every application (or one, with
`--app <slug>`) from the command line:

```bash
docker compose exec -u www-data api php bin/upgrade-app.php --all --dry-run   # what is pending
docker compose exec -u www-data api php bin/upgrade-app.php --all             # apply it
```

- It runs as `www-data`, so the files it writes stay usable by the web server.
- It does the same as the administrator's confirmation, and nothing else. It
  does **not** take a backup: [make one first](/guide/usage/backup).
- One line per app: `up-to-date`, `upgraded (n)`, `pending (n)` (dry run),
  `needs-major` or `failed: <reason>`. After a release **without** migrations an
  app still carries the previous version: it is listed as `pending (0)` in a dry
  run and `upgraded (0)` in a real one, which records the new version and clears
  the "update available" tag on the login page. An app that fails does not stop the others,
  and the failure is also written to that app's own log.
- It never runs the **v4 → v5** upgrade: an app that needs it is reported as
  `needs-major` and is upgraded by its super administrator at login, with the
  snapshot and the verification of that flow.
- Exit code: `0` all fine, `1` at least one app failed, `3` at least one app needs
  the major upgrade.

If you deploy with the *bdus-ops* toolbox, `bdus update` does this after each
instance is healthy again, and `bdus app upgrade <instance>` does it on demand.

## Upgrading to uuid file addresses <Badge type="tip" text="v5.30.0" />

This release gives every file a random **uuid** and **renames the files on disk** from
`projects/{app}/files/{id}.{ext}` to `projects/{app}/files/{uuid}.{ext}` (migration M060); the cache of resized
images is emptied and rebuilt on demand, and existing presets and files are made *public* so nothing already
published disappears (M062). **Take a backup first.** The migration can be repeated: if it stops (a rename that
fails for permissions), fix the cause and run it again; it only does what is missing and never overwrites.

**Breaking: the `files/` folder is no longer served by the web server.** The old address
`projects/{app}/files/…` (the 5.29.4 hotfix left only `files/` and `geodata/` open) now answers 404; files go
through the authenticated API. Links to it from other sites stop working, and if you have your own proxy in
front of BraDypUS that forwards `/projects/`, restrict it to `/projects/{app}/geodata/` too.

Two read/repair commands help around it (run them as the web user, one application at a time):

```bash
# what is missing, without changing anything
docker compose exec -u www-data api php bin/files-backfill-uuid.php --app paths --dry-run

# binaries in files/ that no database row accounts for (read-only; nothing is deleted)
docker compose exec -u www-data api php bin/files-backfill-uuid.php --app paths --strays

# links of the OLD kind typed into your data (projects/{app}/files/42.jpg, /api/file/thumb/42.webp):
# they no longer work; this lists where they are (read-only) so you can replace them
docker compose exec -u www-data api php bin/files-backfill-uuid.php --app paths --scan-urls

# give a uuid (and a place on disk) to files that reached the database without one,
# for instance rows inserted by a tool that bypasses the application
docker compose exec -u www-data api php bin/files-backfill-uuid.php --app paths
```

Anything outside BraDypUS that links to the old addresses (another website, a document) must be updated to the
new ones: take the `uuid` from the API (`GET /api/files`, or `files.uuid` in a record).
