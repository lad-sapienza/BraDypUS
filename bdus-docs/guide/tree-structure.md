---
title: Repository structure
---

# Repository structure

BraDypUS v5 is split into two separate repositories that are deployed together.

## `bdus-api` — PHP backend

```
bdus-api/
├── controllers/          ← API controllers (Bdus\Controllers\*)
├── lib/                  ← Framework: DB, SQL, Record, Config, Auth, JWT, …
├── projects/             ← Runtime: one subdirectory per application (not committed)
│   └── myapp/
│       ├── config.json
│       ├── .jwt_secret
│       ├── backups/
│       ├── db/
│       ├── files/
│       └── …
├── tests/
│   ├── Integration/      ← PHPUnit controller tests
│   ├── Unit/             ← PHPUnit unit tests
│   ├── Support/          ← BdusTestCase base class + fixtures
│   └── api/              ← Hurl E2E tests + run.sh
├── vendor/               ← Composer dependencies
├── index.php             ← Entry point
├── composer.json
├── Dockerfile
├── docker-compose.yml
└── test.sh               ← Unified test runner (PHPUnit + Hurl)
```

## `bdus-app` — Vue 3 frontend

```
bdus-app/
├── src/
│   ├── views/            ← Route-level components (*View.vue)
│   ├── components/       ← Reusable components (record/, config/, users/, …)
│   ├── stores/           ← Pinia stores (auth, …)
│   ├── composables/      ← Reusable composition functions
│   ├── api/              ← API client (fetch-based, JWT injected)
│   ├── locale/           ← i18n files (en.json, it.json)
│   └── router/           ← Vue Router (history mode)
├── public/
├── dist/                 ← Built output (not committed)
├── package.json
├── vite.config.js
├── Dockerfile
└── nginx.conf.template   ← Production Nginx config
```

## Runtime layout

The Vue SPA is served statically. All `/api/*` traffic is proxied to the PHP
backend (by Vite in dev, by Nginx in production).

```
Browser
  ├── GET / → index.html  (SPA)
  └── /api/* → PHP backend (FastRoute dispatcher)
```
