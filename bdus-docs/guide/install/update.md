---
title: Updating BraDypUS
---

# Updating BraDypUS

## Docker Compose (recommended)

```bash
git pull                          # update the orchestration repo
docker compose up -d --build      # rebuild images with latest code
```

After an update, database migrations wait for an administrator: see
[What happens on first login after an update](#what-happens-on-first-login-after-an-update).

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
