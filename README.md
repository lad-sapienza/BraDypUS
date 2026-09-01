# BraDypUS

**Open-source web database system for archaeological and cultural-heritage research.**

Developed at [LAD – Laboratorio di Archeologia Digitale, Sapienza University of Rome](https://lad.saras.uniroma1.it) by [Julian Bogdani](https://orcid.org/0000-0001-5250-927X).

License: [GNU AGPL-3.0](bdus-api/LICENSE) · Docs: [docs.bdus.lad-sapienza.it](https://docs.bdus.lad-sapienza.it) · [![DOI](https://zenodo.org/badge/18011343.svg)](https://zenodo.org/badge/latestdoi/18011343)

---

## Repository layout

This is a monorepo. The three components are developed, versioned and released
together; each still has its own `Dockerfile` and can be built on its own.

| Directory | Language / Stack | Purpose |
|---|---|---|
| [`bdus-api/`](bdus-api/) | PHP 8.4 · Apache | REST JSON backend, multi-tenant → `ghcr.io/lad-sapienza/bdus-api` |
| [`bdus-app/`](bdus-app/) | Vue 3 · Vite · Ant Design Vue | Browser SPA → `ghcr.io/lad-sapienza/bdus-app` |
| [`bdus-docs/`](bdus-docs/) | VitePress | User & developer guide → [docs.bdus.lad-sapienza.it](https://docs.bdus.lad-sapienza.it) |

This root directory holds the **combined compose files**, helper scripts, the
single [`CHANGELOG.md`](CHANGELOG.md), and the CI in [`.github/workflows/`](.github/workflows/).

---

## Quickstart (development)

```bash
git clone https://github.com/lad-sapienza/BraDypUS.git
cd BraDypUS
docker compose up
```

| Service | URL |
|---|---|
| Vue UI (Vite dev server) | <http://localhost:5173> |
| PHP API | <http://localhost:8080> |

For production deployment (pre-built GHCR images, build from source, or
manual/shared-hosting install) see the
**[deployment guide](https://docs.bdus.lad-sapienza.it/guide/deploy/)**.

---

## Helper scripts

| Script | Purpose |
|---|---|
| [`backup.sh`](backup.sh) / [`restore.sh`](restore.sh) | Backup or restore the `projects_data` Docker volume — full or per-app |
| [`seed-demo.sh`](seed-demo.sh) | Populate an instance with a realistic archaeological demo dataset |
| [`bump-version.sh`](bump-version.sh) | Cut a release: bump manifests + CHANGELOG, one commit, one tag `vX.Y.Z`, push. The tag builds both GHCR images. |

Usage and options are documented in each script's header comment, and in the
[docs site](https://docs.bdus.lad-sapienza.it/guide/install/containers#backup-and-restore).

---

## Contributing

Architecture notes, test suite, and API internals are documented in the
**[developer guide](https://docs.bdus.lad-sapienza.it/dev/)**.

---

## License

GNU Affero General Public License v3.0 — see [bdus-api/LICENSE](bdus-api/LICENSE).
