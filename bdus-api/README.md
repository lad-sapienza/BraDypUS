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
- Search (simple text, advanced field-level, SQL expert, Directus-style JSON filter)
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

## Deployment

> Full documentation for all deployment scenarios (development, production from source,
> Docker Hub images, manual installation) is in the
> **[monorepo README](https://github.com/lad-sapienza/BraDypUS#deployment-scenarios)**.

### Backend only (development)

The backend runs standalone at **http://localhost:8080**:

```bash
git clone https://github.com/lad-sapienza/bdus-api.git
cd bdus-api
docker compose up
```

Composer dependencies are installed automatically on first start.  
Add `BRADYPUS_ALLOW_NEW_APP=1` to the environment to enable the new-application wizard.

### Full stack — development (hot-reload)

Clone both repositories side by side, then use the root compose file:

```bash
mkdir BraDypUS && cd BraDypUS
git clone https://github.com/lad-sapienza/bdus-api.git
git clone https://github.com/lad-sapienza/bdus-app.git
docker compose up                          # Vue on :5173, PHP on :8080
```

### Full stack — production (from source)

Pre-built Nginx + Apache images, everything on port 80:

```bash
# (inside the BraDypUS/ monorepo directory)
docker compose -f docker-compose.prod.yml up -d --build
```

### Full stack — production (Docker Hub, coming soon)

Once images are published at `jbogdani/bradypus-api` and `jbogdani/bradypus-app`,
no source code is needed — see the
[monorepo README](https://github.com/lad-sapienza/BraDypUS#c--production-from-docker-hub)
for the ready-to-use `docker-compose.yml`.

### Without Docker

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

### One-command full run

`test.sh` starts an isolated Docker container, runs PHPUnit + the full Hurl
suite, and tears everything down on exit.
Requires Docker, [hurl](https://hurl.dev) ≥ 4.0, and [jq](https://jqlang.github.io/jq/).

```bash
# SQLite (default) — PHPUnit + 18 Hurl phases
./test.sh

# PostgreSQL — Hurl only against a disposable postgres:16 container
./test.sh --db=pgsql --skip-unit

# MariaDB — Hurl only against a disposable mariadb:11 container
./test.sh --db=mysql --skip-unit

# Leave the container running after the tests (useful for manual inspection)
./test.sh --keep

# Seed the app with demo data and leave the container up (screenshot workflow)
./test.sh --seed-demo --keep
```

All three database engines pass the full suite identically.

### PHPUnit — unit and integration tests

PHPUnit uses an **in-memory SQLite** database — no server needed.

```bash
docker compose exec app php vendor/bin/phpunit --testdox
```

### Hurl — end-to-end API test suite

18 sequential phases cover the full application lifecycle.

| Phase | What it tests |
|---|---|
| 01 | Create application |
| 02 | Login / JWT capture |
| 03 | Config — tables & fields |
| 04 | Records CRUD |
| 05 | Stratigraphic relations (RS) |
| 06 | Search (simple / advanced / SQL expert) |
| 07 | Charts |
| 08 | Backup (create / list / delete) |
| 09 | Users & privilege enforcement |
| 10 | Cleanup / logout |
| 11 | Record version history |
| 12 | Data import (CSV / JSON / GeoJSON) |
| 13 | DB migrations list |
| 14 | Relations panel (bdus_cfg_relations CRUD) |
| 15 | Vocabularies CRUD |
| 16 | Welcome text + Search & Replace |
| 17 | Zotero libraries & citation links |
| 18 | JSON filter (Directus-style bracket notation) |

```bash
# Ad-hoc run against a running server (default vars: localhost:8080, SQLite)
bash tests/api/run.sh tests/api/vars.env

# Dry-run: list all phases and their steps
bash tests/api/run.sh tests/api/vars.env --list
```

Copy `tests/api/vars.env` to `tests/api/vars.local.env` to override host or app name.

---

## Data directory

Each application stores its data under `projects/{app_name}/`:

```
projects/{app_name}/
├── config.json     Application settings (DB engine, credentials, …)
├── .jwt_secret     Per-app JWT signing secret (auto-generated, chmod 0600)
├── .htaccess       Blocks web access to config.json and .jwt_secret
├── files/          Uploaded files
├── backups/        Database backups
├── geodata/        GeoJSON / KML / GPX files for the map layer editor
├── export/         Export output (CSV, JSON, …)
└── db/             SQLite database file (if using the SQLite engine)
```

Table/field/relation configuration is stored directly in the SQLite database
(`bdus_cfg_tables`, `bdus_cfg_fields`, `bdus_cfg_relations`) — no per-table
JSON files on disk.

The `projects/` directory is bind-mounted in Docker so data persists across container restarts.

---

## License

GNU Affero General Public License v3.0 — see [LICENSE](LICENSE).
