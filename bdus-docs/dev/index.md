---
title: Developer guide
---

# Developer guide

This section is for contributors, integrators, and AI agents working with
the BraDypUS codebase.

> **Work in progress** — developer docs are being written.
> Check back or see the [migration plan](https://github.com/lad-sapienza/bdus-docs/blob/v5/MIGRATION_PLAN.md)
> for the writing schedule.

## Quick orientation

| You want to… | Read… |
|---|---|
| Understand how the app bootstraps | [Architecture](./architecture) |
| Find which file handles X | [lib/ map](./lib-map) · [Modules](./modules) |
| Understand the DB layer | [Database](./database) |
| Write a new migration | [DB migrations](./migrations) |
| Understand the query DSL | [SQL layer](./sql-layer) |
| Add OAuth support | [OAuth2 / SSO](./oauth) |
| Use the widget API | [Widget API](./widget-api) |
| Run the test suite | [Testing](./testing) |

## Source repositories

| Repo | Contents |
|---|---|
| [lad-sapienza/bdus-api](https://github.com/lad-sapienza/bdus-api) | PHP API + Vue 3 frontend |
| [lad-sapienza/bdus-docs](https://github.com/lad-sapienza/bdus-docs) | This documentation site |
