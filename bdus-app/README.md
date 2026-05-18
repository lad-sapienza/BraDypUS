# bdus-app — BraDypUS Vue frontend

**Part of [BraDypUS](https://bdus.cloud)** — the open-source web database system for archaeological and cultural-heritage research.

Developed at [LAD – Laboratorio di Archeologia Digitale, Sapienza University of Rome](https://lad.saras.uniroma1.it) by [Julian Bogdani](https://orcid.org/0000-0001-5250-927X).

License: [GNU AGPL-3.0](LICENSE) · Docs: [docs.bdus.cloud](https://docs.bdus.cloud) · Cloud: [bdus.cloud](https://bdus.cloud) · [![DOI](https://zenodo.org/badge/18011343.svg)](https://zenodo.org/badge/latestdoi/18011343)

> This repository contains the **Vue 3 / Vite frontend** only.  
> The PHP backend lives in **[lad-sapienza/bdus-api](https://github.com/lad-sapienza/bdus-api)**.

---

## What it does

bdus-app is the browser-based interface for BraDypUS. It connects to the
bdus-api backend via a REST JSON API and provides:

- Multi-app login with app selector
- Record browsing, search (simple / advanced / free SQL), and editing
- File and image management
- Geodata viewer and editor (MapLibre GL)
- Charts (bar, line, pie, doughnut, metric)
- Stratigraphic / Harris matrix viewer
- Saved queries and search templates
- Full configuration panel (tables, fields, validation rules, users)
- Dark / light mode

**Stack:** Vue 3 · Vite · PrimeVue 4 · Pinia · Vue Router · MapLibre GL · Chart.js

---

## Requirements

| Tool | Minimum |
|---|---|
| Node.js | 20 LTS |
| npm | 10+ |
| A running bdus-api instance | — |

---

## Quick start with Docker (recommended)

### Full stack (frontend + backend together)

Clone both repositories side by side, write a parent `docker-compose.yml`, and start everything at once:

```bash
mkdir BraDypUS && cd BraDypUS
git clone https://github.com/lad-sapienza/bdus-api.git
git clone https://github.com/lad-sapienza/bdus-app.git

cat > docker-compose.yml << 'EOF'
services:
  app:
    build: bdus-api/
    ports:
      - "8080:80"
    environment:
      - BRADYPUS_DEBUG=1
      - BRADYPUS_CORS_ORIGIN=http://localhost:5173
    volumes:
      - ./bdus-api:/var/www/html
      - ./bdus-api/projects:/var/www/html/projects
    networks:
      - bradypus-net

  node:
    image: node:22-alpine
    working_dir: /app
    volumes:
      - ./bdus-app:/app
    ports:
      - "5173:5173"
    environment:
      - API_PROXY_TARGET=http://app:80
    command: sh -c "npm install && npm run dev"
    networks:
      - bradypus-net

networks:
  bradypus-net:
    driver: bridge
    name: bradypus-net
EOF

docker compose up
```

| Service | URL |
|---|---|
| Vue UI | **http://localhost:5173** |
| PHP API | http://localhost:8080 |

### Frontend only (bdus-api already running elsewhere)

```bash
git clone https://github.com/lad-sapienza/bdus-app.git
cd bdus-app

# Edit docker-compose.yml and set API_PROXY_TARGET to your backend URL,
# then start the network first:
docker network create bradypus-net   # skip if already exists

docker compose up
```

---

## Quick start without Docker

```bash
git clone https://github.com/lad-sapienza/bdus-app.git
cd bdus-app
npm install

# Copy and edit the env file
cp .env.example .env
# Set API_PROXY_TARGET to your bdus-api URL (default: http://localhost:8080)

npm run dev
```

Open **http://localhost:5173** in your browser.

---

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `API_PROXY_TARGET` | `http://localhost:8080` | URL of the bdus-api backend, used **only** by Vite's dev-server proxy (Node process). Never sent to the browser. In Docker Compose set this to the service name: `http://app:80`. |
| `VITE_API_BASE` | _(empty)_ | Public backend URL injected into the browser bundle. Leave empty in development (Vite proxies all `/api/` requests). Set only for cross-origin production deployments. |

Copy `.env.example` to `.env` and edit as needed.

---

## Building for production

```bash
npm run build
```

Output is in `dist/`. Serve it with any static file server and point
`/api/`, `/index.php`, `/projects/`, `/cache/` to the bdus-api backend.

---

## License

GNU Affero General Public License v3.0 — see [LICENSE](LICENSE).
