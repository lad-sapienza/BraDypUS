---
title: Database layer
---

# Database layer

BraDypUS supports three SQL engines (SQLite, MySQL, PostgreSQL) through a
unified PDO-based abstraction. This page documents the DB layer classes and
the system tables they manage.

---

## Supported engines

| Engine | Driver | Use case |
|---|---|---|
| SQLite | `pdo_sqlite` | Default for new installs, single-file, zero-config |
| MySQL / MariaDB | `pdo_mysql` | Shared hosting, larger deployments |
| PostgreSQL | `pdo_pgsql` | Production deployments requiring ACID guarantees |

The engine is configured in `projects/{app}/config.json`:

```json
{
  "name": "myapp",
  "db_engine": "sqlite"
}
```

`db_engine` is required for non-SQLite apps; a legacy app without it is
auto-detected from `projects/{app}/db/bdus.sqlite` and the file is updated once.
For SQLite the DB file is always at `projects/{app}/db/bdus.sqlite`.
For MySQL / PostgreSQL the connection parameters (`db_host`, `db_name`,
`db_username`, `db_password`, optional `db_port`) are also in `config.json`.

---

## `DB\DB` — PDO wrapper

`DB` (implementing `DB\DBInterface`) is the central class every controller and
lib class uses for all database access. It is built once per request by
`Bdus\App` and injected into controllers. It wraps PDO with:

- Automatic engine detection and DSN construction.
- `ERRMODE_EXCEPTION` — all errors throw `DBException`.
- `EMULATE_PREPARES = false` — native prepared statements only.
- SQLite-specific pragmas: `PRAGMA encoding = "UTF-8"` and
  `PRAGMA foreign_keys = ON` on every connection.
- Monolog logging of all query errors.

### `query()` — the universal method

```php
$db->query(string $sql, array $params = [], string $type = null): mixed
```

| `$type` value | Return value |
|---|---|
| `'read'` (default) | `array` of associative rows |
| `'boolean'` | `true` \| `false` |
| `'id'` | last insert ID (string) |
| `'affected'` | row count (int) |
| integer (0, 1, …) | value of column N from the first row |

A fourth argument, `$fetch_style`, returns numeric instead of associative rows
when `true`.

All user-supplied values go through prepared statement binding — never
string-concatenated into SQL.

### Other methods

```php
$db->exec(string $sql): bool            // raw DDL (no params)
$db->execInTransaction(string $sql): bool  // DDL wrapped in a transaction
$db->beginTransaction(): void
$db->commit(): void
$db->rollBack(): void
$db->getEngine(): string                // 'sqlite' | 'mysql' | 'pgsql'
$db->getApp(): string                   // application name
$db->hasSpatialExtension(): bool        // checks ST_GeomFromText support
```

### `saveSnapshot()` — versioning

Before every write operation `Record\Persist` calls `DB::saveSnapshot()`:

```php
$db->saveSnapshot(
    tb: 'sites',
    id: 42,
    content: ['core' => [...], 'plugins' => [...]],
    operation: 'update'  // 'update' | 'delete' | 'restore'
);
```

This inserts a row into `bdus_versions` with the full record state as JSON,
enabling unlimited undo / diff history.

`tb` is the name of the table **as the request addressed it**, and `content` holds
the columns that table has: for a record edited through a registered
[view](/guide/setup/views), `bdus_versions.tb` is the view name and the snapshot
contains only the view's columns. History is therefore per name, not per base table.

---

## `DB\Inspect` — Schema introspection

`Inspect` dispatches to an engine-specific implementation and exposes a
uniform interface for reading the live schema:

```php
$inspect = new \DB\Inspect($db);
$inspect->tableColumns('sites');        // column names / definitions of the table
$inspect->tableExists('bdus_users');    // bool
$inspect->getAllTables();               // all table names in the DB
```

Engine implementations: `DB\Inspect\Mysql`, `DB\Inspect\Postgres`,
`DB\Inspect\Sqlite`.

### Views are tables to `Inspect` (MySQL, PostgreSQL)

On MySQL/MariaDB `tableExists()` queries `information_schema.TABLES` and
`tableColumns()` runs `DESCRIBE`; both include **views**. `getAllTables()` uses
`SHOW TABLES`, which lists views too. On PostgreSQL `tableExists()` and
`tableColumns()` use `information_schema.tables` / `columns`, which include views,
but `getAllTables()` reads `pg_catalog.pg_tables`, which does not (deduced from the
code, not run). SQLite filters on `type='table'`, so a view is not a table there.

That is what lets an administrator register a view in `bdus_cfg_tables` /
`bdus_cfg_fields` and have the rest of the application treat it as a table: the
record layer only runs `SELECT`, `INSERT`, `UPDATE`, `DELETE` on the name in the
configuration. The guide is [SQL views as tables](/guide/setup/views).

Consequences to keep in mind when changing this layer:

- **`DB\Alter` is not view-aware.** Adding, renaming or dropping a *field* of a
  registered view through `/api/config/table/{tb}/field…` answers success but the
  view is unchanged (proven on MariaDB); only the configuration changes.
  `DELETE /api/config/table/{tb}` removes the configuration and leaves the view.
- **`DB\Validate\DbCfgAlign`** compares configured fields with `tableColumns()`,
  so a registered view is validated like a table.
- **Migrations.** Migrations never recreate a view. On **SQLite**,
  `Migrate::run()` calls `dropLegacyViews()` first, and `run()` is invoked by
  `Login` when there is nothing pending, by `Upgrade::runMinor` and by
  `Upgrade::runMajor`: every view is dropped at the next login (proven).
  On MySQL and PostgreSQL the method returns immediately and views are left alone.
  A migration that alters a base table (`ALTER TABLE … DROP/RENAME COLUMN`) can
  still break a view that references the column.

---

## `DB\Alter` — Schema modification

`Alter` dispatches to engine-specific DDL for safe schema changes on
**user-defined tables** (for system tables, use `DB\System\Manage`).

### Column and table operations

```php
$alter = new \DB\Alter($db);
$alter->addFld('sites', 'notes', 'TEXT');
$alter->renameFld('sites', 'notes', 'description', 'TEXT');
$alter->dropFld('sites', 'description');
$alter->createMinimalTable('measurements', false);   // regular table
$alter->createMinimalTable('myplugin', true, 'sites'); // plugin + FK
$alter->dropTable('measurements');
$alter->renameTable('measurements', 'measures');
```

::: info SQLite recreation
SQLite does not support `DROP COLUMN`, `ADD CONSTRAINT`, or `DROP
CONSTRAINT` on existing tables. `DB\Alter\Sqlite` implements these
operations via the **copy-and-rename** pattern: a temporary table with
the desired schema is created, data is copied, the original is dropped,
and the temporary table is renamed. `PRAGMA foreign_keys = OFF` is set
for the duration.
:::

### FK constraint operations

Added in v5 to enforce at database level the relationships defined in
the application configuration.

```php
// Check for orphans before applying (returns count of violating rows)
$n = $alter->checkOrphans('sites', 'period_id', 'periods', 'id');

// Add FK: sites.period_id → periods.id ON DELETE RESTRICT ON UPDATE CASCADE
$alter->addForeignKey('sites', 'period_id', 'periods', 'id', 'RESTRICT', 'CASCADE');

// Query / remove
$alter->hasForeignKey('sites', 'period_id');   // bool
$alter->dropForeignKey('sites', 'period_id');
```

Supported `ON DELETE` / `ON UPDATE` policies: `CASCADE`, `RESTRICT`,
`SET NULL`, `NO ACTION`.

Self-referential FKs (`from_tb === to_tb`) are allowed; by convention
`on_delete` is forced to `RESTRICT` and `on_update` to `CASCADE`.

**Plugin tables** created via `createMinimalTable($tb, true, $pluginOf)`
automatically receive `FOREIGN KEY (id_link) REFERENCES {pluginOf}(id)
ON DELETE RESTRICT`.

### Index operations

```php
$alter->createIndex('sites', 'idx_sites_period', ['period_id'], false);  // B-tree
$alter->createIndex('sites', 'idx_sites_name_uniq', ['name'], true);     // UNIQUE
$alter->createIndex('sites', 'idx_sites_multi', ['period_id', 'type']);  // composite
$alter->dropIndex('sites', 'idx_sites_period');
```

All methods are **idempotent**: `createIndex` uses `IF NOT EXISTS`
(MySQL equivalent handled separately); `dropIndex` is a no-op if the
index is absent.

Engine implementations: `DB\Alter\Mysql`, `DB\Alter\Postgres`,
`DB\Alter\Sqlite`.

---

## `DB\System\Manage` — System table CRUD

`Manage` is the high-level CRUD layer for `bdus_*` system tables.
It knows the schema of every system table (from JSON descriptors in
`lib/DB/System/Structure/`) and can create them on demand.

### System tables

```
bdus_api_keys          — API keys (SHA-256 hashes)
bdus_cfg_app           — App-level config properties
bdus_cfg_fields        — Field configurations
bdus_cfg_geoface       — GeoFace / map layer config
bdus_cfg_indexes       — User-defined DB indexes (name, columns, unique)
bdus_cfg_relations     — FK relations: from_tb.from_col → to_tb.to_col
bdus_cfg_tables        — Table configurations
bdus_cfg_templates     — Print templates (JSON, one row per table + name)
bdus_assemblage_analyses — Saved assemblage analyses
bdus_charts            — User-defined charts
bdus_file_links        — File ↔ record associations
bdus_files             — Uploaded file metadata
bdus_group_rules       — Per-group, per-table CRUD grants (optional JSON row filter)
bdus_groups            — User groups
bdus_table_locks       — Row subsets frozen against update/delete
bdus_geodata           — Geospatial features (WKT)
bdus_log               — Error / info log entries (written by LogDBHandler)
bdus_migrations        — Applied migration tracking
bdus_queries           — Saved search queries
bdus_rs                — Stratigraphic relation pairs (first/second = INTEGER record ids)
bdus_userlinks         — Manual cross-record links
bdus_users             — User accounts
bdus_user_groups       — User ↔ group membership
bdus_versions          — Record version snapshots
bdus_vocabularies      — Controlled vocabulary items
bdus_zotero_libs       — Zotero libraries configured for the app
bdus_zotero_links      — Record ↔ Zotero item links
```

### CRUD interface

```php
$mgr = new Manage($db);

// Create
$id = $mgr->addRow('bdus_vocabularies', [
    'tb' => 'sites', 'key' => 'category', 'value' => 'Settlement'
]);

// Read
$row  = $mgr->getById('bdus_users', 5);
$rows = $mgr->getBySQL('bdus_users', 'privilege <= ?', [30]);

// Update
$mgr->editRow('bdus_users', 5, ['oauth_sub' => '0000-0002-1825-0097']);

// Delete
$mgr->deleteRow('bdus_vocabularies', 12);

// Create table if not exists (idempotent)
$mgr->createTable('bdus_charts');
```

### Cross-engine schema helpers

These methods work identically on SQLite, MySQL, and PostgreSQL. Use them
instead of engine-specific catalog queries (`sqlite_master`, `PRAGMA`, etc.):

```php
$mgr->tableExists('bdus_cfg_relations');          // bool
$mgr->columnExists('bdus_users', 'oauth_sub');    // bool
$mgr->indexExistsPublic('bdus_users', 'users_email_idx'); // bool
$mgr->createIndex('bdus_geodata', 'geo_idx', ['table_link', 'id_link']); // idempotent
$mgr->addForeignKey('bdus_charts', 'charts_user_fk', 'user_id', 'bdus_users', 'id', 'CASCADE'); // idempotent
```

`addForeignKey` is idempotent: if the constraint already exists it is silently
skipped. On MySQL, `createIndex` automatically adds a prefix length `(191)`
to TEXT columns (required by the InnoDB key-length limit).

> **Engine rule:** never query `sqlite_master`, use `PRAGMA`, write
> `INSERT OR IGNORE`, or call `last_insert_rowid()` outside of an explicit
> `if ($db->getEngine() === 'sqlite')` guard. Use `$db->query($sql, $params, 'id')`
> to retrieve the last inserted ID on any engine.

---

## `DB\System\CreateApp` — New application wizard

`CreateApp` orchestrates the complete bootstrap for a new application:

1. Creates the SQLite DB file (or connects to the MySQL/PG DB).
2. Creates all `bdus_*` system tables via `Manage::createTable()`.
3. Creates the admin user in `bdus_users`.
4. Seeds `config.json` with the connection data and app name.
5. Creates the required directory structure under `projects/{app}/`.

Called by `Bdus\Controllers\NewApp::create()`.

---

## `DB\Export` — Data export

`DB\Export\Export` orchestrates export of record sets. Delegated to:

| Class | Output |
|---|---|
| `DB\Export\CSV` | Comma-separated values |
| `DB\Export\JSON` | JSON array |
| `DB\Export\XLSX` | Excel workbook (via PhpSpreadsheet) |

Called by `Bdus\Controllers\Record::exportRecords()`. The accepted formats are
listed in `DB\Export\Export::FORMATS`.

---

## `DB\Validate` — Schema validation

`Validate` runs a suite of checks and returns a structured report:

- **`DbCfgAlign`** — compares live DB columns vs. config-defined fields.
- **`SystemTables`** — verifies all required `bdus_*` tables exist.
- **`Filesystem`** — checks runtime directories exist and are writable.
- **`DumpExists`** — verifies at least one backup exists.
- **`Info`** — collects app metadata for display.

Called by `Bdus\Controllers\Config` (validation report and fixes).

---

## `DB\System\Snapshot` and `DB\Verify\MigrationVerifier`

Two helpers used by the v4 → v5 **major upgrade**:

- `Snapshot` takes a pre-upgrade copy of the project directory (a tar.gz of
  everything except `files/`, `cache/`, `backups/`, `export/`, and a raw copy of
  the SQLite file) into `projects/{app}/backups/`, so a failed upgrade can be
  rolled back by extracting the archive over the project.
- `MigrationVerifier` runs read-only integrity checks after the upgrade
  (`pass | warn | fail | skip` per check) against that baseline, e.g. row counts.

---

## `DB\LogDBHandler` — Monolog → DB

A custom Monolog handler that writes log records to `bdus_log`.
Used in production (debug mode off) so logs are visible in the admin UI.
In debug mode (`BRADYPUS_DEBUG=1`) logging falls back to `logs/error.log`.

---

## Config file location history

The `config.json` location evolved across major versions. `DB\DB` probes
candidates newest-first for backward compatibility:

| Version | Location |
|---|---|
| v5 (post-M018) | `projects/{app}/config.json` |
| v4 (post-M016) | `projects/{app}/cfg/config.json` |
| v4 (legacy) | `projects/{app}/cfg/app_data.json` |

See [DB migrations](./migrations) for the full migration history.
