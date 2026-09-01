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
curl -O https://raw.githubusercontent.com/lad-sapienza/BraDypUS/v5/bradypus.yml
```

Or create it manually — paste the content from
[bradypus.yml](https://github.com/lad-sapienza/BraDypUS/blob/v5/bradypus.yml).

## 2. Pull the images

```bash
docker compose -f bradypus.yml pull
```

This downloads two images:

| Image | Purpose |
|---|---|
| `ghcr.io/lad-sapienza/bdus-api` | PHP 8.4 + Apache backend |
| `ghcr.io/lad-sapienza/bdus-app` | Vue 3 SPA served by Nginx |

## 3. Start the application

```bash
docker compose -f bradypus.yml up -d
```

The application is now available at **http://localhost**.

## 4. Create your first application

With `BRADYPUS_ALLOW_NEW_APP` enabled you can use the new-app wizard.
Edit `bradypus.yml` to set it temporarily:

```yaml
environment:
  - BRADYPUS_ALLOW_NEW_APP=1
```

Then restart: `docker compose -f bradypus.yml up -d`

Follow the [Create application](/guide/create-app/) guide to complete the setup.

## Pinning a specific version

By default, `latest` is used. To pin to a specific release:

```bash
BDUS_VERSION=5.0.3 docker compose -f bradypus.yml up -d
```

## Updating

Pull the new images and restart:

```bash
docker compose -f bradypus.yml pull
docker compose -f bradypus.yml up -d
```

Data in the `projects_data` volume is never affected by updates.

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `BRADYPUS_DEBUG` | `0` | Set to `1` for debug output |
| `BRADYPUS_ALLOW_NEW_APP` | `0` | Set to `1` to enable the new-app wizard |
| `BDUS_VERSION` | `latest` | Image tag to pull (`5.0.3`, `5.0`, `5`, …) |
| `BDUS_PORT` | `80` | Host port (or `host:port` to bind to a specific interface) |

Examples:

```bash
# Run on port 8090
BDUS_PORT=8090 docker compose -f bradypus.yml up -d

# Bind to localhost only — ideal behind a reverse proxy (Apache, Nginx, Caddy)
BDUS_PORT=127.0.0.1:8090 docker compose -f bradypus.yml up -d
```

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

### Backup and restore

The repo includes two helper scripts,
[`backup.sh`](https://github.com/lad-sapienza/BraDypUS/blob/v5/backup.sh) and
[`restore.sh`](https://github.com/lad-sapienza/BraDypUS/blob/v5/restore.sh),
that wrap the `docker run` commands below. They auto-detect the `projects_data`
volume, so no configuration is needed.

Download them next to `bradypus.yml`:

```bash
curl -O https://raw.githubusercontent.com/lad-sapienza/BraDypUS/v5/backup.sh
curl -O https://raw.githubusercontent.com/lad-sapienza/BraDypUS/v5/restore.sh
chmod +x backup.sh restore.sh
```

**Backup:**

```bash
./backup.sh              # every app → backups/bradypus-all-<timestamp>.tar.gz
./backup.sh siti_scavo   # single app → backups/bradypus-siti_scavo-<timestamp>.tar.gz
```

**Restore** (prompts for confirmation — pass `-y` to skip):

```bash
./restore.sh                    # restore the latest full backup
./restore.sh siti_scavo         # restore the latest backup for one app
./restore.sh siti_scavo my.tar.gz  # restore a specific archive
```

Stop the `api` service first (`docker compose -f bradypus.yml stop api`) to
avoid restoring under a live writer.

### Demo / test data

[`seed-demo.sh`](https://github.com/lad-sapienza/BraDypUS/blob/v5/seed-demo.sh)
populates a running instance with a realistic archaeological demo dataset
(siti, complessi, saggi, US, reperti, sepolture, RS relations, geodata,
chart, …) — the same data used in CI and screenshots.

Unlike `backup.sh`/`restore.sh` it wraps `bdus-api/test.sh`, so it needs the
repository checked out (not just `bradypus.yml`):

```bash
git clone https://github.com/lad-sapienza/BraDypUS.git
cd BraDypUS

./seed-demo.sh              # creates app "bdus_demo" with the full demo dataset
./seed-demo.sh siti_scavo   # custom app name
./seed-demo.sh siti_scavo --reset   # auto-delete the app first if it already exists
```

By default it targets `http://localhost:8080`. To seed a remote testing
server, set `BASE_URL` (and admin credentials) in
`bdus-api/tests/api/vars.local.env` first — see the script's header comment.
The target server needs `BRADYPUS_ALLOW_NEW_APP=1` enabled for the
app-creation step.

#### Manual equivalent (without downloading the scripts)

```bash
# Backup
docker run --rm \
  -v projects_data:/data \
  -v "$(pwd)":/backup \
  alpine tar czf /backup/bradypus-backup.tar.gz -C /data .

# Restore
docker run --rm \
  -v projects_data:/data \
  -v "$(pwd)":/backup \
  alpine tar xzf /backup/bradypus-backup.tar.gz -C /data
```

::: tip Bind mount instead of named volume
If you prefer to keep data in a specific host directory (e.g. `./projects`),
replace the volume entry in `bradypus.yml`:

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
docker compose -f bradypus.yml down
```

Data in `projects_data` is preserved. To also delete the volume (irreversible):

```bash
docker compose -f bradypus.yml down -v
```
