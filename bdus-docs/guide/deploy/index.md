---
title: Production deployment
---

# Production deployment

## Pre-built images from GHCR (recommended)

The fastest deployment path: no source code required.
Images are published automatically on every release to
[GitHub Container Registry](https://ghcr.io/lad-sapienza).

```bash
curl -O https://raw.githubusercontent.com/lad-sapienza/BraDypUS/v5/bradypus.yml
docker compose -f bradypus.yml pull
docker compose -f bradypus.yml up -d
```

To pin a specific version:

```bash
BDUS_VERSION=5.0.3 docker compose -f bradypus.yml up -d
```

See [Deploy with pre-built images](/guide/install/containers) for the full guide.

## Build from source

If you have cloned the repository and want to build the images locally:

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

In production, the `node` container (Vite dev server) is replaced by a static
Nginx container serving the pre-built `dist/` files.

### Environment variables

Set these in `docker-compose.prod.yml` or via a `.env` file:

| Variable | Default | Description |
|---|---|---|
| `BRADYPUS_DEBUG` | `0` | Set to `1` only for debugging |
| `BRADYPUS_ALLOW_NEW_APP` | `0` | Set to `1` temporarily to create the first app |
| `BRADYPUS_CORS_ORIGIN` | — | Space-separated allowed origins for cross-origin API access |

## Shared hosting (PHP-only)

For hosts that support PHP but not Docker, follow the
[Manual installation](/guide/install/manual-download) guide and upload the files via
SFTP/SSH.

::: tip SQLite on shared hosting
SQLite databases are stored as files in `projects/{app}/db/`. They can be
downloaded and uploaded like any other file, making backups and migrations trivial.
:::

## HTTPS

Always run BraDypUS behind HTTPS in production. JWT tokens are transmitted in
headers — without HTTPS they are exposed in transit.

When using a reverse proxy (Nginx, Caddy, Traefik), set the `X-Forwarded-Proto`
header so BraDypUS knows the connection is over HTTPS.

```nginx
# Nginx example — proxy to a BraDypUS container exposing port 80
server {
    server_name myapp.example.com;

    location / {
        proxy_pass         http://127.0.0.1:80;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}
```

## Data persistence

All application data (SQLite databases, uploaded files, configuration, backups) lives
in the `projects/` directory inside the `bdus-api` container. Both Compose files map
this directory to a persistent Docker **named volume** called `projects_data`, so data
survives container restarts and image updates.

To find where Docker stores the volume on disk:

```bash
docker volume inspect $(docker volume ls -q | grep projects_data)
```

For backup and restore commands, see the
[Data persistence](/guide/install/containers#data-persistence) section of the
pre-built images guide.

::: warning
On Docker Desktop (Mac / Windows) the volume lives inside the Docker VM and is not
directly accessible from the host filesystem. Use `docker run --rm -v projects_data:/data alpine ...`
to read or write files inside the volume.
:::
