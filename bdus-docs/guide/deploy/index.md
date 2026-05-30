---
title: Production deployment
---

# Production deployment

## Docker Compose (recommended)

BraDypUS ships with a production Docker Compose file that uses Nginx to serve
the Vue SPA and proxy API requests to PHP — all on a single port 80.

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

## Data persistence

In Docker, `projects/` is bind-mounted from the host so application data
survives container restarts and image rebuilds. Ensure regular backups of
`projects/` — especially the SQLite files in `projects/{app}/db/`.
