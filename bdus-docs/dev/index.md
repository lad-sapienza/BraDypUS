---
title: Developer guide
---

# Developer guide

This section is for contributors, integrators, and AI agents working with
the BraDypUS codebase.

## Quick orientation

| You want to… | Read… |
|---|---|
| Understand how the app bootstraps | [Architecture](./architecture) |
| Find which file handles X | [lib/ map](./lib-map) · [Modules](./modules) |
| Understand the DB layer | [Database](./database) |
| Write a new migration | [DB migrations](./migrations) |
| Understand the query DSL | [SQL layer](./sql-layer) |
| Understand record read / write | [Record lifecycle](./record-lifecycle) |
| Understand config schema & access control | [Config & UAC](./config) |
| Work on the Vue frontend | [Frontend](./frontend) |
| Add OAuth support | [OAuth2 / SSO](./oauth) |
| Set up transactional email (password reset, self-registration) | [Email (Resend)](./email) |
| Use the widget API | [Widget API](./widget-api) |
| Connect Zotero bibliography | [Zotero integration](./zotero) |
| Run the test suite | [Testing](./testing) |
| Find environment variables / data directory layout | [Architecture](./architecture#environment-variables) |

## Source repositories

| Repo | Contents |
|---|---|
| [lad-sapienza/BraDypUS](https://github.com/lad-sapienza/BraDypUS) | Monorepo: PHP API (`bdus-api/`), Vue 3 frontend (`bdus-app/`), and this documentation site (`bdus-docs/`) |
