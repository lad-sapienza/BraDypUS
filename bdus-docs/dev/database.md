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

For SQLite the DB file is always at `projects/{app}/db/bdus.sqlite`.
For MySQL / PostgreSQL the connection parameters (`db_host`, `db_name`,
`db_username`, `db_password`, optional `db_port`) are also in `config.json`.

---

## `DB\DB` — PDO wrapper

`DB` is the central class every controller and lib class uses for all
database access. It wraps PDO with:

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

---

## `DB\Inspect` — Schema introspection

`Inspect` dispatches to an engine-specific implementation and exposes a
uniform interface for reading the live schema:

```php
$inspect = new \DB\Inspect($db);
$inspect->getColumns('sites');          // ['id', 'name', 'typology', …]
$inspect->getColumnTypes('sites');      // ['id' => 'INTEGER', 'name' => 'TEXT', …]
$inspect->tableExists('bdus_users');    // bool
$inspect->listTables();                 // all table names in the DB
```

Engine implementations: `DB\Inspect\Mysql`, `DB\Inspect\Postgres`,
`DB\Inspect\Sqlite`.

---

## `DB\Alter` — Schema modification

`Alter` dispatches to engine-specific DDL for safe schema changes:

```php
$alter = new \DB\Alter($db);
$alter->addColumn('sites', 'notes', 'TEXT');
$alter->renameColumn('sites', 'notes', 'description');
$alter->dropColumn('sites', 'description');
$alter->addTable('measurements', $columnDefs);
$alter->dropTable('measurements');
$alter->renameTable('measurements', 'measures');
```

Engine implementations: `DB\Alter\Mysql`, `DB\Alter\Postgres`,
`DB\Alter\Sqlite`. SQLite's lack of `DROP COLUMN` is handled via the
copy-and-rename table pattern.

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
bdus_cfg_relations     — Cross-table relation definitions
bdus_cfg_tables        — Table configurations
bdus_cfg_templates     — Print templates (Twig source)
bdus_charts            — User-defined charts
bdus_file_links        — File ↔ record associations
bdus_files             — Uploaded file metadata
bdus_geodata           — Geospatial features (WKT)
bdus_log               — Error / info log entries
bdus_migrations        — Applied migration tracking
bdus_queries           — Saved search queries
bdus_rs                — Stratigraphic relation pairs
bdus_userlinks         — Manual cross-record links
bdus_users             — User accounts
bdus_user_table_privs  — Per-user per-table privilege overrides
bdus_versions          — Record version snapshots
bdus_vocabularies      — Controlled vocabulary items
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

Called by `new_app_ctrl::create()`.

---

## `DB\Export` — Data export

`DB\Export\Export` orchestrates export of record sets. Delegated to:

| Class | Output |
|---|---|
| `DB\Export\CSV` | Comma-separated values |
| `DB\Export\JSON` | JSON array |
| `DB\Export\XLSX` | Excel workbook (via PhpSpreadsheet) |

Called by `record_ctrl::exportRecords()`.

---

## `DB\Validate` — Schema validation

`Validate` runs a suite of checks and returns a structured report:

- **`DbCfgAlign`** — compares live DB columns vs. config-defined fields.
- **`SystemTables`** — verifies all required `bdus_*` tables exist.
- **`Filesystem`** — checks runtime directories exist and are writable.
- **`DumpExists`** — verifies at least one backup exists.
- **`Info`** — collects app metadata for display.

Called by `config_ctrl::getValidationReport()` and `config_ctrl::fix()`.

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
