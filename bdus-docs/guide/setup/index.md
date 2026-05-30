---
title: Setting up your application
---

# Setting up your application

Application setup is handled entirely through the **Config** panel, a dedicated
administration area accessible from the sidebar. Only users with **super-admin**
privilege can open it.

![TODO_SCREENSHOT: Config panel open with sidebar showing six panel buttons](/images/v5/setup/config-overview.png)

## The Config panel

The Config panel is divided into six sections, selectable from the left sidebar:

| Panel | Purpose |
|---|---|
| **App settings** | Name, language, status, DB engine and other global options |
| **Validation** | Schema check — finds missing columns, broken FK references, and bad indexes |
| **Geoface** | Configure map layers (WMS, WFS, local GeoJSON/KML files) |
| **Tables** | Create, rename, delete and reorder tables; set layout and plugin links |
| **Fields** | Add and configure fields for any table |
| **Relations** | Define cross-table links displayed in RecordView |

## How to open Config

Click the **Config** item in the sidebar navigation. The first time (or after a
session timeout) you are asked to confirm your super-admin password.

![TODO_SCREENSHOT: super-admin password confirmation dialog](/images/v5/setup/config-password.png)

::: warning Destructive operations
Table deletion and column deletion are **permanent and irreversible**. Always create
a [backup](/guide/usage/backup) before making structural changes.
:::

## Typical setup workflow

1. **App settings** — set the application name, language, and status.
2. **Tables** — create all required tables and set their preview fields.
3. **Fields** — add columns to each table, configure types and validation rules.
4. **Relations** — define cross-table links so RecordView shows related records.
5. **Vocabularies** — populate controlled vocabularies for select/combo fields.
6. **Validation** — run the schema validator and fix any reported issues.
