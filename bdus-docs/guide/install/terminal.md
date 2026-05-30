---
title: Install with Docker (recommended)
---

# Install with Docker

## 1. Clone the orchestration repository

The root `BraDypUS` repository contains the `docker-compose.yml` and
the two sub-repos as directories:

```bash
git clone https://github.com/lad-sapienza/BraDypUS.git
cd BraDypUS
```

## 2. Start the containers

```bash
docker compose up -d
```

This starts two containers:

| Container | Purpose | Port |
|---|---|---|
| `app` | PHP 8.2 + Apache (bdus-api) | 8080 |
| `node` | Node 22 + Vite dev server (bdus-app) | 5173 |

::: tip First run
The first start takes a few minutes to build the image and install
Composer + npm dependencies. Subsequent starts are instant.
:::

## 3. Open the application

- **Frontend (Vue SPA):** http://localhost:5173
- **Backend API directly:** http://localhost:8080

## 4. Create your first application

Follow the [Create application](/guide/create-app/) guide.

## 5. Stop the containers

```bash
docker compose down
```

## Updating

```bash
git pull
docker compose up -d --build
```
