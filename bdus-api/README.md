# bdus-api — BraDypUS PHP backend

**Part of [BraDypUS](https://bdus.cloud)** — the open-source web database system for archaeological and cultural-heritage research.

Developed at [LAD – Laboratorio di Archeologia Digitale, Sapienza University of Rome](https://lad.saras.uniroma1.it) by [Julian Bogdani](https://orcid.org/0000-0001-5250-927X).

License: [GNU AGPL-3.0](LICENSE) · Docs: [docs.bdus.cloud](https://docs.bdus.cloud) · Cloud: [bdus.cloud](https://bdus.cloud) · [![DOI](https://zenodo.org/badge/18011343.svg)](https://zenodo.org/badge/latestdoi/18011343)

> This repository contains the **PHP/Apache backend** only.  
> The Vue frontend lives in **[lad-sapienza/bdus-app](https://github.com/lad-sapienza/bdus-app)**.

---

## What it does

bdus-api exposes a REST JSON API that the Vue frontend (bdus-app) consumes. It handles:

- User authentication (stateless JWT; per-tab, multi-app)
- API key authentication with per-key privilege levels (read / edit / admin)
- Multi-tenant application management (one SQLite / MySQL / PostgreSQL database per project)
- CRUD operations on records, with a flexible field-type system
- Search (simple, advanced, free SQL, ShortSQL DSL)
- File uploads and image management
- Geodata (GeoJSON read/write)
- Charts and saved queries
- Configuration management
- Schema migrations (auto-applied at login; self-healing pre-flight for legacy databases)

---

## Requirements

| Tool | Minimum |
|---|---|
| PHP | 8.2 |
| PHP extensions | `pdo`, `pdo_sqlite` (or `pdo_mysql` / `pdo_pgsql`), `mbstring`, `gd` |
| Composer | 2.x |
| Web server | Apache with `mod_rewrite`, or Nginx |

---

## Quick start with Docker (recommended)

**Only Docker + Docker Compose needed on the host.**

### Backend only

```bash
git clone https://github.com/lad-sapienza/bdus-api.git
cd bdus-api
docker compose up
```

The API is now available at **http://localhost:8080**.  
On first start Composer dependencies are installed automatically inside the container.

To create your first application, add `BRADYPUS_ALLOW_NEW_APP=1` to the
`environment:` section of `docker-compose.yml` (or to a `.env` file), then visit
**http://localhost:5173** from the running frontend.

### Full stack (backend + frontend together)

Clone both repositories side by side and use the parent compose file:

```bash
mkdir BraDypUS && cd BraDypUS
git clone https://github.com/lad-sapienza/bdus-api.git
git clone https://github.com/lad-sapienza/bdus-app.git

# Write the unified compose (see bdus-app README for full content)
# then:
docker compose up
```

| Service | URL |
|---|---|
| PHP API | http://localhost:8080 |
| Vue UI | http://localhost:5173 |

---

## Quick start without Docker

```bash
git clone https://github.com/lad-sapienza/bdus-api.git
cd bdus-api
composer install
```

Point your web server document root at the repository root.  
The `.htaccess` handles URL rewriting for Apache. For Nginx use:

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

Start the Vue frontend separately (see [bdus-app](https://github.com/lad-sapienza/bdus-app))
and set `VITE_API_BASE` or `API_PROXY_TARGET` to point at this server.

---

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `BRADYPUS_DEBUG` | `0` | Set to `1` to enable verbose error output |
| `BRADYPUS_ALLOW_NEW_APP` | _(unset)_ | Set to `1` to enable the "Create new application" wizard |
| `BRADYPUS_CORS_ORIGIN` | _(unset)_ | Space-separated allowed CORS origins (e.g. `http://localhost:5173`) |

Copy `.env.example` to `.env` and edit as needed.

---

## Running the tests

### PHPUnit — unit and integration tests

Tests use PHPUnit 11 with an in-memory SQLite database — no configuration needed.

```bash
# Inside Docker
docker compose exec app ./vendor/bin/phpunit --testdox

# Or locally (requires PHP on the host)
./vendor/bin/phpunit --testdox
```

### Hurl — end-to-end API test suite

Nine sequential phases cover the full application lifecycle against a running server.
Requires [hurl](https://hurl.dev) and [jq](https://jqlang.github.io/jq/) (`brew install hurl jq`).

```bash
# Start the server first (Docker or native), then:
bash tests/api/run.sh tests/api/vars.env

# Useful flags
bash tests/api/run.sh tests/api/vars.env --from=04   # resume from phase 04
bash tests/api/run.sh tests/api/vars.env --only=06   # run a single phase
bash tests/api/run.sh tests/api/vars.env --list      # dry-run: show all steps
```

Edit `tests/api/vars.env` to point at a different server or app name.

---

## Data directory

Each application stores its data under `projects/{app_name}/`:

```
projects/{app_name}/
├── cfg/        JSON configuration files (tables, fields, app settings)
├── files/      Uploaded files
├── backups/    Database backups
├── export/     Export output
└── db/         SQLite database (if using SQLite engine)
```

The `projects/` directory is bind-mounted in Docker so data persists across container restarts.

---

## License

GNU Affero General Public License v3.0 — see [LICENSE](LICENSE).
