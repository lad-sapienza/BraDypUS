# BraDypUS — Technical Reference for Developers & AI Agents

BraDypUS is a PHP + Vue 3 web application for managing relational databases in the
humanities/archaeology domain. This repository is a monorepo:

| Directory | Content |
|---|---|
| `bdus-api/` | PHP 8.4 JSON API (no framework; FastRoute + Composer libraries) |
| `bdus-app/` | Vue 3 SPA (Vite, Pinia, Vue Router, Ant Design Vue) |
| `bdus-docs/` | VitePress documentation (user guide, developer docs, API reference) |

> **If you are an AI agent starting a new session**: read this file, then
> `CHANGELOG.md` (repo root) for recent changes, and the developer docs in
> `bdus-docs/dev/` (architecture, database, record lifecycle, lib map, testing)
> for the details this file only summarises.

---

## API layout (`bdus-api/`)

```
index.php          single entry point: CORS, error envelope, bootstrap, dispatch
controllers/       Bdus\Controllers\{Name} — one class per module, extends Bdus\Controller
lib/               everything else, PSR-4 under the root namespace (Auth\, DB\, Record\, …)
  bootstrap.php    constants, autoload, app + JWT resolution
  Bdus/            App (kernel), Router (routes + privilege map), Dispatcher, Controller
openapi.yaml       API contract — keep in sync with the routes
projects/{app}/    runtime data per application (NOT committed): config.json,
                   .jwt_secret, files/, backups/, export/, db/
tests/             Unit/, Integration/, api/ (hurl phases), Support/, fixtures/
```

### Routing and request flow

All requests hit `index.php`. `Bdus\Router::dispatch()` matches the path with
FastRoute and sets `$_GET['obj']` (controller class) / `$_GET['method']`;
`Bdus\Dispatcher` then applies the auth gate, injects dependencies and calls the
method. See `bdus-docs/dev/architecture.md` for the full trace.

- **URL scheme (v5.9.0):** application-scoped endpoints are `/{app}/api/…`;
  only `GET /api/auth/apps`, `POST /api/new-app`, `GET /api/new-app/status` and
  `GET /api/info` are bare (`Router::APP_INDEPENDENT`). Wrong shape → `404`
  (`app_prefix_required` / `app_prefix_not_allowed`); URL app ≠ JWT app →
  `403 app_mismatch`.
- **Privilege tiers:** `Router::ROUTE_PRIVILEGE` (`'Bdus\\Controllers\\X::method'`
  → `none | read | edit | admin | super_admin`). A route not listed defaults to
  `super_admin`. Always add the entry when adding a route.
- **Auth:** `Authorization: Bearer <JWT>` (per-app secret in
  `projects/{app}/.jwt_secret`) or an API key (hash in `bdus_api_keys`). The
  Dispatcher re-reads `privilege` and `token_version` from `bdus_users`, so the
  JWT value is never trusted alone. No PHP sessions.

### Controller conventions

```php
namespace Bdus\Controllers;

class Thing extends \Bdus\Controller
{
    public function getThings(): void
    {
        if (!\Auth\Authorization::can('read')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $this->returnJson(['status' => 'success', 'data' => $result]);
    }
}
```

- Injected: `$this->db` (`DB\DBInterface`), `$this->cfg` (`Config\Config`),
  `$this->uac` (`UAC\UAC`), `$this->log`; request data in `$this->get`,
  `$this->post` (JSON body merged), `$this->request`.
- `Authorization::can()` strings (exact; a typo silently yields `false`):
  `'enter'` `'read'` `'preview'` `'add_new'` `'edit'` `'multiple_edit'`
  `'admin'` `'super_admin'`. Numeric thresholds are the `UAC\UAC` constants
  (`SUPERADM=1`, `ADM=10`, `UPDATE/DELETE=20`, `CREATE=25`, `READ=30`, `ENTER=39`).
- **Errors are HTTP 200 with `{status:'error', code}`**; the frontend translates
  `code`. Only the gates (401/403/404/405/409) use HTTP status codes.

### i18n convention (critical)

- API endpoints return **code strings, never translated text**.
- The SPA translates with `t(code)` from `bdus-app/src/locale/{en,it}.json`.
- A new message: add the key to **both** locale files and return the key.

### Config access

Application config (tables, fields, relations, templates, geoface, app settings)
lives in the database (`bdus_cfg_*`); only DB credentials stay in
`projects/{app}/config.json`.

```php
$this->cfg->get('main.name');
$this->cfg->get('tables.mytb.label');
$this->cfg->get('tables.*.name');   // all table names, in config order
```

`get('tables')` alone is not valid. **Config order is meaningful**: tables and
fields appear in the order defined in the config; never sort them.

---

## DB layer (`lib/DB/`)

- `DB\DB` — PDO wrapper (SQLite / MySQL / PostgreSQL), native prepared
  statements, exceptions on error. `query($sql, $params, $type)` with `$type`
  = `'read'` (default) | `'boolean'` | `'id'` | `'affected'` | int (column N of
  first row). `exec`, `execInTransaction`, `beginTransaction/commit/rollBack`,
  `saveSnapshot()` (record versioning), `getEngine()`.
- `DB\System\Manage` — CRUD and DDL helpers for `bdus_*` system tables, driven by
  `lib/DB/System/Structure/*.json`. `createTable()` is idempotent; trans-engine
  `createIndex`, `addForeignKey`, `tableExists`, `columnExists`.
- `DB\Inspect`, `DB\Alter` — engine dispatch for schema introspection / changes
  on user tables. Never query `sqlite_master`, use `PRAGMA`, or write
  `INSERT OR IGNORE` outside an engine guard; use `$db->query($sql, $p, 'id')` for
  the last inserted id.

**Adding a system table:** add `lib/DB/System/Structure/{name}.json` (file name
without the `bdus_` prefix; `columns`, `indexes`, `relations` — `ref_table` is the
full table name, e.g. `bdus_files`), register `{name}` in
`Manage::$available_tables`, add a migration so existing apps call
`createTable()` once.

### Migrations (`DB\System\Migrate`)

Idempotent, tracked by name in `bdus_migrations`. To add one:

1. Create `lib/DB/System/Migrations/M0NN_Description.php` with
   `public const NAME` (never change after first deploy) and
   `public static function run(Manage $manage)`.
2. **Append** the class to `Migrate::ALL_MIGRATIONS` (never reorder).
3. If it touches files on disk, add its `NAME` to `FILE_AFFECTING_MIGRATIONS`.

Pending minor migrations are listed at login and applied by an admin via
`POST /api/upgrade/minor`; the v4 → v5 major upgrade has its own flow
(`Upgrade::runMajor`, with `Snapshot` and `MigrationVerifier`).

---

## Record layer (`lib/Record/`)

```
Record\Read    — loads the full model: core, plugins, links, backlinks, manual links,
                 files, geodata, rs, bibliography
Record\Edit    — stages changes on the model (_val / _delete markers)
Record\Persist — writes: persistCore / persistPlugins / persistManualLinks / persistRs
```

New records are built by `Bdus\Controllers\Record` and written with
`Persist::all()`. File attachments live in `bdus_file_links`
(`file_id`, `table_name`, `record_id`, `sort`); record-to-record manual links in
`bdus_userlinks` (bidirectional). Details: `bdus-docs/dev/record-lifecycle.md`.

Print templates (`bdus_cfg_templates`, JSON) are loaded/validated by
`Template\Loader` and edited through `Bdus\Controllers\Templates`.

---

## Frontend (`bdus-app/`)

**Stack:** Vue 3, Vite, Pinia, Vue Router, Ant Design Vue (+ a small token bridge
for dark mode), Cytoscape (+ dagre) for Harris matrix / graphs, MapLibre for maps,
Chart.js.

```
src/
  views/        route-level *View.vue
  components/   record/, config/, … feature components
  composables/  useTables, useRecordQuery, useRsRelations, useDarkMode, …
  stores/       Pinia: auth, config, listNavigation
  api/index.js  fetch client: JWT, proactive refresh, `/{app}/api/…` prefixing
  token.js      JWT in sessionStorage (per-tab isolation)
  locale/       en.json, it.json
tests/          node:test unit tests (`npm test`)
```

- API calls go through `api.get/post/put/delete('/api/…')`; the client adds the
  `/{app}` prefix and the Bearer token, and refreshes the token when < 30 min
  remain. On `401` it clears the token and redirects to `/login`.
- Harris matrix (`RsGraph.vue`): booleans in Cytoscape elements must be 0/1;
  passive stratigraphic relations are swapped (`SWAP_DIRECTION` in
  `useRsRelations.js`).

---

## Tests

```bash
docker compose exec app php vendor/bin/phpunit --testdox   # PHP unit + integration
./test.sh --setup --tests                                  # hurl E2E phases (tests/api)
cd bdus-app && npm test                                    # frontend unit tests
```

- `Tests\Support\BdusTestCase`: in-memory SQLite re-created per test class;
  fixtures in `tests/fixtures/`; helpers `makeController()`, `callController()`,
  `setPrivilege()`. The schema calls `Manage::createTable()` for every
  `available_tables` entry, so new system tables need no test changes.
- Fixture tables: `items`, `tags` (plugin of items), `reviews` (backlink),
  `categories` (lookup). Seed data inserted into `bdus_users` needs `password`.
- Hurl phases are numbered (`01_`…`36_`); add a phase file for new public routes.

---

## Releasing

Add notes under `## [Unreleased]` in the root `CHANGELOG.md`, then
`./bump-version.sh X.Y.Z` (commits, tags `vX.Y.Z`, pushes; the tag triggers the
image build). Default branch: `v5`.

---

## Architectural decisions (still relevant)

- **`bdus_file_links` for files, `bdus_userlinks` for record links:** a file
  belongs to a record (unidirectional); manual links are bidirectional.
- **No Doctrine DBAL:** the small `DB\DB` wrapper covers the three engines and
  index/FK management lives in `DB\System\Manage`.
- **Config order is preserved:** the config order is the UI order everywhere
  (dropdowns, columns, search forms, exports).
- **One JWT secret per application**, tokens in `sessionStorage` (several apps
  can be open in different tabs); the `Authorization` header is read via the
  `getallheaders()` fallback so it works on Apache, nginx and Caddy.
