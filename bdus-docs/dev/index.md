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
| Use the widget API | [Widget API](./widget-api) |
| Connect Zotero bibliography | [Zotero integration](./zotero) |
| Run the test suite | [Testing](./testing) |

## Source repositories

| Repo | Contents |
|---|---|
| [lad-sapienza/bdus-api](https://github.com/lad-sapienza/bdus-api) | PHP API + Vue 3 frontend |
| [lad-sapienza/bdus-docs](https://github.com/lad-sapienza/bdus-docs) | This documentation site |
