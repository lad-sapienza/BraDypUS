---
title: Installation overview
---

# Installation overview

BraDypUS v5 consists of two components that run together:
- **`bdus-api`** — PHP backend
- **`bdus-app`** — Vue 3 frontend

All approaches use **Docker Compose** to manage both services.

## Pre-built images — fastest (recommended)

No source code required. Docker pulls the images directly from
[GitHub Container Registry](https://ghcr.io/lad-sapienza) and starts the application.

See [Deploy with pre-built images](/guide/install/containers).

## Build from source — for development

Clone the repository and build the images locally. Required if you want to
modify the source code or run the Vite dev server.

See [Install via terminal / git](/guide/install/terminal).

## Manual installation (advanced)

See [Manual installation](/guide/install/manual-download) if you need to run
without Docker on a shared hosting server.

## What you'll need

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- [Git](https://git-scm.com/) only for the build-from-source approach
