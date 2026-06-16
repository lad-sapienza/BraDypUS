---
title: Deploy with pre-built images (recommended)
---

# Deploy with pre-built images

This is the fastest way to run BraDypUS in production.  
No source code, no build step — Docker pulls the images directly from
[GitHub Container Registry](https://ghcr.io) and starts the application.

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine + Compose plugin on Linux)

## 1. Download the Compose file

```bash
curl -O https://raw.githubusercontent.com/lad-sapienza/BraDypUS/v5/docker-compose.hub.yml
```

Or create it manually — paste the content from
[docker-compose.hub.yml](https://github.com/lad-sapienza/BraDypUS/blob/v5/docker-compose.hub.yml).

## 2. Pull the images

```bash
docker compose -f docker-compose.hub.yml pull
```

This downloads two images:

| Image | Purpose |
|---|---|
| `ghcr.io/lad-sapienza/bdus-api` | PHP 8.2 + Apache backend |
| `ghcr.io/lad-sapienza/bdus-app` | Vue 3 SPA served by Nginx |

## 3. Start the application

```bash
docker compose -f docker-compose.hub.yml up -d
```

The application is now available at **http://localhost**.

## 4. Create your first application

With `BRADYPUS_ALLOW_NEW_APP` enabled you can use the new-app wizard.
Edit `docker-compose.hub.yml` to set it temporarily:

```yaml
environment:
  - BRADYPUS_ALLOW_NEW_APP=1
```

Then restart: `docker compose -f docker-compose.hub.yml up -d`

Follow the [Create application](/guide/create-app/) guide to complete the setup.

## Pinning a specific version

By default, `latest` is used. To pin to a specific release:

```bash
BDUS_VERSION=5.0.3 docker compose -f docker-compose.hub.yml up -d
```

## Updating

Pull the new images and restart:

```bash
docker compose -f docker-compose.hub.yml pull
docker compose -f docker-compose.hub.yml up -d
```

Data in the `projects_data` volume is never affected by updates.

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `BRADYPUS_DEBUG` | `0` | Set to `1` for debug output |
| `BRADYPUS_ALLOW_NEW_APP` | `0` | Set to `1` to enable the new-app wizard |
| `BDUS_VERSION` | `latest` | Image tag to pull (`5.0.3`, `5.0`, `5`, …) |

## Data persistence

All application data (SQLite databases, uploaded files, configuration, backups) is
stored in a Docker **named volume** called `projects_data`. Data survives container
restarts, image updates, and `docker compose down`.

### Where is the data stored?

Named volumes are managed by Docker, not stored in a predictable fixed path.
To find the exact location on disk:

```bash
docker volume inspect $(docker volume ls -q | grep projects_data)
```

Look for the `Mountpoint` field in the output, e.g.:
```
"Mountpoint": "/var/lib/docker/volumes/bradypus_projects_data/_data"
```

On **Docker Desktop** (Mac / Windows) volumes live inside the Docker VM and are not
directly accessible from the host filesystem — use the commands below instead.

### Listing files in the volume

```bash
docker run --rm -v projects_data:/data alpine ls /data
```

### Backup

```bash
docker run --rm \
  -v projects_data:/data \
  -v "$(pwd)":/backup \
  alpine tar czf /backup/bradypus-backup.tar.gz -C /data .
```

This creates `bradypus-backup.tar.gz` in the current directory.

### Restore

```bash
docker run --rm \
  -v projects_data:/data \
  -v "$(pwd)":/backup \
  alpine tar xzf /backup/bradypus-backup.tar.gz -C /data
```

::: tip Bind mount instead of named volume
If you prefer to keep data in a specific host directory (e.g. `./projects`),
replace the volume entry in `docker-compose.hub.yml`:

```yaml
# instead of:
volumes:
  - projects_data:/var/www/html/projects

# use:
volumes:
  - ./projects:/var/www/html/projects
```

Then remove the `projects_data:` entry from the top-level `volumes:` section.
:::

## Stopping

```bash
docker compose -f docker-compose.hub.yml down
```

Data in `projects_data` is preserved. To also delete the volume (irreversible):

```bash
docker compose -f docker-compose.hub.yml down -v
```
