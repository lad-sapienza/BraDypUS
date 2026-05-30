---
title: Application anatomy
---

# Application anatomy

After creating an application named `myapp`, the following directory structure
is created under `projects/`:

```
projects/
└── myapp/
    ├── config.json          ← main application config (DB connection, settings)
    ├── .jwt_secret          ← JWT signing secret (auto-generated, chmod 0600)
    ├── .htaccess            ← blocks web access to config.json and .jwt_secret
    ├── backups/             ← database backup files
    ├── db/
    │   └── bdus.sqlite      ← SQLite database (only for sqlite engine)
    ├── export/              ← temporary export files
    ├── files/               ← uploaded record attachments
    ├── geodata/             ← local GeoJSON/KML files for GeoFace layers
    └── welcome.md           ← editable welcome page (Markdown)
```

## System tables

The following system tables are created automatically in the database:

`bdus_users`, `bdus_log`, `bdus_versions`, `bdus_rs`, `bdus_geodata`,
`bdus_files`, `bdus_file_links`, `bdus_userlinks`, `bdus_vocabularies`,
`bdus_cfg_app`, `bdus_cfg_geoface`, `bdus_cfg_relations`,
`bdus_zotero_libs`, `bdus_zotero_links`, `bdus_migrations`

All system tables are prefixed with `bdus_` and managed automatically.
User data tables have no required prefix.

## config.json

Stores the database engine and connection parameters.
For SQLite it looks like:

```json
{
  "db_engine": "sqlite",
  "db_host": "",
  "db_name": "",
  "db_username": "",
  "db_password": "",
  "db_port": ""
}
```

::: warning
`config.json` and `.jwt_secret` are protected by `.htaccess` and must
**never** be committed to version control or served publicly.
:::
