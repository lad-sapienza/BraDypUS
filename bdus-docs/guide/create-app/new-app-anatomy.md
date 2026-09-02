---
title: Application anatomy
---

# Application anatomy <Badge type="tip" text="v5.0.0" />

After creating an application named `myapp`, the following directory structure
is created under `projects/`:

```
projects/
└── myapp/
    ├── config.json          ← DB connection parameters and app description
    ├── .jwt_secret          ← JWT signing secret (auto-generated, chmod 0600)
    ├── .htaccess            ← blocks web access to config.json and .jwt_secret
    ├── backups/             ← database backup files
    ├── db/
    │   └── bdus.sqlite      ← SQLite database (only for the sqlite engine)
    ├── export/              ← temporary export files (CSV, JSON, XLSX)
    ├── files/               ← uploaded record attachments
    └── geodata/             ← local GeoJSON/KML files for GeoFace layers
```

::: info Welcome text
The editable welcome page (formerly `welcome.md`) is stored in the database,
in the `welcome` column of `bdus_cfg_app`. It is managed through the
application's home page editor.
:::

## System tables

All system tables are created automatically and prefixed with `bdus_`.
User-defined data tables have no required prefix.

| Table | Purpose |
|---|---|
| `bdus_api_keys` | API key authentication |
| `bdus_cfg_app` | App-level settings (status, max image size, welcome text) |
| `bdus_cfg_fields` | Field configuration |
| `bdus_cfg_geoface` | GeoFace / map layer configuration |
| `bdus_cfg_relations` | Cross-table relation definitions |
| `bdus_cfg_tables` | Table configuration |
| `bdus_cfg_templates` | Print templates (Twig source) |
| `bdus_charts` | User-defined charts |
| `bdus_file_links` | File ↔ record associations |
| `bdus_files` | Uploaded file metadata |
| `bdus_geodata` | Geospatial features (WKT geometry) |
| `bdus_log` | Application log entries |
| `bdus_migrations` | Applied migration tracking |
| `bdus_queries` | Saved search queries |
| `bdus_rs` | Stratigraphic relation pairs |
| `bdus_userlinks` | Manual cross-record links |
| `bdus_users` | User accounts |
| `bdus_user_table_privs` | Per-user per-table privilege overrides |
| `bdus_versions` | Record version snapshots |
| `bdus_vocabularies` | Controlled vocabulary items |
| `bdus_zotero_libs` | Zotero library connections |
| `bdus_zotero_links` | Citation links between records and Zotero items |

## config.json

Stores the database engine, connection parameters, and the app description.
For SQLite it looks like:

```json
{
  "definition":  "My archaeological database",
  "db_engine":   "sqlite",
  "db_host":     null,
  "db_port":     null,
  "db_name":     null,
  "db_username": null,
  "db_password": null
}
```

For MySQL or PostgreSQL, `db_host`, `db_port`, `db_name`, `db_username`, and
`db_password` contain the actual connection details.

::: warning
`config.json` and `.jwt_secret` are protected by `.htaccess` and must
**never** be committed to version control or served publicly.
:::
