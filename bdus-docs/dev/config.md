---
title: Config & access control
---

# Config & access control

Two injected objects reach every controller: `Config\Config` (application
configuration) and `Auth\Authorization` (access control). This page documents their
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
| App-level settings (status, `maxImageSize`, image conversion, welcome) | `bdus_cfg_app` table | `config.json` |
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
│   ├── maxImageSize  ← downscale uploaded images above this px bound (0 = off)
│   ├── imageConvert  ← convert uploaded images to imageFormat (bool, default false)
│   ├── imageFormat   ← 'webp' | 'jpg', target format when imageConvert is on
│   ├── imageQuality  ← 1-100, default 85, used when imageConvert is on
│   ├── imageDpi      ← resolution to embed, default 72, used when imageConvert is on
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

## `Auth\Authorization` — access control

Access is decided by a single **tier** (privilege level) per principal: a
column of `bdus_users` for people, of `bdus_api_keys` for API keys. There is
no per-table or per-record access control yet (see the
[groups & permissions design note](./groups-permissions-design)).

### Privilege constants

| Constant | Value | Meaning |
|---|---|---|
| `Authorization::SUPERADM` | 1 | Super admin — full access including schema changes |
| `Authorization::ADM` | 10 | Admin — user management, backups, logs |
| `Authorization::UPDATE` | 20 | Can update and delete records |
| `Authorization::CREATE` | 25 | Editor — can create and edit own records |
| `Authorization::READ` | 30 | Reader — read-only |
| `Authorization::ENTER` | 39 | Can log in but has no data access |

Lower numeric value = higher privilege. A user with `privilege = 10` can do
everything that `20`, `25`, `30`, `39` allow, plus admin operations.

### Checking a privilege

```php
\Auth\Authorization::can(string $privilege = 'edit', ?int $creator = null): bool
```

| Privilege string | Allowed when |
|---|---|
| `'enter'` | `privilege ≤ 39` and app not `'off'` (super admins bypass `'off'`) |
| `'read'`, `'preview'` | `privilege ≤ 30` and app not `'off'` |
| `'add_new'` | `privilege ≤ 25` and app not `'frozen'` |
| `'edit'` | `privilege ≤ 20`, or (`≤ 25` and `$creator` is the current user); app not `'frozen'` |
| `'delete'` | as `'edit'` for the tier (a self writer deletes their own); group rules grant it separately |
| `'multiple_edit'` | `privilege ≤ 20` and app not `'frozen'` |
| `'admin'` | `privilege ≤ 10` and app not `'frozen'` |
| `'super_admin'` | `privilege = 1` (always allowed, even if app is `'off'`) |

Any other string returns `false`.

`can()` takes a third argument, `table:`. With it the answer is "may the user do
this on at least some rows of that table": the tier decides first, and the
user's group rules (`Auth\Access`, see the
[groups & permissions design note](./groups-permissions-design)) can grant
more. The row-level part is enforced where queries are built:
`SQL\QueryFromRequest` ANDs the user's read condition into the WHERE clause of
every list, search, export, chart, GeoFace and assemblage query. Admins and
super admins bypass rules and locks.

```php
if (!\Auth\Authorization::can('read', table: $tb)) { … }
```

> **Note**: the `ROUTE_PRIVILEGE` map in `Router.php` uses string tiers
> (`'read'`, `'edit'`, `'admin'`, `'super_admin'`) and is the single source of
> truth for API-key access. `Authorization::can()` is the one for JWT users.
> The two are independent layers of the same model and can diverge.

### Application status

The app `status` field further restricts access:

| Status | Effect |
|---|---|
| `'on'` | Normal operation |
| `'frozen'` | Read-only for everyone including admins; super-admins can still enter |
| `'off'` | Login disabled for everyone except super-admins |

### Usage in controllers

```php
if (!\Auth\Authorization::can('read')) {
    $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
    return;
}

// Owner-aware edit (self_writer may edit their own records)
if (!\Auth\Authorization::can('edit', $record['creator'])) { … }
```

---

## How Config and access control are wired together

`Dispatcher::dispatch()` builds `Config` and tells `Authorization` the app
status before calling the controller method:

```php
$config = new Config(new Dot(), MAIN_DIR . 'projects/' . APP . '/', $this->db);
$ctrl->setCfg($config);
\Auth\Authorization::setAppStatus($config->get('main.status'));
```

The privilege tier map in `Router::ROUTE_PRIVILEGE` is checked first (coarse
HTTP-level gate); `Authorization::can()` is called inside controller methods
for the finer checks.
