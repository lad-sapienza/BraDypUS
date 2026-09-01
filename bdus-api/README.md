# bdus-api — BraDypUS PHP backend

**Part of [BraDypUS](https://docs.bdus.lad-sapienza.it)** — the open-source web database system for archaeological and cultural-heritage research.

Developed at [LAD – Laboratorio di Archeologia Digitale, Sapienza University of Rome](https://lad.saras.uniroma1.it) by [Julian Bogdani](https://orcid.org/0000-0001-5250-927X).

License: [GNU AGPL-3.0](LICENSE) · Docs: [docs.bdus.lad-sapienza.it](https://docs.bdus.lad-sapienza.it) · [![DOI](https://zenodo.org/badge/18011343.svg)](https://zenodo.org/badge/latestdoi/18011343)

> This directory is the **PHP/Apache backend**.
> The Vue frontend is in [`bdus-app/`](../bdus-app/).

---

## What it does

bdus-api is a multi-tenant REST JSON API (auth, records CRUD, search, files,
geodata, charts, schema migrations, …) consumed by the bdus-app Vue frontend.
See the [feature guide](https://docs.bdus.lad-sapienza.it/guide/usage/) for the full list.

---

## Requirements

| Tool | Minimum |
|---|---|
| PHP | 8.4 |
| PHP extensions | `pdo`, `pdo_sqlite` (or `pdo_mysql` / `pdo_pgsql`), `mbstring`, `gd` |
| Composer | 2.x |
| Web server | Apache with `mod_rewrite`, or Nginx |

---

## Quickstart (development)

```bash
git clone https://github.com/lad-sapienza/BraDypUS.git
cd BraDypUS/bdus-api
docker compose up
```

The backend runs standalone at **http://localhost:8080** (Composer
dependencies install automatically on first start). For the full stack
(with the Vue frontend) or production deployment, see the
**[deployment guide](https://docs.bdus.lad-sapienza.it/guide/deploy/)**.

---

## Environment variables, tests, architecture

See the **[developer guide](https://docs.bdus.lad-sapienza.it/dev/)**:

- [Environment variables & request lifecycle](https://docs.bdus.lad-sapienza.it/dev/architecture)
- [Running the test suite](https://docs.bdus.lad-sapienza.it/dev/testing)
- [Data directory layout](https://docs.bdus.lad-sapienza.it/dev/architecture#directory-layout-backend)

---

## License

GNU Affero General Public License v3.0 — see [LICENSE](LICENSE).
