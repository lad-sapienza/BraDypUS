# BraDypUS

**The web-based, flexible, free (AGPL) database system**

BraDypUS is an open-source web database developed at
[LAD – Laboratorio di Archeologia Digitale, Sapienza University of Rome](https://lad.saras.uniroma1.it)
by Julian Bogdani. It targets archaeological and cultural-heritage research teams who need to
create, manage and publish relational databases on the web without writing code.

License: [GNU AGPL-3.0](https://www.gnu.org/licenses/agpl-3.0.en.html) ·
Docs: [docs.bdus.cloud](https://docs.bdus.cloud) ·
Cloud: [bdus.cloud](https://bdus.cloud) ·
[![DOI](https://zenodo.org/badge/18011343.svg)](https://zenodo.org/badge/latestdoi/18011343)

---

## Quick start

### With Docker (recommended)

**Requirements:** Docker + Docker Compose (no other dependencies on the host).

```bash
git clone https://github.com/bdus-db/BraDypUS.git
cd BraDypUS

# Start PHP/Apache backend (port 8080) + Vite dev server (port 5173)
docker compose --profile vue up
```

| Service | URL |
|---|---|
| PHP API / legacy UI | http://localhost:8080 |
| Vue dev UI | http://localhost:5173 |

The first start installs Composer dependencies automatically inside the container
(`composer install` is run by the entrypoint if `vendor/` is missing).

To stop: `docker compose --profile vue down`

---

### Without Docker

**Requirements:**

| Tool | Minimum version |
|---|---|
| PHP | 8.2 |
| PHP extensions | `pdo`, `pdo_sqlite` (or `pdo_mysql` / `pdo_pgsql`), `mbstring`, `gd` |
| Composer | 2.x |
| Node.js | 20 LTS |
| npm | 10+ |
| Web server | Apache with `mod_rewrite`, or any PHP-capable server |

```bash
git clone https://github.com/bdus-db/BraDypUS.git
cd BraDypUS

# PHP dependencies
composer install

# Node / Vue dependencies
npm install

# Start the Vite dev server (assumes PHP is served at http://localhost:8080)
# Edit the `server.proxy` target in vite.config.js if your PHP URL differs
npm run vue:dev
```

Point your web server document root at the repository root.  
The Vite dev server proxies `/index.php` and `/projects/` to the PHP backend,
so both can run side by side during development.

---

## Development environment (with Docker)

The `docker-compose.yml` defines two services:

| Service | Description |
|---|---|
| `app` | PHP 8.2 + Apache, source live-mounted, port **8080** |
| `node` (profile `vue`) | Node 20, Vite dev server, port **5173** |

### Useful commands

```bash
# Backend only (no Vite)
docker compose up

# Backend + Vue dev server
docker compose --profile vue up

# Run PHP dependency installer manually
docker compose exec app composer install

# Rebuild the PHP image (after Dockerfile changes)
docker compose build

# Open a shell in the PHP container
docker compose exec app bash
```

### Running the test suite

Tests use PHPUnit 11 with an in-memory SQLite database — no database
configuration needed.

```bash
# Run all tests inside the PHP container
docker compose exec app ./vendor/bin/phpunit --testdox

# Or, if you have PHP available on the host
./vendor/bin/phpunit --testdox
```

The suite currently covers `debug_ctrl`, `record_ctrl`, `search_ctrl` and
`QueryFromRequest` (60 tests).
