---
title: DB migrations
---

# DB migrations

BraDypUS uses a lightweight, code-first migration system. Each migration is
an idempotent PHP class that runs exactly once per database and is tracked in
`bdus_migrations`.

---

## How migrations run

`DB\System\Migrate::run()` is called from `Bdus\App::start()` on **every
request** (not only at login). Each migration:

1. Is checked against `bdus_migrations` — if already applied, skipped.
2. Calls `Mxxx::run($manage)` if pending.
3. Records its `NAME` in `bdus_migrations` so it never runs again.

The overhead on steady-state requests is one `SELECT` on `bdus_migrations` —
negligible.

**Order matters** — `ALL_MIGRATIONS` in `Migrate.php` is the canonical
ordered list. Never reorder existing entries.

---

## Writing a new migration

### 1. Create the class

```php
// lib/DB/System/Migrations/M023_MyChange.php
namespace DB\System\Migrations;

use DB\System\Manage;

class M023_MyChange
{
    public const NAME = 'M023_my_change';   // unique snake_case string

    public static function run(Manage $manage): void
    {
        $db = $manage->getDb();

        // ── Guard: check preconditions before acting ──────────────────────
        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='bdus_users'",
            [], 'read'
        ) ?: [];
        if (empty($tables)) {
            return;  // table absent — no-op
        }

        // ── Add a column (idempotent) ─────────────────────────────────────
        $cols = $db->query('PRAGMA table_info(bdus_users)', [], 'read') ?: [];
        $existing = array_column($cols, 'name');

        if (!in_array('new_column', $existing, true)) {
            $db->query(
                "ALTER TABLE bdus_users ADD COLUMN new_column TEXT",
                [], 'boolean'
            );
        }

        // ── Create a table (use Manage for cross-engine compat) ───────────
        // $manage->createTable('bdus_new_table');

        // ── Data migration ────────────────────────────────────────────────
        // $db->query("UPDATE bdus_users SET new_column = 'default' WHERE …", [], 'boolean');
    }
}
```

### 2. Register in `Migrate.php`

Add the class to the `ALL_MIGRATIONS` array in
`lib/DB/System/Migrate.php`, **at the end**:

```php
use DB\System\Migrations\M024_MyChange;

public const ALL_MIGRATIONS = [
    // … existing migrations …
    M023_ZoteroTables::class,
    M024_MyChange::class,     // ← append here
];
```

### 3. Write an integration test

```php
// tests/Integration/M024MyChangeTest.php
class M024MyChangeTest extends BdusTestCase
{
    public function testColumnAdded(): void
    {
        // Run migration
        M024_MyChange::run(new Manage(static::$db));

        // Verify
        $cols = static::$db->query('PRAGMA table_info(bdus_users)', [], 'read');
        $names = array_column($cols, 'name');
        $this->assertContains('new_column', $names);
    }

    public function testIdempotent(): void
    {
        M024_MyChange::run(new Manage(static::$db));
        M024_MyChange::run(new Manage(static::$db));  // must not throw
        $this->assertTrue(true);
    }
}
```

---

## Rules for migration authors

| Rule | Rationale |
|---|---|
| **Always guard** — check whether the column / table / index already exists before creating it | Migrations run on upgrade; the object may already be there |
| **Never drop user data** | Migrations are irreversible once deployed |
| **Use `Manage::createTable()` for new system tables** | Handles engine differences automatically |
| **Use `$db->query()` with bound params** for data migrations | Prevents SQL injection even in DDL context |
| **Keep `NAME` unique and descriptive** | It is the idempotency key |
| **Append only — never reorder** | Migration order matters for dependencies |
| **SQLite-only DDL is OK** | All production installs use SQLite; MySQL / PG users are expected to apply DDL manually if needed |

---

## Migration history (M001–M027)

| Migration | What it does |
|---|---|
| M001 | Create `bdus_user_table_privs` (per-table privilege overrides) |
| M002 | Create `bdus_file_links` (file ↔ record associations) |
| M003 | Refactor `bdus_queries` schema |
| M004 | Refactor `bdus_charts` schema |
| M005 | Create `bdus_api_keys` |
| M006 | Add `privilege` column to `bdus_api_keys` |
| M007 | Repair orphaned file links |
| M008 | Rename bare system tables to `bdus_*` |
| M009 | Add `operation` column to `bdus_versions` |
| M010 | Fix `bdus_versions` schema inconsistencies |
| M011 | Move YAML config → DB (`bdus_cfg_*` tables) |
| M012 | Add extra columns to `bdus_cfg_tables` |
| M013 | Create `bdus_cfg_relations` (initial v1 schema) |
| M014 | Move GeoFace config → DB (`bdus_cfg_geoface`) |
| M015 | Delete now-redundant JSON config files from `cfg/` |
| M016 | Rename `cfg/app_data.json` → `cfg/config.json` |
| M017 | Cleanup orphaned files in `cfg/` |
| M018 | Move `cfg/config.json` → `config.json` (project root) |
| M019 | Move app settings → DB (`bdus_cfg_app`) |
| M020 | Deduplicate relation entries; add UNIQUE(from_tb, to_tb) |
| M021 | Back-fill `plugin_of` field in `bdus_cfg_tables` |
| M022 | Add `oauth_provider` + `oauth_sub` columns to `bdus_users` |
| M023 | Create `bdus_zotero_libs` and `bdus_zotero_links` for Zotero integration |
| M024 | Drop legacy v4 columns (`date`, `text`, `vals` from `bdus_queries`; `sqltext`, `date` from `bdus_charts`) |
| M025 | Add `color` column to `bdus_cfg_app` for runtime primary-colour customisation |
| M026 | **Refactor `bdus_cfg_relations`** — replaces `(from_tb, to_tb, fld JSON, sort)` with `(from_tb, from_col, to_tb, to_col, on_delete, on_update)`. One row per FK column pair. UNIQUE moved to `(from_tb, from_col)`. Expands existing JSON blobs into individual rows. |
| M027 | Create `bdus_cfg_indexes` — tracks user-defined and auto-generated (FK) indexes per table. |

---

## Pre-flight migrations (outside the normal loop)

Two one-time upgrades run **before** the normal migration loop, on every
request, from `Bdus\App::start()`:

**`Migrate::maybeRemovePrefix()`** — if tables are still named with the
legacy `APP__` prefix (v4 apps), renames them, strips the prefix from
`tables.json`, field configs, and data columns.

**`Migrate::maybeAddBdusPrefix()`** — if system tables are still bare
(e.g. `users` instead of `bdus_users`), renames them.

Both methods are fully idempotent and no-ops on already-migrated databases.
