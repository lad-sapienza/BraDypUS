---
title: Knowledge base
---

# Knowledge base

Useful background knowledge for working with BraDypUS.

## Relational databases

BraDypUS manages relational databases. Understanding basic concepts helps:

- **Table** — a named collection of rows with fixed column structure
- **Column (field)** — a named property with a specific data type
- **Row (record)** — one entry in a table
- **Primary key** — a unique identifier for each row (BraDypUS always uses `id`)
- **Foreign key** — a column that references the `id` of another table

## JSON

Application configuration is stored in JSON (`config.json`). Print templates
are also JSON. You do not need to edit JSON manually — the UI handles it — but
familiarity helps when troubleshooting.

## Markdown

The welcome page (`welcome.md`) is written in Markdown. Basic syntax:

```
# Heading
**bold** *italic*
- list item
[link text](https://example.com)
```

## Docker

BraDypUS runs in Docker containers. Basic commands:

```bash
docker compose up -d        # start in background
docker compose down         # stop
docker compose logs -f app  # follow PHP logs
docker compose exec app bash # shell inside the PHP container
```

## HTTP and REST

The BraDypUS backend is a JSON REST API. All communication between the Vue
frontend and the PHP backend uses HTTP requests with JSON bodies and JWT tokens
in the `Authorization` header.
