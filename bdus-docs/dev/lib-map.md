---
title: lib/ namespace map
---

# lib/ namespace map

Everything in `lib/` is a reusable library class. Controllers in `controllers/`
consume these classes; they never access the database or filesystem directly
without going through a lib/ abstraction.

---

## Namespace overview

| Namespace / file | Path | Role |
|---|---|---|
| `Bdus\App` | `lib/Bdus/App.php` | Application orchestrator |
| `Bdus\Router` | `lib/Bdus/Router.php` | FastRoute dispatcher + privilege map |
| `Bdus\Dispatcher` | `lib/Bdus/Dispatcher.php` | Auth gate + DI + controller invocation |
| `Bdus\Controller` | `lib/Bdus/Controller.php` | Abstract base for all controllers |
| `Bdus\Utils` | `lib/Bdus/Utils.php` | Static utility helpers |
| `Auth\CurrentUser` | `lib/Auth/CurrentUser.php` | Request-scoped authenticated user |
| `Auth\ApiKeyAuth` | `lib/Auth/ApiKeyAuth.php` | API key authentication |
| `Auth\RateLimiter`, `Auth\ClientIp` | `lib/Auth/RateLimiter.php`, `lib/Auth/ClientIp.php` | Request limits for API keys and failed logins; client address behind a trusted proxy |
| `Record\Purge` | `lib/Record/Purge.php` | Retention: delete or anonymise old records and their version history (`bin/purge-records.php`) |
| `Auth\Authorization` | `lib/Auth/Authorization.php` | Privilege check helpers |
| `Auth\Access` | `lib/Auth/Access.php` | Table- and row-level access: group rules and locks ([Groups & permissions](./groups-permissions)) |
| `Auth\Password` | `lib/Auth/Password.php` | bcrypt hash / verify |
| `JWT\JwtManager` | `lib/JWT/JwtManager.php` | JWT issue / verify / peek |
| `Config\Config` | `lib/Config/Config.php` | Config facade (dot-notation access) |
| `Config\Load` | `lib/Config/Load.php` | Config bootstrap — picks DB or file loader |
| `Config\LoadFromDB` | `lib/Config/LoadFromDB.php` | DB-backed config reader |
| `Config\ToDB` | `lib/Config/ToDB.php` | Persists config changes to DB |
| `Config\ToFiles` | `lib/Config/ToFiles.php` | Config → JSON file writer (legacy fallback) |
| `Config\AppSettings` | `lib/Config/AppSettings.php` | App-level settings reader |
| `Config\GeofaceConfig` | `lib/Config/GeofaceConfig.php` | GeoFace / map layer config |
| `Config\ConfigException` | `lib/Config/ConfigException.php` | Config-specific exception |
| `DB\DB` | `lib/DB/DB.php` | PDO wrapper (SQLite / MySQL / PostgreSQL) |
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
| `DB\Validate\DumpExists` | `lib/DB/Validate/DumpExists.php` | Checks backup tool availability |
| `DB\Validate\Filesystem` | `lib/DB/Validate/Filesystem.php` | .htaccess / filesystem sanity |
| `DB\Validate\Info` | `lib/DB/Validate/Info.php` | App info collector |
| `DB\Validate\Resp` | `lib/DB/Validate/Resp.php` | Validation response builder |
| `DB\Validate\SystemTables` | `lib/DB/Validate/SystemTables.php` | Checks required system tables |
| `DB\System\Manage` | `lib/DB/System/Manage.php` | CRUD on system/data tables |
| `DB\System\CreateApp` | `lib/DB/System/CreateApp.php` | New application wizard |
| `DB\System\Migrate` | `lib/DB/System/Migrate.php` | Schema migration runner |
| `DB\System\Upgrader` | `lib/DB/System/Upgrader.php` | Applies the pending minor migrations of one app (admin endpoint and `bin/upgrade-app.php`); refuses a major upgrade |
| `DB\System\Migrations\Mxxx` | `lib/DB/System/Migrations/` | 24 migration classes (M001–M024) |
| `DB\Engines\AvailableEngines` | `lib/DB/Engines/AvailableEngines.php` | Lists supported DB engines |
| `DB\LogDBHandler` | `lib/DB/LogDBHandler.php` | Monolog handler → `bdus_log` table |
| `SQL\QueryFromRequest` | `lib/SQL/QueryFromRequest.php` | Main query builder — converts `$request` array to SQL |
| `SQL\QueryObject` | `lib/SQL/QueryObject.php` | Low-level fluent query builder (internal) |
| `SQL\Filter\JsonFilter` | `lib/SQL/Filter/JsonFilter.php` | Directus-style filter → SQL WHERE |
| `SQL\Filter\FilterException` | `lib/SQL/Filter/FilterException.php` | Filter-specific exception |
| `SQL\Validator` | `lib/SQL/Validator.php` | Field / operator allow-list checks |
| `SQL\SqlException` | `lib/SQL/SqlException.php` | SQL-layer exception |
| `Record\Read` | `lib/Record/Read.php` | Fetches record + plugins + links + files |
| `Record\Edit` | `lib/Record/Edit.php` | Validates + prepares record edits |
| `Record\Persist` | `lib/Record/Persist.php` | Low-level persistence (INSERT/UPDATE/DELETE) |
| `Template\Loader` | `lib/Template/Loader.php` | Loads Twig print templates from DB |
| `Template\Assign` | `lib/Template/Assign.php` | Record layouts per group: which templates a user's groups assign |
| `Image\Resizer` | `lib/Image/Resizer.php` | Resize / convert an uploaded image in place (Intervention) |
| `Image\Presets` | `lib/Image/Presets.php` | Derived-image presets: validation, built-in `thumb`, lookup |
| `Image\Watermark` | `lib/Image/Watermark.php` | Optional text watermark of a preset: validation, text resolution, rendering (bundled DejaVu Sans) |
| `Image\Derivative` | `lib/Image/Derivative.php` | Generates a preset version into `cache/`, purges it |
| `Zotero\Client` | `lib/Zotero/Client.php` | Zotero API HTTP client |
| `Zotero\ZoteroException` | `lib/Zotero/ZoteroException.php` | Zotero-specific exception |
| `Pleiades\Client` | `lib/Pleiades/Client.php` | Pleiades gazetteer API HTTP client |
| `Pleiades\PleiadesException` | `lib/Pleiades/PleiadesException.php` | Pleiades-specific exception |
| — | `lib/bootstrap.php` | Constants, DB connection, auth setup |
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

**`ApiKeyAuth`** handles `Authorization: Bearer {key}` auth (the `?api_key=` query-string form was removed: it ended up in access logs).
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

Per-app secrets live at `projects/{app}/.jwt_secret` (mode 0600),
generated on first use if absent. The legacy location
`projects/{app}/cfg/.jwt_secret` is probed for backwards compatibility.

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

See [Config & access control](./config) for the full schema.

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

**`SQL\QueryFromRequest`** is the main entry point for all data queries.
It converts a structured `$request` PHP array (with a `type` key: `all`,
`fast`, `sqlExpert`, or `filter`) into a PDO-prepared SELECT statement.
See [SQL layer](./sql-layer).

**`SQL\Filter\JsonFilter`** parses Directus-style filter arrays
(`['field' => ['_eq' => 'value']]`) into SQL WHERE clauses.

**`SQL\QueryObject`** is the low-level fluent builder used internally by
`QueryFromRequest`. Not called directly by controllers.

---

### `Record\` — Record lifecycle

**`Record\Read`** assembles a complete record response:
fields + plugin rows + manual links + files.

**`Record\Edit`** validates incoming data and delegates to `Record\Persist`.

**`Record\Persist`** writes a single atomic change: INSERT / UPDATE / DELETE.

See [Record lifecycle](./record-lifecycle).

---

### `Auth\` — Access control

**`Auth\Authorization`** holds the numeric privilege constants and
`can()`, the single check used by controllers. Privilege levels:

| Level | Constant | Meaning |
|---|---|---|
| 1 | `Authorization::SUPERADM` | Super admin — full access including schema changes |
| 10 | `Authorization::ADM` | Admin — user management, backups, logs |
| 20 | `Authorization::UPDATE` | Writer — read + update records |
| 25 | `Authorization::CREATE` | Self writer — read + create, edit own records |
| 30 | `Authorization::READ` | Reader — read-only |
| 39 | `Authorization::ENTER` | Can log in, no data access |

See [Config & access control](./config).

---

### `Template\` — Print templates

**`Template\Loader`** reads Twig template source from `bdus_cfg_templates`
(DB-backed since M011). Provides compiled templates for print / preview.

**`Template\Assign`** resolves the templates a user's groups oblige them to use on a table
(`bdus_groups.layouts`, M051): union over groups, unknown names ignored, `null` = no restriction.
See [Groups & permissions](./groups-permissions#record-layouts-per-group).

---

### `Image\` — Image handling

**`Image\Resizer`** uses `intervention/image` (GD) to downscale and/or convert an
uploaded image in place, according to the app settings (`maxImageSize`, `imageConvert`, …).

**`Image\Presets`** validates and normalises the derived-image presets
(`{name, width, height, fit, format, quality}`; `fit` is `cover` or `inside`), resolves
the effective list (the built-in `thumb` is added when not defined) and finds one by name.
The list is stored as JSON in `bdus_cfg_app.image_presets` (read and written by
`Config\AppSettings`, exposed as `main.imagePresets`, saved by `Config::save_app_properties`).

**`Image\Watermark`** owns the optional `watermark` of a preset
(`{text, position, color, background, opacity, margin, size}`): `normalize()` validates it when the
preset is saved (called by `Presets::normalize`), `resolveText()` expands `{{attribution}}` and sanitises
the result at render time (no text when the template needs an attribution the file lacks), `apply()`
draws box and text with the bundled font `lib/Image/fonts/DejaVuSans.ttf` (GD needs a font file and
FreeType, both present in the image).

**`Image\Derivative`** builds one version of one file: `generate($src, $dest, $preset, $attribution)`
decodes, crops (`cover`) or scales down (`inside`), encodes WebP/JPG into a hidden temp file
and renames it into place. It refuses unreadable sources, unsupported types and sources over
100 megapixels. `purgeFile($projDir, $id)` removes every version of a file
(`projects/{app}/cache/*/{id}.*`, failure markers included); `purgePreset()` empties a
preset's folder.

**Serving and lifecycle.** `File::serveDerived` (`GET /api/file/{preset}/{id}.{ext}`, public)
looks the preset up, generates on first request into `cache/{preset}/{id}.{ext}` and answers
with `ETag` revalidation. A failure is a plain 404; a source that failed leaves
`{id}.{ext}.fail` and is not retried until it changes. The versions are a rebuildable mirror:
`purgeFile` is called wherever a file or its metadata changes (`File::replaceFile`,
`File::updateFile`, `Record::deleteFile`, `Persist` record delete, and the id-reuse guard in
`Record::uploadFile` and `Import::importPhotos`), and `Config::save_app_properties` purges the
folder of a preset that was changed or removed. `cache/` is excluded from `docker-backup.sh`.
