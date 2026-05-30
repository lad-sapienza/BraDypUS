---
title: Updating BraDypUS
---

# Updating BraDypUS

## Docker Compose (recommended)

```bash
git pull                          # update the orchestration repo
docker compose up -d --build      # rebuild images with latest code
```

DB migrations run automatically on the first login after an update.

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

BraDypUS runs any pending DB migrations automatically when a user logs in.
Migrations are idempotent — safe to run multiple times. The migration history
is visible in the admin-only **Migrations** view.
