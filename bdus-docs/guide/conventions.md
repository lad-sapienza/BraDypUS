---
title: Conventions
---

# Conventions

## Application name

BraDypUS is a multi-application system: on the same installation many
applications can coexist, each fully isolated and self-contained.

The **application name** is the unique identifier for each application.
It must match: `^[a-z][a-z0-9_-]{2,19}$`

Rules:
- 3 to 20 characters
- lowercase ASCII letters, digits, hyphens, underscores
- must start with a letter

Examples of valid names: `sites`, `paths_2024`, `my-archive`

In this guide **`myapp`** is used as the example application name.

## System tables

BraDypUS manages a set of **system tables** automatically. Their structure
is fixed and cannot be changed. All system tables are prefixed with `bdus_`:

| Table | Purpose |
|---|---|
| `bdus_users` | User accounts and privileges |
| `bdus_log` | System activity log |
| `bdus_versions` | Record version snapshots |
| `bdus_rs` | Stratigraphic relations (Harris Matrix) |
| `bdus_geodata` | Geographic geometries (WKT) |
| `bdus_files` | Uploaded file metadata |
| `bdus_file_links` | Junction between files and records |
| `bdus_userlinks` | Manual record-to-record links |
| `bdus_vocabularies` | Vocabulary items |
| `bdus_cfg_app` | Application settings (persisted to DB) |
| `bdus_cfg_geoface` | GeoFace layer configuration |
| `bdus_cfg_relations` | Cross-table relation definitions |
| `bdus_zotero_libs` | Zotero library connections |
| `bdus_zotero_links` | Zotero citation links |
| `bdus_migrations` | Migration history |

## Data tables

**Data tables** are defined by the user in Config → Tables. Their structure
depends entirely on research needs and can be updated at any time by a super-admin.

### Regular tables

Top-level tables that appear in the sidebar. Each regular table can contain
any number of user-defined fields plus the mandatory `id` (auto-increment integer
primary key).

### Plugin tables

Sub-tables attached to a parent (regular) table. They add repeating groups of
information to a parent record (e.g. multiple finds per context). Plugin tables
require two mandatory FK fields: `table_link` (TEXT) and `id_link` (INT).

## Privilege levels

See [Users & privileges](/guide/setup/users) for the full privilege table.

## JWT authentication

BraDypUS v5 uses **stateless JWT (JSON Web Token)** authentication. There are no PHP sessions.
Each browser tab carries its own token stored in `sessionStorage`, which means:

- Multiple applications can be open in different tabs simultaneously.
- Closing a tab terminates that session immediately.
- Tokens expire after 8 hours; silent refresh happens automatically when < 30 min remain.

## Per-application secrets

Each application has a signing secret stored at `projects/{app}/.jwt_secret`
(chmod 0600, generated automatically on first login). Never commit this file to version control.
