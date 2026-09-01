# bdus-app — BraDypUS Vue frontend

**Part of [BraDypUS](https://docs.bdus.lad-sapienza.it)** — the open-source web database system for archaeological and cultural-heritage research.

Developed at [LAD – Laboratorio di Archeologia Digitale, Sapienza University of Rome](https://lad.saras.uniroma1.it) by [Julian Bogdani](https://orcid.org/0000-0001-5250-927X).

License: [GNU AGPL-3.0](LICENSE) · Docs: [docs.bdus.lad-sapienza.it](https://docs.bdus.lad-sapienza.it) · [![DOI](https://zenodo.org/badge/18011343.svg)](https://zenodo.org/badge/latestdoi/18011343)

> This directory is the **Vue 3 / Vite frontend**.
> The PHP backend is in [`bdus-api/`](../bdus-api/).

---

## What it does

bdus-app is the browser-based interface for BraDypUS: record browsing/editing,
search, geodata, charts, Harris matrix, import/export, user & schema
management, and more. See the [feature guide](https://docs.bdus.lad-sapienza.it/guide/usage/)
for the full list.

**Stack:** Vue 3 · Vite · Ant Design Vue · Pinia · Vue Router · MapLibre GL · Chart.js · Cytoscape.js · marked.js

---

## Requirements

| Tool | Minimum |
|---|---|
| Node.js | 20 LTS |
| npm | 10+ |
| A running bdus-api instance | — |

---

## Quickstart (development)

```bash
git clone https://github.com/lad-sapienza/BraDypUS.git
cd BraDypUS/bdus-app
npm install
npm run dev
```

Open **http://localhost:5173**. By default it targets a bdus-api instance at
`http://localhost:8080` — set `API_PROXY_TARGET` in `.env` to point elsewhere.
For the full stack or production deployment, see the
**[deployment guide](https://docs.bdus.lad-sapienza.it/guide/deploy/)**.

---

## Environment variables, build, architecture

See the **[developer guide](https://docs.bdus.lad-sapienza.it/dev/frontend)**:

- [Environment variables](https://docs.bdus.lad-sapienza.it/dev/frontend#environment-variables) (`API_PROXY_TARGET`, `VITE_API_BASE`)
- [Building for production & standalone deployment](https://docs.bdus.lad-sapienza.it/dev/frontend#build-output)
- [Component map & composables](https://docs.bdus.lad-sapienza.it/dev/frontend)

---

## License

GNU Affero General Public License v3.0 — see [LICENSE](LICENSE).
