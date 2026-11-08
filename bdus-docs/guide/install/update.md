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
  `needs-major` or `failed: <reason>`. An app that fails does not stop the others,
  and the failure is also written to that app's own log.
- It never runs the **v4 → v5** upgrade: an app that needs it is reported as
  `needs-major` and is upgraded by its super administrator at login, with the
  snapshot and the verification of that flow.
- Exit code: `0` all fine, `1` at least one app failed, `3` at least one app needs
  the major upgrade.

If you deploy with the *bdus-ops* toolbox, `bdus update` does this after each
instance is healthy again, and `bdus app upgrade <instance>` does it on demand.

