---
title: lib/ namespace map
---

# lib/ namespace map

Everything in `lib/` is a reusable library class. Controllers in `modules/`
consume these classes; they never access the database or filesystem directly
without going through a lib/ abstraction.

---

## Namespace overview

| Namespace / file | Path | Role |
|---|---|---|
| `Bdus\App` | `lib/Bdus/App.php` | Application orchestrator |
| `Bdus\Router` | `lib/Bdus/Router.php` | FastRoute dispatcher + privilege map |
| `Controller` | `lib/Controller.php` | Abstract base for all module controllers |
| `autoLoader` | `lib/autoLoader.php` | Custom class resolver |
| `Auth\CurrentUser` | `lib/Auth/CurrentUser.php` | Request-scoped authenticated user |
| `Auth\ApiKeyAuth` | `lib/Auth/ApiKeyAuth.php` | API key authentication |
| `JWT\JwtManager` | `lib/JWT/JwtManager.php` | JWT issue / verify / peek |
| `Config\Config` | `lib/Config/Config.php` | YAML config facade (dot-notation) |
| `Config\Load` | `lib/Config/Load.php` | Loads config from DB |
| `Config\LoadFromDB` | `lib/Config/LoadFromDB.php` | DB-backed config reader |
| `Config\ToDB` | `lib/Config/ToDB.php` | Persists config changes to DB |
| `Config\ToFiles` | `lib/Config/ToFiles.php` | Config → YAML file writer (legacy) |
| `Config\AppSettings` | `lib/Config/AppSettings.php` | App-level settings (status, name…) |
| `Config\GeofaceConfig` | `lib/Config/GeofaceConfig.php` | Geoface / map layer config |
| `DB\DB` | `lib/DB/DB.php` | PDO wrapper (multi-engine) |
| `DB\DBInterface` | `lib/DB/DBInterface.php` | DB contract |
| `DB\DBException` | `lib/DB/DBException.php` | DB exceptions |
| `DB\Inspect` | `lib/DB/Inspect.php` | Engine-dispatch for schema introspection |
| `DB\Inspect\Mysql` | `lib/DB/Inspect/Mysql.php` | MySQL introspection |
| `DB\Inspect\Postgres` | `lib/DB/Inspect/Postgres.php` | PostgreSQL introspection |
| `DB\Inspect\Sqlite` | `lib/DB/Inspect/Sqlite.php` | SQLite introspection |
| `DB\Alter` | `lib/DB/Alter.php` | Engine-dispatch for schema modification |
| `DB\Alter\Mysql` | `lib/DB/Alter/Mysql.php` | MySQL ALTER operations |
| `DB\Alter\Postgres` | `lib/DB/Alter/Postgres.php` | PostgreSQL ALTER operations |
| `DB\Alter\Sqlite` | `lib/DB/Alter/Sqlite.php` | SQLite ALTER operations |
| `DB\Export\Export` | `lib/DB/Export/Export.php` | Export orchestrator |
| `DB\Export\CSV` | `lib/DB/Export/CSV.php` | CSV export |
| `DB\Export\JSON` | `lib/DB/Export/JSON.php` | JSON export |
| `DB\Export\XLSX` | `lib/DB/Export/XLSX.php` | Excel export |
| `DB\Validate\Validate` | `lib/DB/Validate/Validate.php` | Validation runner |
| `DB\Validate\DbCfgAlign` | `lib/DB/Validate/DbCfgAlign.php` | DB ↔ config schema alignment |
| `DB\Validate\DumpExists` | `lib/DB/Validate/DumpExists.php` | Checks backup files |
| `DB\Validate\Filesystem` | `lib/DB/Validate/Filesystem.php` | Filesystem sanity |
| `DB\Validate\Info` | `lib/DB/Validate/Info.php` | App info collector |
| `DB\Validate\Resp` | `lib/DB/Validate/Resp.php` | Validation response builder |
| `DB\Validate\SystemTables` | `lib/DB/Validate/SystemTables.php` | Checks required system tables |
| `DB\System\Manage` | `lib/DB/System/Manage.php` | CRUD on system/data tables |
| `DB\System\CreateApp` | `lib/DB/System/CreateApp.php` | New application wizard |
| `DB\System\Migrate` | `lib/DB/System/Migrate.php` | Schema migration runner |
| `DB\System\Migrations\Mxxx` | `lib/DB/System/Migrations/` | 22 individual migration classes (M001–M022) |
| `DB\Engines\AvailableEngines` | `lib/DB/Engines/AvailableEngines.php` | Lists supported DB engines |
| `DB\LogDBHandler` | `lib/DB/LogDBHandler.php` | Monolog handler → bdus_logs table |
| `SQL\QueryObject` | `lib/SQL/QueryObject.php` | SQL query builder |
| `SQL\SafeQuery` | `lib/SQL/SafeQuery.php` | PDO-prepared statement executor |
| `SQL\ShortSql\ParseShortSql` | `lib/SQL/ShortSql/ParseShortSql.php` | ShortSQL → QueryObject |
| `SQL\ShortSql\*` | `lib/SQL/ShortSql/` | ShortSQL clause parsers (8 classes) |
| `SQL\Validator` | `lib/SQL/Validator.php` | SQL safety checks |
| `Record\Read` | `lib/Record/Read.php` | Fetches record + plugins + links + files |
| `Record\Edit` | `lib/Record/Edit.php` | Validates + persists record edits |
| `Record\Persist` | `lib/Record/Persist.php` | Low-level persistence layer |
| `UAC\UAC` | `lib/UAC/UAC.php` | Privilege enforcement |
| `UAC\Loader` | `lib/UAC/Loader.php` | Builds per-table UAL from DB |
| `Template\Loader` | `lib/Template/Loader.php` | Loads Twig print templates from DB |
| `Image\Resizer` | `lib/Image/Resizer.php` | Image resize / thumbnail (Intervention) |
| — | `lib/version.php` | `BDUS_VERSION` constant |

---

## Namespace detail

### `Auth\` — Request authentication

**`CurrentUser`** is a static request-scoped store. Populated once in
`constants.php` from validated JWT claims (or API key record), then read
anywhere:

```php
Auth\CurrentUser::id()              // int|null
Auth\CurrentUser::privilege()       // int|null  (1=superadmin … 40=readonly)
Auth\CurrentUser::isAuthenticated() // bool
Auth\CurrentUser::isApiKey()        // bool (false for JWT users)
```

**`ApiKeyAuth`** handles `Authorization: Bearer {key}` / `?api_key=` auth.
Plain-text keys are never stored — only SHA-256 hashes in `bdus_api_keys`.

---

### `JWT\` — Token management

**`JwtManager`** is a thin wrapper around `firebase/php-jwt`.

| Method | Purpose |
|---|---|
| `generate(user, app)` | Issues a signed JWT (1-day expiry) |
| `decode(token, app)` | Verifies signature + expiry, returns claims |
| `peekApp(token)` | Reads `app` claim without verifying (loads the right secret) |
| `refresh(token, app)` | Issues a new token from a still-valid one |

Per-app secrets live at `projects/{app}/cfg/.jwt_secret` (mode 0600),
generated on first use if absent.

---

### `Config\` — Application configuration

**`Config`** provides dot-notation access to the merged config:

```php
$cfg->get('main.status')           // app status string, e.g. 'on'
$cfg->get('tables.0.name')         // first table name
$cfg->get('geoface.layers.0.url')  // first map layer URL
```

Config is stored in DB (since M011) in `bdus_cfg_*` tables.
`Config\Load` reads from DB; `Config\ToDB` / `Config\ToFiles` persist changes.

See [Config & UAC](./config) for the full schema.

---

### `DB\` — Database abstraction

**`DB\DB`** wraps PDO and dispatches schema operations to engine-specific
implementations. Key method:

```php
$db->query($sql, $params, 'read')      // returns rows array
$db->query($sql, $params, 'boolean')   // returns true|false
$db->query($sql, $params, 'id')        // returns last insert id
```

**`DB\Inspect`** introspects the live schema (column list, indexes, …).
**`DB\Alter`** modifies the schema (add/rename/drop columns and tables).
Both dispatch to engine-specific `Mysql`, `Postgres`, or `Sqlite` classes.

**`DB\System\Manage`** is the high-level CRUD layer for system and data tables:

```php
$mgr = new Manage($db);
$mgr->getBySQL('bdus_users', 'email = ?', [$email]);
$mgr->editRow('bdus_users', $id, ['oauth_sub' => $sub]);
```

**`DB\System\CreateApp`** orchestrates new-application creation: creates the
DB, installs system tables, seeds initial config.

**`DB\System\Migrate`** runs pending migrations. See [DB migrations](./migrations).

See [Database layer](./database) for the full reference.

---

### `SQL\` — Query building

**`SQL\QueryObject`** is the safe SQL builder. All data queries go through it.

**`SQL\ShortSql\ParseShortSql`** parses the ShortSQL DSL used by the public
REST API into a `QueryObject`. See [SQL layer](./sql-layer).

**`SQL\SafeQuery`** is the low-level PDO executor used by `DB\DB`.

---

### `Record\` — Record lifecycle

**`Record\Read`** assembles a complete record response:
fields + plugin rows + manual links + files.

**`Record\Edit`** validates incoming data and delegates to `Record\Persist`.

**`Record\Persist`** writes a single atomic change: INSERT / UPDATE / DELETE.

See [Record lifecycle](./record-lifecycle).

---

### `UAC\` — Access control

**`UAC\UAC`** enforces privilege levels per-table. Privilege levels:

| Level | Constant | Meaning |
|---|---|---|
| 1 | `UAC::SUPERADM` | Super admin — full access including schema changes |
| 10 | `UAC::ADM` | Admin — user management, backups, logs |
| 25 | `UAC::CREATE` | Editor — read + write records |
| 30 | `UAC::READ` | Reader — read-only |
| 40 | `UAC::READONLY` | Strict read-only (also public API keys) |

**`UAC\Loader`** builds the per-user, per-table privilege list (UAL) from
`bdus_user_table_privileges`. See [Config & UAC](./config).

---

### `Template\` — Print templates

**`Template\Loader`** reads Twig template source from `bdus_cfg_templates`
(DB-backed since M011). Provides compiled templates for print / preview.

---

### `Image\` — Image handling

**`Image\Resizer`** uses `intervention/image` to resize uploads to thumbnails
and to serve resized versions on demand from `cache/img/`.
