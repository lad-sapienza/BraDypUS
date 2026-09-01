---
title: System requirements
---

# System requirements

## Development (local)

The recommended way to run BraDypUS locally is with **Docker**.

| Requirement | Version |
|---|---|
| [Docker Desktop](https://www.docker.com/products/docker-desktop/) | ≥ 4.x |
| [Git](https://git-scm.com/) | ≥ 2.x |

Docker provides everything else: PHP 8.4, Apache, Node 22, SQLite.
No local PHP or Node installation is needed.

## Production (server)

For production deployments without Docker, the server needs:

| Requirement | Details |
|---|---|
| **PHP** | ≥ 8.4 with `pdo`, `pdo_sqlite`/`pdo_mysql`/`pdo_pgsql`, `mbstring`, `gd` |
| **Web server** | Apache (with `mod_rewrite`) or Nginx |
| **Database** | SQLite 3, MySQL ≥ 5.7 / MariaDB, or PostgreSQL ≥ 12 |
| **Node.js** | ≥ 18 (only needed to build the Vue frontend; not needed at runtime) |

The Vue frontend is a pre-built static SPA — the production server only needs
to serve the `dist/` files and proxy `/api/*` to PHP.

## Browser requirements

Any modern browser: Chrome/Edge ≥ 90, Firefox ≥ 88, Safari ≥ 14.
JavaScript must be enabled. Internet Explorer is not supported.
