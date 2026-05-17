# BraDypUS — Technical Reference for Developers & AI Agents

BraDypUS is a PHP + Vue 3 web application for managing relational databases in the
humanities/archaeology domain. It is currently undergoing a **v5 migration**: the
entire frontend is being rewritten from jQuery + Bootstrap 3 + server-side Twig to a
Vue 3 SPA, while the PHP backend is extended with new JSON endpoints.

> **If you are an AI agent starting a new session**: read this file first, then check
> `CHANGELOG.md` for the latest changes. The memory files in
> `~/.claude/projects/-Users-jbogdani-dev-BraDypUS/memory/` contain deeper historical
> context across sessions.

---

## Repository Layout

```
lib/               PHP framework layer (namespaced, no framework)
modules/           37 feature modules ({name}_ctrl extends Controller)
vue/               Vue 3 SPA (Vite + PrimeVue Aura)
tests/             PHPUnit integration + unit tests
projects/          Runtime: per-app data (NOT committed)
  {app}/cfg/       YAML config files
  {app}/files/     Uploaded files
  {app}/db/        SQLite DB files (if SQLite engine)
```

---

## PHP Architecture

### Routing

All requests hit `index.php`. The router reads `?obj=foo_ctrl&method=bar&param=…`
and instantiates `foo_ctrl` (class in `modules/foo/foo.php`), then calls `$ctrl->bar()`.

- Controllers **must** extend `Controller` (abstract base in `lib/Controller.inc`)
- The base class injects `$this->db`, `$this->cfg`, `$this->log`, `$this->prefix`,
  `$this->get`, `$this->post`, `$this->request`
- The router enforces authentication before dispatching — no module needs to re-check
  whether the user is logged in, only *what* the user can do

### Controller conventions

```php
class foo_ctrl extends Controller {
    // v5 endpoints: return JSON via $this->returnJson([...])
    public function getThings(): void {
        if (!\utils::canUser('read')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        // ...
        $this->returnJson(['status' => 'success', 'data' => $result]);
    }

    // Deprecated v4 methods are tagged @deprecated v5 but not deleted yet
}
```

**Privilege strings** (exact — typos silently fail to false):
`'enter'` `'read'` `'preview'` `'add_new'` `'edit'` `'multiple_edit'` `'admin'`
`'super_admin'`

### i18n convention (critical)

- **PHP endpoints return code strings, never translated text**:
  `['status' => 'error', 'code' => 'not_enough_privilege']`
- **Vue translates** with `t('not_enough_privilege')` from the locale JSON
- Locale files: `vue/src/locales/it.json` and `en.json`
- Adding a new message: add to both locale files, return the key from PHP

### Config access

```php
// Correct — dot notation on a specific key
$this->cfg->get('main.name');
$this->cfg->get('tables.mytb.label');
$this->cfg->get('tables.*.name');  // all table names (preserves config order!)

// Wrong — returns null
$this->cfg->get('tables');
```

**Config order is meaningful.** Tables and fields appear in the order defined in the
YAML config files. Never sort alphabetically; always iterate in config order.

---

## DB Layer (`lib/DB/`)

### `DB\DB`

PDO wrapper supporting SQLite, MySQL, PostgreSQL. Main query methods:

```php
$db->query(string $sql, array $values, string $return): mixed
// $return: 'read' (array of rows) | 'boolean' | 'id' | 'affected'

$db->execInTransaction(string $sql): bool
$db->exec(string $sql): bool
```

### `DB\System\Manage`

Orchestrates system table CRUD. All system tables are described in
`lib/DB/System/Structure/*.json` with the format:

```json
{
    "columns":   [ { "name": "…", "type": "…", "pk": bool, "notnull": bool } ],
    "indexes":   [ { "name": "…", "columns": ["…"], "unique": bool } ],
    "relations": [ { "name": "…", "column": "…", "ref_table": "…",
                     "ref_column": "id", "on_delete": "CASCADE" } ]
}
```

`ref_table` and index/constraint names are **without prefix** — Manage adds
`$this->prefix` at runtime.

**`createTable(name)`** is idempotent: `CREATE TABLE IF NOT EXISTS`, then applies
indexes on all engines; on SQLite, FK constraints are included inline in the DDL;
on MySQL/PG they are applied via `ALTER TABLE ADD CONSTRAINT`.

**Public trans-engine API:**

```php
$manage->createIndex(table, name, columns[], unique)  // idempotent
$manage->dropIndex(table, name)
$manage->addForeignKey(table, name, column, refTable, refColumn, onDelete)
// SQLite: throws BadMethodCallException — use structure JSON instead
$manage->dropForeignKey(table, name)
```

**Adding a new system table:**
1. Create `lib/DB/System/Structure/{name}.json` with columns, indexes, relations
2. Add `'{name}'` to `Manage::$available_tables`
3. Add a migration (see below) — existing apps need `createTable()` called once

### `DB\System\Migrate` — migration runner

Runs on every login, tracked in `{prefix}migrations`. Idempotent.

**Adding a migration:**
1. Create `lib/DB/System/Migrations/M00N_Description.php`:
   ```php
   class M00N_Description {
       public const NAME = 'M00N_description';  // never change after first deploy
       public static function run(Manage $manage): void { … }
   }
   ```
2. Add `M00N_Description::class` to `Migrate::ALL_MIGRATIONS` (append — never reorder)

### `DB\Inspect\*` and `DB\Alter\*`

Engine-specific schema introspection and column/table operations. Used by the `config`
module for user-facing schema management. Three implementations each:
`Sqlite`, `Mysql`, `Postgres`.

---

## Record Layer (`lib/Record/`)

```
Record\Read    — fetches full record data (core fields + plugins + files + links + geodata)
Record\Edit    — validates and prepares changes
Record\Persist — executes INSERT / UPDATE
```

`Record` (the public class, `lib/Record.inc`) is the façade: `new Record($tb, $id, $db, $cfg)`.

**File attachments** live in `{prefix}file_links` (unidirectional junction):
```
file_links.file_id  → files.id
file_links.table_name + record_id → the attached record
```
Record-to-record manual links stay in `{prefix}userlinks` (bidirectional).

---

## Vue SPA (`vue/`)

**Stack:** Vue 3, Vite, Pinia, PrimeVue Aura, Vue Router (hash mode), `vue-i18n`

### Directory layout

```
vue/src/
  views/           One *View.vue per major page (route-level component)
  components/
    record/        RecordView sub-components (FieldDisplay, FieldEditor,
                   FileGallery, PluginSection, RsSection, RsGraph, TemplateSection)
    config/        ConfigView panels
    users/         UsersView sub-components
  composables/     useRsRelations.js, useTables.js, useDarkMode.js
  locales/         it.json, en.json
  stores/          Pinia stores
```

### API calls

All PHP endpoints are called with:
```js
// GET
const res = await axios.get('index.php', { params: { obj: 'foo_ctrl', method: 'bar', … } })
// POST
const res = await axios.post('index.php?obj=foo_ctrl&method=bar', formData)
```

JWT token is injected automatically by an Axios request interceptor.

### Harris Matrix / RS relations

`RsGraph.vue` uses Cytoscape.js + cytoscape-dagre.

- Boolean data **must** be stored as integers in Cytoscape elements (0/1) — `[attr = false]`
  is not a valid Cytoscape selector
- `min-width: 'label'` not `width: 'label'` (deprecated since Cytoscape ≥3.23)
- Passive stratigraphic relations (1–4) have source/target swapped so arrows flow
  newer→older (top→bottom in dagre layout). See `SWAP_DIRECTION` in `useRsRelations.js`

---

## Test Infrastructure

**Run tests:**
```bash
docker compose exec app php vendor/bin/phpunit --testdox
```

**Base class:** `Tests\Support\BdusTestCase` (in `tests/Support/`)

- In-memory SQLite DB, re-created once per test *class* (fast; classes are isolated)
- Fixtures in `tests/fixtures/cfg/`
- `makeController(class, get, post)` — instantiates and injects dependencies
- `callController(ctrl, method)` — captures JSON output and decodes it
- `setPrivilege(int)` — temporarily changes the simulated user privilege

**System tables in test schema** (all created in `BdusTestCase::createSchema()`):
`test__items`, `test__tags`, `test__log`, `test__userlinks`, `test__rs`,
`test__geodata`, `test__files`, `test__file_links`

**When adding a new system table:** add it to `BdusTestCase::createSchema()` too,
or tests touching that table will fail with "no such table".

**Test locations:**
- `tests/Integration/` — controller-level tests (HTTP-style: call method, assert JSON)
- `tests/Unit/`        — unit tests for lib classes

---

## v5 Migration Status

### Migrated (Vue views + PHP JSON endpoints)

| Module | Vue view | Notes |
|---|---|---|
| login | LoginView | JWT auth, per-app secret |
| home | HomeView | Welcome MD/HTML editor for admins |
| record | RecordView + RecordEdit | All field types, plugins, files, links, geodata |
| search | DataView | fast/advanced/SQL search, URL-persisted state |
| rs | RsSection + RsGraph + MatrixView | Harris Matrix full-page view |
| config | ConfigView | 6 panels: app settings, validation, geoface, tables, fields |
| templates | TemplatesView | JSON layout editor |
| user | UsersView | Per-table privilege overrides |
| backup | BackupView | Native PDO SQLite dump |
| myExport | (DataView toolbar) | CSV, XLSX, JSON streaming |
| info | InfoView | Changelog |
| log | LogView | |
| vocabularies | VocabulariesView | |
| history | HistoryView | |
| frontpage_editor | HomeView (inline) | |
| search_replace | SearchReplaceView | Cascading dropdowns, config order |
| confirm_super_adm_pwd | (used in ConfigView password gate) | |

### Pending migration

| Module | Priority | Notes |
|---|---|---|
| file | medium | ✅ sort drag-and-drop completo (SortableJS); upload/delete già presenti |
| geoface | medium | Leaflet map; full geodata CRUD |
| import_geodata | low | File upload + geodata parse |
| chart | low | jqPlot charts; likely replaced |
| matrix (v4) | — | Superseded by RsGraph/MatrixView |
| userlinks | low | Manual link management UI |
| new_app | low | App creation wizard |
| preview_flds | low | Field preview helper |

### Deprecated (no Vue equivalent needed)

- `empty_cache` — no Twig cache in v5
- `menuValues` — no longer needed
- `free_sql` — replaced by SQL expert search in DataView
- `myTmpl` — template preview superseded by TemplatesView

---

## Architectural Decisions

### Why `{prefix}file_links` instead of `{prefix}userlinks` for files

`userlinks` is a bidirectional junction (`tb_one/id_one` ↔ `tb_two/id_two`) designed
for record-to-record manual links. File attachments need a unidirectional model:
a file belongs to a record, not the other way around. The new `file_links` table
(`file_id`, `table_name`, `record_id`, `sort`) makes queries simpler, supports
`ON DELETE CASCADE`, and aligns with the Directus pattern. Existing data is migrated
automatically by `M002_CreateFileLinks` on first login.

### Why no Doctrine DBAL

Doctrine DBAL would be a large dependency (~3 MB, 200+ files) and would create a
parallel query interface alongside the existing `DB\DB` wrapper. The custom DB layer
is small, well-tested, and covers all three engines. Index/FK management (the main
gap) has been added natively to `DB\System\Manage` with a consistent trans-engine API.

### Why config order is preserved (never sort tables/fields)

The YAML config files are the source of truth for the logical order of tables and
fields in every UI: dropdowns, column lists, search forms, export headers. Alphabetical
sorting would break this contract. Any code that enumerates tables or fields must
iterate in config order (i.e., preserve the order from `cfg->get('tables.*.name')`).

### JWT auth design

One JWT secret per application (not per user), stored at
`projects/{app}/cfg/.jwt_secret` (chmod 0600, htaccess-protected). Tokens live in
`sessionStorage` (per-tab isolation: multiple apps can be open simultaneously).
Silent proactive refresh when < 30 min remain. The `Authorization: Bearer` header
is read via `getallheaders()` fallback, making it server-agnostic (Apache, nginx,
Caddy, CLI).
