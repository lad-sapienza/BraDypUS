---
title: Config & UAC
---

# Config & UAC

Two injected objects reach every controller: `Config\Config` (application
configuration) and `UAC\UAC` (access control). This page documents their
structure, APIs, and how they interact.

---

## `Config\Config` — application configuration

`Config` is a read/write facade over the application's configuration data.
It is injected into every controller via `$this->cfg`.

### Storage backends

Configuration has migrated across versions:

| Data | Storage (post-M019) | Legacy |
|---|---|---|
| Bootstrap (DB engine, credentials, `definition`) | `config.json` | same |
| App-level settings (status, `maxImageSize`, welcome) | `bdus_cfg_app` table | `config.json` |
| Table definitions | `bdus_cfg_tables` | JSON files in `cfg/` |
| Field definitions | `bdus_cfg_fields` | JSON files in `cfg/` |
| Relation definitions | `bdus_cfg_relations` | inline in table JSON |
| GeoFace config | `bdus_cfg_geoface` | separate JSON |

`Config` detects the available backend automatically (`LoadFromDB::isAvailable($db)`)
and loads from DB when possible, falling back to JSON files for pre-migration
apps. All write methods (`setTable`, `setFld`, etc.) mirror this: they write
to DB or to JSON, never both.

### In-memory structure

The loaded config is a nested PHP array with two top-level keys:

```
cfg
├── main
│   ├── name          ← app name (always = DB connection identifier)
│   ├── status        ← 'on' | 'off' | 'frozen'
│   ├── maxImageSize  ← max upload size in MB (0 = unlimited)
│   ├── welcome       ← HTML string for dashboard welcome page
│   ├── db_engine     ← 'sqlite' | 'mysql' | 'pgsql'
│   └── definition    ← free-text description of the application
└── tables
    └── {table_name}
        ├── name       ← same as key
        ├── label      ← human-readable table name
        ├── order      ← display sort order (int)
        ├── id_field   ← name of the human-readable identifier column
        ├── preview    ← optional Twig template for record preview
        ├── plugin     ← list of plugin table names ['ctx', 'ctx_bibliography', …]
        ├── rs         ← name of the RS field (if stratigraphic relations enabled)
        ├── link       ← outgoing link definitions (see below)
        ├── backlinks  ← incoming backlink definitions (see below)
        └── fields
            └── {field_name}
                ├── name       ← same as key
                ├── label      ← human-readable field name
                ├── type       ← 'text' | 'select' | 'date' | 'boolean' | 'int' | …
                ├── id_from_tb ← referenced table name (for FK fields)
                ├── vocab_tb   ← vocabulary table (for select fields)
                └── …
```

### Link and backlink definitions

**`link`** (outgoing) — describes that records in another table reference this one:

```json
[
  {
    "other_tb": "sites_contexts",
    "fld": [
      { "my": "id", "other": "id_link" }
    ]
  }
]
```

**`backlinks`** (incoming via plugin table) — format is a colon-delimited
string `ref_table:via_plugin:via_plugin_field`:

```json
["bibliography:sites_bibliography:id_link"]
```

### Read API

```php
$cfg->get(string $key, ?string $filter_key = null, ?string $filter_val = null)
```

Uses dot-notation. Wildcards (`*`) expand the matching level:

```php
$cfg->get('main.status')                    // 'on'
$cfg->get('main.*')                         // full main array
$cfg->get('tables.sites.label')             // 'Sites'
$cfg->get('tables.sites.id_field')          // 'site_code'
$cfg->get('tables.sites.fields.typology.type')  // 'select'
$cfg->get('tables.*', 'plugin_of', null)    // tables that are NOT plugins
$cfg->get('tables.*.label')                 // {sites: 'Sites', contexts: 'Contexts', …}
$cfg->get('tables.sites.fields.*.name')     // [name, typology, description, …]
```

Returns `false` if the key does not exist.

### Write API

| Method | Effect |
|---|---|
| `setMain(array $main)` | Save app-level settings (bootstrap keys → `config.json`; runtime settings → `bdus_cfg_app`) |
| `setTable(array $tbData)` | Upsert a table definition |
| `setFld(string $tb, string $fldName, array $data)` | Upsert a field definition |
| `renameFld(string $tb, string $old, string $new)` | Rename a field (preserves order) |
| `deleteFld(string $tb, string $fld)` | Delete a field |
| `renameTb(string $old, string $new)` | Rename a table |
| `deleteTb(string $tb)` | Delete a table |
| `sortTables(array $order)` | Reorder tables by name array |
| `save()` | Flush full in-memory state to the storage backend |

All write methods update both the in-memory state and the DB / JSON files
atomically — no stale-cache issues.

---

## `UAC\UAC` — access control

`UAC` enforces privilege levels on a per-action, per-table, and optionally
per-record basis. It is injected into every controller via `$this->uac`.

### Privilege constants

| Constant | Value | Meaning |
|---|---|---|
| `UAC::SUPERADM` | 1 | Super admin — full access including schema changes |
| `UAC::ADM` | 10 | Admin — user management, backups, logs |
| `UAC::UPDATE` / `UAC::DELETE` | 20 | Can update and delete records |
| `UAC::CREATE` | 25 | Editor — can create and edit own records |
| `UAC::READ` | 30 | Reader — read-only |
| `UAC::ENTER` | 39 | Can log in but has no data access |

Lower numeric value = higher privilege. A user with `privilege = 10` can do
everything that `20`, `25`, `30`, `39` allow, plus admin operations.

> **Note**: The `ROUTE_PRIVILEGE` map in `Router.php` uses string tiers
> (`'read'`, `'edit'`, `'admin'`, `'super_admin'`). `UAC::can()` uses the
> granular action strings below. Both systems are independent layers of
> the same privilege model.

### Actions

```php
$uac->can(string $action, ?string $onTable, ?int $onRecId, bool $user_owns): bool
```

| Action string | Allowed when |
|---|---|
| `'enter'` | `privilege ≤ ENTER` and app not `'off'` (superadmins bypass `'off'`) |
| `'read'` | `privilege ≤ READ` (global, or table-based, or record-subset-based) |
| `'create'` | `privilege ≤ CREATE` and app is `'on'` |
| `'update'` / `'delete'` | `privilege ≤ UPDATE`, or (`≤ CREATE` and `user_owns_record`) |
| `'multiple_edit'` | `privilege ≤ UPDATE` |
| `'admin'` | `privilege ≤ ADM` and app not `'frozen'` |
| `'super_admin'` | `privilege ≤ SUPERADM` (always allowed, even if app is `'off'`) |

### User Access Level (UAL)

Before `can()` can be called, the UAL must be set:

```php
$uac->setUAL([
    'global'  => UAC::READ,       // base privilege for all tables
    'sites'   => UAC::CREATE,     // table override
    'contexts'=> [UAC::UPDATE, 'creator = 5'],  // record-subset override
]);
```

Three granularities:

1. **Global** (`ual['global']`) — applies to every table.
2. **Table-based** (`ual['tablename']` = int) — overrides global for that table.
3. **Record-subset-based** (`ual['tablename']` = `[int, sql_condition]`) —
   applies the privilege only to records matching the raw SQL condition.
   `UAC` runs `SELECT count(*) FROM table WHERE (sql) AND id = rec_id` to
   check membership.

The UAL is built from `bdus_users` (global privilege) and
`bdus_user_table_privs` (per-table overrides) by `UAC\Loader`.

### Application status

The app `status` field further restricts access:

| Status | Effect |
|---|---|
| `'on'` | Normal operation |
| `'frozen'` | Read-only for everyone including admins; super-admins can still enter |
| `'off'` | Login disabled for everyone except super-admins |

### Usage in controllers

```php
// Check before reading
if (!$this->uac->can('read', $tb)) {
    return $this->response('error', 403, 'Forbidden');
}

// Check before writing
if (!$this->uac->can('update', $tb, $id, $isOwner)) {
    return $this->response('error', 403, 'Forbidden');
}

// Check for admin operations
if (!$this->uac->can('admin')) {
    return $this->response('error', 403, 'Forbidden');
}
```

---

## How Config and UAC are wired together

`App::route()` constructs both objects before calling the controller method:

```php
// Config
$config = new Config\Config(new \Adbar\Dot(), PROJ_DIR . 'cfg/', $this->db);

// UAC — reads status from config, privilege from DB
$uac = new UAC\UAC($config->get('main.status'), $this->db);
$ual = (new UAC\Loader($this->db))->load(Auth\CurrentUser::id());
$uac->setUAL($ual);

// Inject into controller
$ctrl->setCfg($config);
$ctrl->setUAC($uac);
```

The privilege tier map in `Router::ROUTE_PRIVILEGE` is checked first (coarse
HTTP-level gate); `UAC::can()` is called inside controller methods for
fine-grained table- and record-level checks.
