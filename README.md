# BraDypUS

**Open-source web database system for archaeological and cultural-heritage research.**

Developed at [LAD – Laboratorio di Archeologia Digitale, Sapienza University of Rome](https://lad.saras.uniroma1.it) by [Julian Bogdani](https://orcid.org/0000-0001-5250-927X).

License: [GNU AGPL-3.0](bdus-api/LICENSE) · Docs: [docs.bdus.cloud](https://docs.bdus.cloud) · Cloud: [bdus.cloud](https://bdus.cloud) · [![DOI](https://zenodo.org/badge/18011343.svg)](https://zenodo.org/badge/latestdoi/18011343)

---

## Repository layout

This monorepo contains two independent projects that work together:

| Directory | Language / Stack | Purpose |
|---|---|---|
| [`bdus-api/`](bdus-api/) | PHP 8.2 · Apache | REST JSON backend, multi-tenant |
| [`bdus-app/`](bdus-app/) | Vue 3 · Vite · PrimeVue 4 | Browser SPA |

Each sub-directory has its own `README.md`, `Dockerfile`, and `docker-compose.yml`
for standalone use. This root directory holds the **combined compose files** for
running both services together.

---

## Deployment scenarios

| Scenario | Who it's for | What you need |
|---|---|---|
| [A – Development](#a--development) | Contributors / active development | Git · Docker |
| [B – Production from source](#b--production-from-source) | Self-hosters who cloned the repo | Git · Docker |
| [C – Production from pre-built images](#c--production-from-pre-built-images) | Self-hosters (no source needed) | Docker only |
| [D – Manual (no Docker)](#d--manual-no-docker) | Custom servers / shared hosting | PHP 8.2+ · Node 20+ |

---

## A – Development

Hot-reloading Vite frontend + PHP backend mounted from source.

```bash
git clone https://github.com/lad-sapienza/bdus-api.git
git clone https://github.com/lad-sapienza/bdus-app.git

# Start both services
docker compose up
```

| Service | URL |
|---|---|
| Vue UI (Vite dev server) | <http://localhost:5173> |
| PHP API | <http://localhost:8080> |

The PHP source tree is bind-mounted, so edits take effect without rebuilding.  
Vite proxies `/api/`, `/index.php`, `/projects/`, `/cache/` to the PHP container —
no CORS configuration needed.

To enable the new-application wizard (required for the test suite) add
`BRADYPUS_ALLOW_NEW_APP=1` to the `environment:` block of `docker-compose.yml`.

---

## B – Production from source

Pre-built images: Nginx serves the compiled Vue SPA and proxies backend calls.  
Everything is on **port 80**; data is persisted in a named Docker volume.

```bash
git clone https://github.com/lad-sapienza/bdus-api.git
git clone https://github.com/lad-sapienza/bdus-app.git

docker compose -f docker-compose.prod.yml up -d --build
```

Open **<http://localhost>** in your browser.

To expose on a different port, change `"80:80"` in `docker-compose.prod.yml`.  
Project data (files, databases, backups) lives in the `projects_data` Docker volume
and survives rebuilds and container restarts.

### Running behind a reverse proxy (Apache / Nginx / Caddy)

Expose only the container port and configure your reverse proxy to forward traffic:

```nginx
# Nginx example
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

---

## C – Production from pre-built images

No source code or build tools required. Images are published to the **GitHub
Container Registry** (`ghcr.io/lad-sapienza/bdus-api` and `ghcr.io/lad-sapienza/bdus-app`)
by CI on every tagged release. Download the ready-to-use compose file and run it:

```bash
curl -O https://raw.githubusercontent.com/lad-sapienza/BraDypUS/v5/bradypus.yml
docker compose -f bradypus.yml pull
docker compose -f bradypus.yml up -d
```

Open **<http://localhost>** in your browser.

Pin to a specific version by setting `BDUS_VERSION` (default: `latest`):

```bash
BDUS_VERSION=5.0.3 docker compose -f bradypus.yml up -d
```

Change the host port with `BDUS_PORT` (default: `80`) — useful behind a reverse
proxy, or bind to localhost only:

```bash
BDUS_PORT=8090 docker compose -f bradypus.yml up -d
BDUS_PORT=127.0.0.1:8090 docker compose -f bradypus.yml up -d
```

Project data (files, databases, backups) is persisted in the `projects_data`
Docker volume and survives restarts and upgrades. To upgrade:

```bash
docker compose -f bradypus.yml pull
docker compose -f bradypus.yml up -d
```

Docker automatically restarts containers after a reboot (`restart: unless-stopped`).
Full details (data backup/restore, reverse proxy setup) are in the
[deployment guide](https://docs.bdus.cloud/guide/install/containers.html).

---

## D – Manual (no Docker)

### Backend (bdus-api)

```bash
git clone https://github.com/lad-sapienza/bdus-api.git
cd bdus-api
composer install
```

Point your web server document root at the repository root.

**Apache** — the included `.htaccess` handles URL rewriting automatically.

**Nginx** — add to your server block:
```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

Required PHP extensions: `pdo`, `pdo_sqlite` (or `pdo_mysql` / `pdo_pgsql`),
`mbstring`, `gd`.

Set environment variables (via `.env`, `SetEnv` in Apache, or `fastcgi_param` in
Nginx) as needed — see [bdus-api README](bdus-api/README.md#environment-variables).

### Frontend (bdus-app)

```bash
git clone https://github.com/lad-sapienza/bdus-app.git
cd bdus-app
npm install
```

If the frontend is served from the **same origin** as the backend, leave
`VITE_API_BASE` empty and make sure `/api/`, `/index.php`, `/projects/`, and
`/cache/` are routed to the PHP backend.

If the frontend is on a **different origin** (e.g. GitHub Pages), set
`VITE_API_BASE` to the full backend URL **before building**, and enable CORS on
the backend with `BRADYPUS_CORS_ORIGIN`:

```bash
VITE_API_BASE=https://api.example.com npm run build
```

The compiled output is in `dist/`. Copy it to any static file server and add a
SPA fallback rule (see the Nginx snippet in scenario B above).

---

## Environment variables

See [`bdus-api/README.md`](bdus-api/README.md#environment-variables) for the
full list of PHP backend variables and [`bdus-app/README.md`](bdus-app/README.md#environment-variables) for frontend variables.

---

## License

GNU Affero General Public License v3.0 — see [bdus-api/LICENSE](bdus-api/LICENSE).
