# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.0.0] - unreleased

### Added
- **Graph visualisation of manual links** (`ManualLinksGraph.vue`): a toggle button
  in the Linked records section header switches between list and an interactive
  Cytoscape.js force-directed graph. Nodes represent records (current record
  highlighted in orange); edges carry the relation label when present. Clicking any
  non-self node navigates to that record. The graph is read-only; editing links
  remains in list mode. Uses the built-in `cose` layout — no extra plugin required.
  i18n keys `show_graph` / `hide_graph` added (it/en).
- **Typed manual links** — `bdus_userlinks` now has an optional `label TEXT`
  column (migration M029). Users can assign a free-text relation type (e.g.
  `"cites"`, `"is part of"`) when adding a manual link between two records.
  The label is shown as a chip in the Linked records section of RecordView.
  Backend: `addManualLink` accepts `label` in the POST body; `getRecord` returns
  `label` in each `manualLinks` entry via `Record\Read::getManualLinks()`.
  Frontend: optional `InputText` shown after a record is selected in the add-link
  form; label chip displayed next to each link in read mode.
  Tests: 2 new assertions in `RecordCtrlManualLinksTest`; hurl phase 04e updated
  to verify `link.label == "cites"`. i18n key `link_label_placeholder` added (it/en).
  Docs: `guide/usage/crud.md` updated with new **Manual links** and **Duplicate**
  sections. Foundation for C2 graph visualisation.
- **Duplicate record** (`POST /api/record/{tb}/{id}/duplicate`): one-click record copy.
  Copies all core fields from the source record (excluding `id`); `creator` is set to the
  current authenticated user. Returns `{ status, code: "success_duplicated", id: newId }`.
  Requires `add_new` privilege. Frontend: "Duplica" button in `RecordView` header (visible
  in read mode for existing records when `can_add` is true). `getRecord` now also exposes
  `metadata.can_add` so the button can be shown/hidden without an extra request.
  Tests: 5 new PHPUnit tests (`RecordCtrlDuplicateTest`); hurl phase 32 (6 requests).
  Total test count: 856 → 861 (+5 tests); hurl phases: 31 → 32.
- **FK constraints and user-defined indexes on user tables** (M026, M027):
  BraDypUS now enforces at database level the relationships defined in the application
  configuration.

  **Schema change (M026)** — `bdus_cfg_relations` replaces the old `(from_tb, to_tb,
  fld JSON, sort)` layout with one row per FK column pair:
  `(from_tb, from_col, to_tb, to_col, on_delete, on_update)`.
  Direction is semantic (`from_tb` always holds the FK column); UNIQUE is now on
  `(from_tb, from_col)` instead of `(from_tb, to_tb)`, allowing multiple FK columns
  between the same table pair.

  **New system table (M027)** — `bdus_cfg_indexes` tracks both auto-generated FK
  indexes and user-defined indexes.

  **`DB\Alter` — new cross-engine operations**:
  - `addForeignKey` / `dropForeignKey` / `hasForeignKey` — SQLite via table-recreation,
    MySQL/PG via `ALTER TABLE ADD/DROP CONSTRAINT`.
  - `checkOrphans` — counts FK violations before applying a constraint; if > 0 the
    config row is saved but the DB constraint is not applied (returns a `warning`).
  - `createIndex` / `dropIndex` — B-tree, unique, and composite indexes on all engines.
  - `createMinimalTable` extended with `pluginOf` parameter: plugin tables get
    `FOREIGN KEY (id_link) REFERENCES parent(id) ON DELETE RESTRICT` inline in the DDL.

  **New/updated config endpoints** (all super-admin):
  - `POST /api/config/relations` — create FK relation; applies constraint + auto-index
    to DB immediately; warns if orphans found.
  - `PUT /api/config/relations/{id}` — update `to_tb`, `to_col`, `on_delete`,
    `on_update`; `from_tb` and `from_col` are immutable.
  - `DELETE /api/config/relations/{id}` — drops FK and auto-index from DB.
  - `POST /api/config/apply-constraints` — bulk-applies all pending FK constraints
    and explicit indexes; useful after v4 migration or orphan cleanup.
  - `GET/POST /api/config/table/{tb}/indexes` — list and create user-defined indexes.
  - `DELETE /api/config/table/{tb}/indexes/{id}` — drop a user-defined index.

  **New record endpoint**: `GET /api/record/{tb}/check-plugins-before-delete?id=N` —
  returns a list of plugin tables with row counts referencing the given record, so the
  frontend can show a confirmation dialog before deletion (instead of relying on
  silent CASCADE).

  **Self-referential FKs** are allowed; `on_delete` is forced to `RESTRICT` and
  `on_update` to `CASCADE`.

  Tests: 64 new PHPUnit tests (`M026MigrationTest`, `AlterFkIndexTest`,
  `ConfigRelationsCtrlTest`, `ConfigIndexesCtrlTest`, `RecordCtrlDeletePluginTest`);
  2 new hurl phases (14 rewritten for new schema, 31 new for index management).

### Fixed
- **`POST /api/api-key/{id}/revoke` and `DELETE /api/api-key/{id}`** (`Api.php`): controllers
  now read the path-variable `id` via `$this->request['id']` (merges URL path vars and POST body)
  instead of `$this->post['id']`, fixing a bug where both endpoints always returned
  `parameter_missing` when called via the REST API.
- **`POST /api/saved-query/{id}/share|unshare` and `DELETE /api/saved-query/{id}`**
  (`SavedQueries.php`): same fix — path-variable `id` now read via `$this->request['id']`.
- **`POST /api/template/{tb}/{name}/rename`** (`Templates.php`): controller now reads
  `old_name` from the URL path variable (`$this->get['name']`) and `new_name` from the
  JSON body (`$this->post['new_name']`), aligning with the REST route.
- **OpenAPI `POST/DELETE /api/config/geoface`**: these two operations were documented under
  the wrong path. Corrected to `POST/DELETE /api/config/geofile` (the real PHP routes).
- **OpenAPI geoface feature `PUT` and `DELETE`**: updated request-body schemas to match
  the actual controller signatures — `PUT` expects `{geodata:[{id,geometry}]}` (batch array),
  `DELETE` expects `{ids:[…]}`.
- **`PATCH /api/config/table/{tb}` and `PATCH /api/config/table/{tb}/field/{fld}` (rename endpoints)**:
  `rename_tb` and `rename_column` now read the old name from the URL path parameter
  (`$this->get['tb']` / `$this->get['fld']`) and `new_name` from the JSON request body
  (`$this->post['new_name']`), aligning with the OpenAPI spec and standard REST design.
  Previously both parameters were incorrectly read from `$this->get` (query string),
  making the documented body-based API non-functional.
- **Test schema fragility** (`tests/Support/BdusTestCase.php`): `createSchema()` now
  calls `Manage::createTable()` in a loop over `Manage::$available_tables` instead of
  hardcoding `CREATE TABLE` DDL for every system table. Adding a column to any system
  table no longer requires manual updates to test files. Six Integration test classes
  (`LoginCtrlTest`, `UserCtrlTest`, `ConfirmAdminPwdCtrlTest`, `FreeSqlCtrlTest`,
  `SavedQueriesCtrlTest`, `ChartCtrlTest`) had their redundant `createSchema()` overrides
  removed; seed inserts into `bdus_users` were updated to include the required `password`
  column. `ConfigCtrlTest` gains a `createSchema()` override that plants a rogue column
  in `bdus_log`, ensuring `testGetValidationReportFixItemsHaveCorrectShape` always
  exercises its assertion loop.

### Added
- **Demo app schema specification** (`tests/api/demo-schema.dbml`): DBML file documenting
  the full structure of the `bdus_demo` test/demo application with `// bdus:` annotations
  for all BraDypUS-specific features (field types, population policies, validation checks,
  table-level features). Serves as the canonical source of truth for the demo schema.
- **Hurl CRUD phases migrated to dedicated `crud_test` table**: all generic CRUD
  tests (phases 04–28) now run against a temporary `crud_test` table created in
  phase 03 and dropped in phase 10. The archaeological tables (`siti`, `us`, `saggi`,
  `reperti`, `complessi`) remain empty after the correctness suite, making demo-seed
  ID predictions reliable and eliminating all table-pollution bugs.
- **Demo seed on same app**: `--seed-demo` / `--seed-more` now populate the SAME
  `bdus_test_suite` app instead of a separate `bdus_demo` instance. Phase 10 drops
  `crud_test` and the real tables are clean for phase 19.
- **Phase 19 matrix fix**: added missing `?tb=us` parameter to the RS matrix query.
- **Phase 19 `capture_phase` → `run_phase`**: prevents the double-execution bug where
  `hurl --json` populated the DB but exited non-zero, causing `hurl --test` to re-run
  and fail on duplicate records.
- **`test.sh --all-engines`**: new flag that runs the full suite (PHPUnit + Hurl) three
  times in sequence — sqlite → pgsql → mysql — and reports a consolidated pass/fail per
  engine. Default (`./test.sh`) still uses sqlite only for fast iteration.
  All three engines pass the full suite at this commit.
- **PHPUnit test coverage for 6 previously untested controllers**:
  `HistoryCtrlTest` (8 tests — pagination, filters, privilege),
  `NewAppCtrlTest` (3 tests — shape, not-permitted guard, missing params),
  `FileCtrlTest` (5 tests — sortFiles success/edge/privilege),
  `UserCtrlTest` (18 tests — full CRUD: showList, showUserForm, saveUserData, deleteOne,
  getTablePrivileges, saveTablePrivilege, deleteTablePrivilege),
  `LoginCtrlTest` (8 tests — auth happy/error paths, refresh, out, listApps),
  `ConfirmAdminPwdCtrlTest` (4 tests — correct/wrong/empty pwd, non-super-admin).
  Total test count: 749 → 800 (+51 tests, +141 assertions).
- **Hurl E2E phases 29–30**: history+confirm-admin-password (`GET /api/history`,
  `POST /api/admin/check-password`); file sort (`POST /api/files/sort`).
  Total hurl phases: 28 → 30.
- **OpenAPI spec fully aligned with `Router.php`**: added documentation for all previously
  undocumented routes — migrations (`GET /api/migrations`), config relations
  (`GET/POST /api/config/relations`, `PUT/DELETE /api/config/relations/{id}`), all 10 Zotero
  routes (`/api/zotero/*`), and widget routes (`GET /api/widgets`, `GET /api/widget/{name}`).
  Added 4 new tags and 4 new component schemas (`Relation`, `RelationInput`, `ZoteroLib`,
  `ZoteroLink`). OpenAPI now covers all 107 URL paths exposed by the router.
- **Hurl E2E test suite expanded** — 6 new test phases (23–28) covering widgets, logs, API
  keys, saved queries, templates + field-structure, and geoface feature CRUD. Existing phases
  07 (charts) and 17 (zotero) extended with share/unshare and sync-all steps. Photo import
  validation tests added to phase 12. All 28 phases pass on SQLite.
- **Phase 22 — Schema structural changes** (`tests/api/22_schema_changes.hurl`): new E2E
  test phase covering the full lifecycle of a temporary table: create table, add column,
  change column type, add second column, add data, rename column, verify data survives,
  delete records, delete column, rename table, verify under new name, delete table, verify
  no config residues. Exercises every schema-modification endpoint.
- **Rewritten phase 03** (`tests/api/03_config_tables.hurl`): demo app schema now covers
  all field types (`text`, `textarea`, `long_text`, `boolean`, `date`, `select`,
  `combo_select`, `multi_select`, `slider`), all population policies (`vocabulary_set`,
  `dic`, `id_from_tb`, `get_values_from_tb`) and all validation checks (`not_empty`, `int`,
  `email`, `no_dupl`, `range`, `regex`). Tables: `siti`, `complessi`, `saggi`, `us`,
  `reperti`, `misure`, `m_reperti_in_us`, `m_reperti_in_complessi`.
- **Rewritten phase 19** (`tests/api/19_seed_demo.hurl`): realistic archaeological seed
  data — 11 vocabulary sets (71 entries), 3 siti, 8 complessi, 12 saggi, 30 US with 25 RS
  relations (Harris Matrix), 20 reperti, plugin data, geodata (8 geometries), userlinks,
  and 2 charts.



Complete rewrite of the frontend: from jQuery + Bootstrap 3 + Twig server-side
rendering to a Vue 3 SPA (Vite, Pinia, PrimeVue / Aura theme).
The PHP backend is preserved and extended with JSON endpoints consumed by the new frontend.

### Added
- **`Auth\Authorization` class** (`lib/Auth/Authorization.php`): extracted from the
  legacy `utils` class. Provides `can(string $privilege, ?int $creator): bool` (replaces
  `utils::canUser`) and `privilege(mixed $input): mixed` (replaces `utils::privilege`).
  Now properly namespaced under `Auth\`; all 20+ modules updated.
- **`Auth\Password` class** (`lib/Auth/Password.php`): extracted from `utils`. Provides
  `hash(string $password): string` and `verify(string $plain, string $stored): bool`
  with transparent backward-compatible SHA-1 → bcrypt verification.
- **`JsonFilter` cross-table (plugin) queries**: a second level of nesting in the
  Directus-style filter identifies a plugin table instead of a field:
  ```
  ?filter[photos][description][_icontains]=amphora
  ```
  Generates a safe `id IN (SELECT id_link FROM photos WHERE table_link=? AND
  description LIKE ?)` subquery with PDO bound parameters. Plugin membership and
  field names are validated against the table config. Available for `getRecords`,
  `exportRecords`, `getRsMatrix`, `getGeoJson`, and chart `getData`.
- **`_empty` / `_nempty` filter operators**: convenience aliases for the common
  "is blank" test — `_empty` → `(col IS NULL OR col = '')`,
  `_nempty` → `(col IS NOT NULL AND col != '')`.  These replace the legacy
  `is_empty` / `is_not_empty` pseudo-operators from the old advanced search.
- **`openapi.yaml` updated**: removed `shortSql` / `where` params; documented all
  active search modes (`fast`, `sqlExpert`, `filter`); added `FilterObject` schema
  with all 17 operators, cross-table example and security notes; export endpoint
  corrected to use `qt`/`q` params as actually implemented.


- **OAuth2 / SSO authentication** (Google + ORCID): users can log in with an
  external identity without a local password. Provider credentials are stored
  in `projects/{app}/config.json`; providers not configured are silently hidden
  in the login UI. State tokens are HMAC-SHA256 signed with the per-app JWT
  secret and carry a 10-minute TTL. Google accounts are auto-linked by email on
  first use; ORCID requires an admin to pre-set the `oauth_sub` field (ORCID's
  public API does not expose email). New migration M022 adds nullable
  `oauth_provider` / `oauth_sub` columns and a partial unique index to
  `bdus_users`. See `docs/oauth.md` for full setup instructions.
  (`league/oauth2-google` dependency added.)
- `GET /api/auth/oauth/{provider}/redirect` — returns provider authorization URL
- `GET /api/auth/oauth/{provider}/callback` — exchanges code, issues JWT, redirects
- `listApps` response now includes an `oauth` array per app listing enabled providers
- **Stateless JWT authentication**: PHP sessions removed entirely; each
  request carries a signed `Authorization: Bearer` token stored in
  `sessionStorage` (per-tab — multiple apps open simultaneously in one
  browser). Per-app secrets stored at `projects/{app}/cfg/.jwt_secret`
  (auto-generated, chmod 0600). Silent proactive token refresh when < 30
  min remain. `firebase/php-jwt ^7.0` dependency added.
- **Per-table privilege overrides**: admins can assign read/write/admin
  overrides per table per user, with an optional raw SQL WHERE subset for
  row-level filtering. Managed via an expandable row in the Users view.
  New `user_table_privs` system table; applied automatically via the new
  DB migration runner (`DB\System\Migrate`) on every login.
- **Harris Matrix / stratigraphic relations**: tables with `rs_field`
  configured expose full relation management inline in RecordView
  (`RsSection` + `RsGraph` powered by Cytoscape.js / cytoscape-dagre) and
  a standalone full-page Harris Matrix view at `/matrix/:tb/:id`.
  `DataView` toolbar shows a "Harris Matrix" button for eligible tables.
- **cfg/ directory protection**: `projects/{app}/cfg/.htaccess` denying all
  web access is written on app creation and self-healed by the Filesystem
  validation check if missing.
- `Auth\CurrentUser` static class: centralised per-request user state
  replacing all direct `$_SESSION['user']` access across the codebase.
- `DB\Validate\Filesystem`: new validation check verifying cfg/ is
  web-inaccessible; runs first in the validation report.
- Vue 3 SPA with Vite dev server and hash-based routing
- PrimeVue Aura theme; dark-mode ready via CSS custom properties
- Responsive layout: collapsible sidebar (desktop) + slide-in drawer (mobile)
- Locale switcher (🇮🇹 / 🇬🇧) with reactive i18n throughout the UI
- **DataView**: paginated record table, column toggler with persistent column order,
  sortable columns, fast / advanced / SQL expert search, active filter persisted
  in the URL so Back navigation restores the exact search state
- **RecordView / RecordEdit**: full record display and edit — all field types (text,
  textarea, date, select, multi_select, boolean, combo_select, slider, link_to,
  link_out), plugin tables (add/edit/delete rows), file upload and deletion,
  unsaved-changes guard on navigation, client-side validation with force-validate on
  save, linked records panel, geodata count, template selector
- Streaming export from any active search: CSV, XLSX (zero-dependency PHP OOXML
  writer), JSON — served as download attachment, no temp file on disk
- Add-record button (toolbar + FAB) gated by `can_add` privilege
- **ConfigView**: full application configuration UI — password-gated, split
  sidebar/panel shell with 6 panels:
  - **App settings**: 15-field form for name, label, language, status, engine, etc.
  - **Validation**: schema validation report with one-click fixes; dark-mode status rows
  - **Geoface**: WMS/WFS/local layer editor + GeoJSON/KML file upload
  - **Table settings**: name, label, layout fields, preview fields, plugins, backlinks,
    cross-table links; rename and delete table; add new table
  - **Field list**: split panel with field list and inline field editor
  - **Field form**: auto-generated from `fld_structure.json`; supports input, select,
    multi_select meta-types
- **App-scoped URL routing**: every authenticated route now lives under
  `/:app/` (e.g. `#/myapp/data`, `#/myapp/record/sites/42`). Public routes
  (`/login`, `/oauth-callback`, `/new-app`) remain at the root. Deep links
  are fully self-contained — the app name in the URL is the source of truth
  for bookmarks and shared links. The navigation guard silently corrects a
  stale or mistyped app name so the JWT always wins. After login the browser
  navigates to `/${app}/`; the `401` handler still redirects to `/login`.
- **Runtime primary-colour customisation** (M025): administrators can choose the
  application's primary colour palette from 8 presets (Indigo, Blue, Violet, Emerald,
  Teal, Amber, Rose, Slate) in **Config → App settings → Appearance**.
  The selection is persisted in `bdus_cfg_app.color` (migration M025) and applied
  to every user at login via `GET /api/info/app`; the colour change takes effect
  immediately in the config panel (live preview) using PrimeVue's
  `updatePrimaryPalette` API. Works correctly in both light and dark mode.
- **Design Templates** (`/templates`): visual editor for JSON record-view layout
  templates — create, edit, rename, delete per-table templates; section cards with
  label, plugin selector, collapsible flag, content rows (field + width)
- **BackupView**: list backups, create, download, delete (admin), restore (super_admin);
  native PHP/PDO SQLite dumper replaces Spatie's CLI-dependent one — works in Docker
- **InfoView**: version badge + full changelog
- **LogView**, **UsersView**, **VocabulariesView**, **HomeView**, **LoginView** migrated
- 167 PHPUnit integration + unit tests covering all migrated controllers
- Module readmes: `vue/docs/{data,log,search,backup,info,config,templates}.md`
- **REST router** (`nikic/fast-route ^1.3`): clean URL routing for the public
  REST API (`/api/…`) without query-string dispatch. All legacy `?obj=…&method=…`
  URLs remain intact for the Vue frontend.
- **CORS support**: `Access-Control-Allow-Origin` / `Access-Control-Allow-Headers`
  headers emitted for REST API responses so third-party frontends can consume
  public endpoints without a proxy.
- **`welcome.md` scaffold**: a starter welcome-page file is generated inside
  `projects/{app}/cfg/` when a new application is created, giving users an
  editable landing page out of the box.
- **Image resize on upload** (`Image\Resizer::maybeResize()`): when
  `maxImageSize` is set in the app config, raster images (JPEG, PNG, GIF,
  WebP, BMP, TIFF) are automatically downscaled to fit within the configured
  pixel bound on upload. Vector formats (SVG) and non-image files are skipped.
  Uses `intervention/image` 3.x `scaleDown()`.
- **Migration M014 — GeoFace config to DB**: geoface layer definitions
  previously stored in `cfg/geoface.json` are migrated to a new
  `bdus_cfg_geoface` system table (single row: `id=1`, `layers TEXT`).
  New `Config\GeofaceConfig` static helper provides `isAvailable()`,
  `getLayers()`, and `saveLayers()` with a DB-first / file fallback pattern
  for zero-downtime migration.
- **Migration M015 — delete superseded cfg JSON files**: removes all
  `cfg/*.json` files that are now served from the database
  (`cfg/tables.json`, per-table field files, etc.). `cfg/config.json`
  (main app config) is always preserved.
- **Migration M016 — rename app_data.json → config.json**: renames
  `cfg/app_data.json` to `cfg/config.json` for naming consistency and
  strips the five obsolete fields (`gmapskey`, `googleanaytics`,
  `virtual_keyboard`, `api_login_as_user`, `auth_login_as_user`) from the
  stored JSON in the same pass.
- **Migration M017 — cleanup stray cfg JSON files**: removes any
  `cfg/*.json` files (excluding `config.json`) that survived on
  installations where M015 ran before its glob logic was corrected
  (system tables such as `files` were previously missed).
- **Migration M018 — move config to project root**: moves `cfg/config.json`
  and `cfg/.jwt_secret` from the `cfg/` subdirectory to the project root,
  removes the now-empty `cfg/` directory, and replaces the blanket
  deny-all `.htaccess` with a `<Files>`-based equivalent at the project
  root that blocks only `config.json` and `.jwt_secret` — leaving
  `projects/{app}/files/*` directly servable by the web server.
- **Migrations admin endpoint** `GET /api/migrations`: returns the list of
  all registered migrations with their applied status; accessible to
  `admin` users only.

### Removed
- **`class version` / `lib/version.php`**: single-method class deleted; version
  reading is now inlined in `info_ctrl::getInfo()` (`composer.json` read directly).
- **`lib/SQL/SafeQuery.php`**: was only used by the now-deleted `obj_encoded` and
  `getWhereAndValues` paths in `QueryFromRequest`.
- **`lib/Template/Exceptions/TemplateException.php`**: never thrown or caught
  anywhere outside its own definition file.
- **Dead `QueryFromRequest` types**: `id_array`, `encoded` (v4 base64 ShortSQL),
  `obj_encoded` (v4 SafeQuery) — zero callers in production code.
- **Dead `QueryFromRequest` methods**: `setSubQuery()`, `setGroup()`, `getGroup()`,
  `getWhere()`, `getWhereAndValues()` — zero callers; `$group` property removed.
- **`QueryFromRequest::advSearch()`** (157 lines) and `case 'advanced'` in
  `setWhere()`: the entire legacy advanced search implementation is removed.
  All endpoints that previously accepted `search_type=advanced` / `adv` now only
  accept the Directus-style `filter` format.
- **`utils::emptyDir()`** and **`utils::debug()`**: no callers.
- **`utils::recursiveFilter()`**: moved inline as `config_ctrl::filterPost()` (its
  only caller); removed from `utils`.
- **`utils::multiArray2GeoJSON()`**: moved inline as `geoface_ctrl::toGeoJSON()` (its
  only caller); removed from `utils`.
- **`utils::canUser()`, `utils::privilege()`, `utils::encodePwd()`, `utils::verifyPassword()`**:
  moved to `Auth\Authorization` / `Auth\Password`; removed from `utils`.
- **`.inc` autoloader fallback**: `lib/autoLoader.php` no longer looks for
  `$className.inc` files — the three remaining `.inc` files have been renamed
  to `.php` (`utils`, `QueryFromRequest`, `bigRestore`). The `interfaces/` `.inc`
  fallback is also removed.
- **ShortSQL DSL** (`lib/SQL/ShortSQL/` and related SQL classes): legacy v4
  filter format removed; `shortSql` type and `where` param removed from all
  endpoint documentation and backend handling.
- **Base64 filter encoding**: the `qt=filter&q=BASE64_JSON` URL persistence scheme
  is replaced by a plain `?filter=JSON_STRING` parameter. PHP backends no longer
  attempt `base64_decode` as a fallback when parsing the `filter` param.
- PHP session-based authentication (`session_start()`, `$_SESSION['user']`)
- `cookieAuth.inc` (dead code)
- `autolog` feature (anonymous login via configured user_id — incompatible
  with per-tab JWT isolation)
- `pref.inc` session-based persistence replaced by a stateless per-request
  static store (user preferences will migrate to `users.settings` or
  Vue localStorage in a future release)
- Gulp / LESS build pipeline (`gulpfile.js` removed); v5 frontend is
  built entirely by Vite
- `michelf/php-markdown` PHP dependency removed; Markdown → HTML conversion
  for the changelog and welcome page is now performed client-side (`marked.js`)
- `Monolog\Handler\FirePHPHandler` removed (FirePHP browser extension is
  abandoned); the debug handler stack now uses `StreamHandler` only
- `langs` key removed from `getAppProperties` response; the list of available
  UI locales is owned by the Vue frontend, not the backend
- **Obsolete config fields removed**: `gmapskey` (Google Maps API key),
  `googleanaytics` (GA tracking ID), `virtual_keyboard`, `api_login_as_user`,
  and `auth_login_as_user` are no longer read, stored, or displayed. Stripped
  from existing configs by M016.
- `cfg/` subdirectory removed from new app layout (post-M018); `config.json`
  and `.jwt_secret` now live at the project root.
- **Config form sections removed**: "External services" (Google Maps / Analytics)
  and "Login options" (virtual keyboard, login-as-user) sections removed from
  the ConfigAppForm Vue component.

### Fixed
- **Login `TypeError` when app is not found**: if the JSON body did not
  include an `app` field that matched an existing project directory, `APP`
  was left undefined, `$this->db` was null, and `DB\System\Manage::__construct()`
  threw a bare `TypeError` logged as an unhandled error. `Login::authenticate()`
  now guards against a null DB and throws `\Exception('app_not_found')`, which
  the `catch (\Exception)` block returns as `{"status":"error","code":"app_not_found"}`.
- **Stale Bearer token prevents login**: when a Bearer token was present in the
  request but its `app` claim pointed to a project directory that no longer
  existed (e.g. a deleted test application), the bootstrap `else` branch that
  reads `app` from the request body was never reached, leaving `APP` undefined.
  The `if/else` structure in `bootstrap.php` is replaced by `if` + `if (!defined('APP'))`,
  so the body fallback always runs when `APP` is still undefined after the token check.
- **JWT response nested under index `0`**: `Login::auth()` and `Login::refresh()`
  emitted `{"status":"success","0":{"token":"…"}}` instead of `{"status":"success","token":"…"}`
  because an anonymous array `['token' => $token]` was appended as an array element
  rather than merged. Fixed to `'token' => $token` (named key).
- **`User::saveUserData()` response missing `id`**: same anonymous-array bug caused
  the newly created user's `id` to appear at `{"0":{"id":N}}`. Fixed with the spread
  operator (`...$extra`).
- **Dead `search → advanced` conversion in `Record::getRecords()`**: the block
  that converted a legacy `{ "search": [{field, operator, value}] }` POST body to
  the `advanced` type was never reachable (the `advanced` case was removed from
  `QueryFromRequest::setWhere()`) and threw an unhandled exception. The dead block
  is removed; callers must use the Directus-style `filter` format instead.

### Security
- `Authorization: Bearer` header is now read via `getallheaders()` as
  fallback, making JWT auth fully server-agnostic (Apache mod_php, nginx-FPM,
  Caddy, CLI) — no longer requires the Apache-specific `RewriteRule`
  workaround in `.htaccess`
- JWT secrets are auto-generated per application, stored with chmod 0600,
  and the containing directory is .htaccess-protected against web access
- **Project root `<Files>` htaccess** (M018): replaces the blanket
  `Deny from all` in `cfg/` with a targeted `<Files>` block at the project
  root that protects only `config.json` and `.jwt_secret`, so
  `projects/{app}/files/*` remains directly web-accessible.
- **`restoreVersion` requires admin privilege**: restoring a record to a
  previous version is now gated at `admin` level (was `edit`), preventing
  regular editors from silently overwriting history.

### Changed
- **`search_type=advanced` retired** across the entire API: `getRecords`,
  `exportRecords`, `getRsMatrix`, `getGeoJson`, and chart `getData` no longer
  accept `search_type=advanced` or the `adv` array parameter. All structured
  search is now handled by the Directus-style `filter` format.  
  **Breaking change**: saved queries that contain `search_type: "advanced"` will
  fail to load and must be recreated using the search form.
- **`GET /api/search/{tb}/config` operator values** changed from legacy SQL strings
  (`LIKE`, `=`, `NOT LIKE`, …) to filter operator keys (`_icontains`, `_eq`,
  `_ncontains`, …). Frontend i18n keys (`contains`, `is_exactly`, …) unchanged.
- **`filter` param accepts plain JSON string** in GET requests (e.g.
  `?filter={"status":{"_eq":"active"}}`), in addition to the bracket notation
  (`?filter[status][_eq]=active`) already supported. PHP backends `json_decode`
  the string; no base64 step.
- **URL persistence format**: the search form in DataView now persists an active
  filter as `?filter=JSON_STRING` instead of the previous `?qt=filter&q=BASE64`.
  MatrixView and GeofaceView pass filter objects to the API as bracket notation
  (serialised automatically by `api.get()` via `appendQuery`).
- **`lib/` structure**: `lib/utils.inc`, `lib/QueryFromRequest.inc`, and
  `lib/bigRestore.inc` renamed to `.php`. `lib/autoLoader.php` updated to remove
  the `.inc` lookup branch.
- **`version::current()` inlined**: the one-line `json_decode(composer.json)` read
  is now directly in `info_ctrl::getInfo()`; `lib/version.php` deleted.
- All module endpoints now return JSON (`returnJson()`) instead of rendering Twig
- `backup_ctrl::buildFileName()` uses `PROJ_DIR` (was a relative path)
- `DB\Export\Export` refactored: `fromData()` factory + `streamToResponse()`;
  HTML / XML / SQL / XLS exporters deprecated
- Password hashing upgraded from SHA1 to bcrypt (transparent migration on login)
- Sidebar reorganised: Import geodata and Backup moved under Data; Empty cache removed;
  Design Templates added under Admin
- CSS design tokens normalised (`--p-surface-*` → `--bdus-*` / `--p-content-*`) across
  all components and views for correct dark-mode rendering
- File gallery panel moved above core fields in RecordView for immediate visibility
- ConfigTableForm: Twig template selector (`tmpl_edit` / `tmpl_read`) removed — setting
  is not used in v5; YAML keys preserved on disk but ignored on save
- All v4 PHP methods superseded by Vue equivalents tagged `@deprecated v5`
- Backup validation no longer requires the `sqlite3` CLI binary; `DumpExists::Check()`
  returns success immediately for SQLite (native PHP/PDO dump needs no external tool)
- Application version is now read from `composer.json` at runtime instead of
  being hardcoded; `info_ctrl::getInfo()` returns the value from the `version` key
- **Three-step config fallback chain**: `Config\Load`, `DB\DB`, `modules/login`,
  and `JWT\JwtManager` all resolve config paths via the ordered chain
  `{root}/config.json` → `{root}/cfg/config.json` → `{root}/cfg/app_data.json`,
  allowing the system to handle any migration state transparently without
  requiring a specific migration to have already run.
- **New-app creation writes to project root** (post-M018): `CreateApp` no longer
  creates the `cfg/` subdirectory; `config.json`, `.jwt_secret`, and the
  `<Files>` htaccess are written directly to `projects/{app}/`.
- Composer dependencies updated: `adbario/php-dot-notation` 3.5,
  `monolog/monolog` 3.10, `intervention/image` 3.11, `spatie/db-dumper` 3.8;
  `intervention/image` v3 API migration applied (`ImageManager` + `Driver` class,
  `read()` replaces `make()`, explicit path required for `save()`)

### Fixed
- **Harris Matrix — search filter not applied**: `getRsMatrix` received
  the advanced search payload as a base64-encoded GET param (`adv=BASE64`)
  but called `json_decode()` directly, getting null and falling back to
  "all records". Now tries plain `json_decode` first (POST path), then
  `base64_decode` + `json_decode` (GET/matrix path).
- **Harris Matrix — edge direction**: passive relations (1 "is covered by",
  2 "is cut by", 3 "carries", 4 "is filled by") had source/target in the
  wrong order. Added `SWAP_DIRECTION` set; `buildElements()` now swaps
  `first`↔`second` for these relations so arrows always flow newer→older
  (top→bottom in the dagre layout).
- **Harris Matrix — cyclic edges highlighted**: after layout, directed edges
  whose source `y` > target `y` (counter-flow = stratigraphic cycle) are
  marked with class `cyclic` and rendered in red.
- **Harris Matrix — Cytoscape rendering**: boolean data stored as JS
  `true`/`false` produced invalid selectors (`[attr = false]` not supported).
  Changed to integers (0/1); updated all selectors accordingly. Replaced
  deprecated `width: 'label'` with `min-width: 'label'`. Added `fit: true`
  to dagre layout and a `layoutstop` handler to ensure viewport fits large
  graphs.
- **Harris Matrix — orphan edges crash**: Cytoscape threw on edges whose
  source or target node was not in the dataset (deleted records with orphan
  RS rows). Orphan edges are now silently skipped with a `console.warn`.
- **Harris Matrix — "back to table" button missing**: the toolbar only showed
  "back to record" when opened from a specific record. A permanent "back to
  table" button is now always visible; `DataView.openMatrix()` passes the
  current URL as `back` query param so the exact search state is restored.
- **Harris Matrix — URL param mismatch**: `MatrixView` was forwarding
  DataView's URL-friendly `qt`/`q` params directly to `getRsMatrix`, which
  expects `search_type`/`adv`/`search` etc. Added `buildMatrixApiParams()`
  to translate between the two conventions.
- **Race condition on SQLite config rewrite**: `DB::getConnectionDataFromCfg`
  rewrote `app_data.json` on every request when `db_engine` was already set
  (`null !== $cfg['db_engine']`). Concurrent Vue API calls caused partial
  reads → `json_decode` failure → `invalid_configuration_file` error.
  Condition changed to `null ===` so the write only fires for the one-time
  legacy migration.
- **Global error handler returned invalid JSON in debug mode**: `index.php`
  appended raw HTML (`var_dump`, stack trace) after the JSON response body,
  breaking `JSON.parse` in the Vue frontend. Error details already go to
  `logs/error.log`; a `debug` field in the JSON response is now sufficient.
- **Migration runner — PHP parse error**: `{$class::NAME}` string
  interpolation with `::` is not valid PHP syntax. Assigned the constant to
  a local variable before interpolation.
- **Harris Matrix — edge labels translated**: relation labels on graph edges
  now use the active UI locale via `t(REL_KEYS[rel])` instead of fixed
  English abbreviations.
- `getHistory()` query referenced column `user`; actual column in the
  `versions` table is `userid` — fixed with `userid AS user` alias so the
  API shape is unchanged
- `showUserForm()` response was missing the `privilege` field, leaving the
  role Select empty in the user profile dialog
- `pref` class deletion caused `tr::load_file()` to crash on every request
  with `Class "pref" not found` — recreated as stateless store
- `firebase/php-jwt` was not installed, causing a fatal error after
  successful authentication; `generic_error` was shown to the user
- `dumpSqliteNative()`: cursor-based row iteration (no full-table `fetchAll()`),
  gzip level 6 instead of 9 — significantly faster on large databases
- `html`/`body`/`#app` locked to viewport height (`overflow: hidden`) so internal
  containers scroll instead of the page body — record header now stays fixed
- Select/dropdown fields pre-load async options on mount so the current value
  displays correctly in edit mode without opening the dropdown first
- Config App Settings crash: non-sequential PHP array keys now forced through
  `array_values()` so PrimeVue `Select` always receives a JSON array, not an object
- Config table list populates after password-gate submission via `watch` on
  `store.unlocked` (previously only triggered in `onMounted`)
- Toast notifications now show a "Saved" title and the response detail separately,
  eliminating blank toasts when the backend returns no `code`
- Dark-mode-compatible status row colours in ConfigValidation:
  `color-mix(in srgb, ...)` instead of hardcoded `-50` palette tokens
- Multi_select fields now pre-load options on mount so labels resolve in closed state
- Table switch in DataView no longer ignored due to Vue 3 reactive-update batching
- FAB uses `position: fixed` so it is not clipped by `overflow: hidden` ancestors
- **M003 / M004 migrations crash on new apps**: `M003_RefactorQueriesTable` and
  `M004_RefactorChartsTable` executed `SELECT` statements against columns (`text`,
  `date`) that only exist in databases upgraded from v4. Fresh v5 databases never
  had those columns, causing a fatal DB error on first login. Both migrations now
  catch the `\Throwable` and return early when those columns are absent.
- **Privilege name `adm` → `admin`**: the internal privilege string was changed to
  match the token used throughout the codebase; users with the old value were
  silently treated as unprivileged.
- **Welcome markdown not persisting on PUT**: `_fetch()` in the Vue API layer sent
  `FormData` for simple key-value payloads and fell back to `application/json` only
  for complex objects. PHP does not populate `$_POST` from `FormData` on PUT requests,
  so the body was silently discarded and the file was truncated to 0 bytes. Fixed by
  always serialising the body as JSON with `Content-Type: application/json`.
- **Login crash — `$this->db` null**: `constants.php` resolved the app context from
  `$_REQUEST['app']`, which PHP only populates from form-encoded bodies. The Vue
  frontend sends login credentials as `application/json`, so `$_REQUEST['app']` was
  always empty, `APP` was never defined, `$this->db` stayed null, and
  `Manage::__construct()` threw a `TypeError`. Fixed by falling back to
  `json_decode(file_get_contents('php://input'))` when `$_REQUEST['app']` is absent.
- **API response contract inconsistency**: `Controller::response()` emitted `text` as
  the i18n key but `returnJson()` callers used `code`; the frontend read both fields
  in inconsistent order across components, causing untranslated raw codes to appear in
  toasts and error messages. Fixed by: (1) `response()` now always emits both `code`
  and `text` with the same value; (2) `responseMessage()` in the Vue API layer falls
  back to `text` when `code` is absent; (3) all components standardised to use
  `api.responseMessage(res, t)` for messages and `res.code ?? res.text` for throws.
- **Legacy API fallback removed** from `api/index.js`: `ROUTE_MAP` was already 100%
  complete so the `?obj=&method=` fallback path was dead code; removed for clarity.
  Any unmapped route now throws a descriptive error at build time.

### Added (cont.)
- **API key privilege levels**: API keys now carry a privilege level (read / edit /
  admin) stored in a new `privilege` column on `{prefix}api_keys` (migration M006).
  `Auth\ApiKeyAuth` authenticates key-bearing requests and populates `CurrentUser`
  so all existing `utils::canUser()` checks work unchanged.
- **Route-level privilege map** (`Bdus\Router::ROUTE_PRIVILEGE`): every route in the
  FastRoute dispatcher now has an explicit required privilege level
  (`none` | `read` | `edit` | `admin`). `App::route()` enforces this before the
  controller runs — returning 401 for unauthenticated requests and 403 when an API
  key's privilege is insufficient for the route. JWT-authenticated users continue
  to rely on per-controller `utils::canUser()` checks as before.
- `Auth\CurrentUser::isApiKey()`: helper that returns `true` when the current
  principal is an API key rather than a human user.

### Removed (cont.)
- **Table prefix system** (`APP__`): `PREFIX` constant is now always an empty
  string. All table names — user tables and system tables alike — are stored
  and accessed without any application prefix. The prefix was a legacy
  workaround for shared MySQL/PostgreSQL databases; every current deployment
  uses per-application SQLite files, making it unnecessary. See the migration
  note below.

### Added (cont.)
- **End-to-end API test suite** (`tests/api/`): nine Hurl phases cover the
  full application lifecycle — `01` create-app, `02` login/JWT capture, `03`
  config (tables + fields), `04` records CRUD, `05` stratigraphic relations
  (RS), `06` search (shortSql, advanced, SQL expert), `07` charts (save/fetch
  data/delete), `08` backup (create/list/delete), `09` cleanup/logout.
  Run with `bash tests/api/run.sh tests/api/vars.env`.
- **`getRecords()` simple-filter shortcuts**: a GET request to
  `/api/records/{tb}?q_{field}=value` now applies a `WHERE field = value`
  filter without requiring the full ShortSQL syntax. Multiple `q_` params are
  AND-combined. A POST body `{"search": [{"field","operator","value"}]}` is
  also accepted as a shorthand for the advanced search format.
- **`Controller::returnJson()` auto-status**: if the array passed to
  `returnJson()` does not include a `status` key, `"status":"success"` is
  injected automatically, removing boilerplate from every call-site.

### Fixed (cont.)
- **Legacy prefix boot crash**: existing apps created before v5 that still
  have `APP__`-prefixed tables in SQLite (e.g. `test__users`, `test__files`)
  would crash at login with *"Configuration file …/test__files.json not
  found"* because `Config::__construct()` runs before migrations. Fixed with a
  two-part change: (1) `Migrate::maybeRemovePrefix()` is now `public static`
  so it can be called early; (2) `App::start()` calls it immediately after DB
  initialisation — before routing — so every legacy app is transparently
  upgraded on its first request. The pre-flight renames all `APP__*` tables in
  SQLite and rewrites `tables.json`; it is a no-op once the rename is done.
- **`CreateApp` wrote prefixed table names**: `CreateApp::__construct()` was
  passing `APP . '__'` to `Manage` and writing `"{APP}__files"` /
  `"{APP}__geodata"` into the generated `tables.json`. New apps therefore
  booted with the prefix baked in, defeating the removal. Fixed: `Manage` is
  now constructed with `''` and the generated entries use the bare names
  `"files"` / `"geodata"`.
- **Chart `delete` / `share` / `unshare` ignored the route-param `id`**:
  `deleteChart()`, `shareChart()` and `unshareChart()` read only
  `$this->post['id']`, but FastRoute merges path-parameter values into
  `$_GET`. Requests to `DELETE /api/chart/{id}` and
  `POST /api/chart/{id}/share` therefore always saw `id = null` and returned
  *"parameter missing"*. Fixed by reading `$this->post['id'] ?? $this->get['id']`.
- **`GET /api/record/{tb}/new` route missing**: the route for fetching a
  blank new-record form was absent from the FastRoute table; it now returns
  the empty record scaffold.
- **`search_ctrl::getUsedValues()` used legacy `$this->request`**: refactored
  to read `tb` / `field` from `$_GET`, always use `returnJson()`, and return
  `{"values":[...]}` instead of a bare JSON array.

### Removed (cont.)
- **`API\V1\Router` / `API\V1\Auth` / `API\V1\Handler` / `API\V1\Filter`**:
  the legacy read-only REST API surface (`/api/v1/{app}/...`) has been
  removed. Its functionality is fully superseded by the unified
  `Bdus\Router` surface (`/api/...`) which supports both JWT and API key
  auth with per-route privilege control. Clients should migrate to the new
  endpoints: `/api/records/{tb}`, `/api/record/{tb}/{id}`, `/api/tables`.
- **`API\Inspect` / `API\Search` / `API\GetUniqueVal`**: helper classes that
  were only used by `API\V1\Handler`; removed along with V1.
- `/api/v1/` URI prefix intercept removed from `index.php`.

### Added (cont.)
- **Relations panel** (`ConfigView` → Relations tab): dedicated CRUD panel for
  `bdus_cfg_relations` — lists all table-to-table links with field-mapping
  badges, inline add/edit form with lazy field-dropdown loading, and
  confirm-before-delete. Three new `super_admin` endpoints:
  `GET /api/config/relations`, `POST /api/config/relations`,
  `PUT /api/config/relations/{id}`, `DELETE /api/config/relations/{id}`.
  `ConfigTableForm` now renders the Links section as read-only with an
  "Edit in Relations →" shortcut; the table-save payload no longer sends
  `link` so existing relations are never silently overwritten.
- **Migration M020 — deduplicate bidirectional relations**: self-join removes
  redundant `(A→B)` + `(B→A)` duplicate pairs from `bdus_cfg_relations`;
  same-direction duplicates are also collapsed. A `UNIQUE(from_tb, to_tb)`
  index is added afterward to prevent future duplicates. `ToDB::upsertTable()`
  now guards the `upsertRelations` call with `array_key_exists('link', …)` so
  saving a table form that omits the `link` key never clears existing relations.
- **Hurl phase 13 and 14**: `13_migrations.hurl` (DB migration list endpoint)
  and `14_relations.hurl` (13 HTTP requests covering the full relations lifecycle
  — list, create, update, duplicate errors, self-loop error, delete) added to
  the end-to-end API test suite and wired into `run.sh`.
- **PHPUnit `ConfigRelationsCtrlTest`**: 15 integration tests covering
  `getRelations`, `saveRelation` (create, canonical normalisation, duplicate
  guards, self-loop, update), and `deleteRelation` (success, missing id,
  non-existent id), each with a privilege guard assertion.

### Fixed (cont.)
- **`getTablePrivileges()` response envelope**: was returning a bare array;
  now wrapped in the standard `{status, code, data}` envelope consumed by
  `UserPrivilegesPanel.vue`.
- **`Alter\Sqlite::renameFld()` on SQLite < 3.25.0**: the legacy rename path
  (table rebuild) was unimplemented. Now executes: read DDL from
  `sqlite_master`, read column order from `PRAGMA table_info`, rewrite DDL with
  the new column name, create temp table, `INSERT … SELECT`, drop original,
  rename temp — all inside a transaction.
- **`ShortSql\Join` orphaned `$values`**: `$values = $parsedWhere['sql_values']`
  was always empty (the WHERE parser runs with `noValues=true` to preserve
  PDO-binding order) and unused; removed and replaced with a design-rationale
  comment.
- **Hurl phase 13 invalid template syntax**: `{{$.total}}` is not valid Hurl
  variable syntax; fixed by capturing `total` in one request and referencing
  `{{migrations_total}}` in the next.

### Changed (cont.)
- **Bidirectional relation loading**: `Config\LoadFromDB` tags each row from
  `bdus_cfg_relations` as forward (`_inverted: false`) or reverse
  (`_inverted: true`) and auto-swaps `my`↔`other` in the `fld` array for
  reverse reads, so both sides of a relation always see the correct field
  direction without storing duplicate rows.

### Added (cont.)
- **Per-app widget system**: arbitrary JS display extensions can be placed in
  `projects/{app}/widgets/{name}.js` as native ES modules and attached to any
  field via the `widget` property in the field configuration. The backend
  serves them through two new `read`-privilege endpoints:
  `GET /api/widgets` (list available widgets) and
  `GET /api/widget/{name}` (serve module source with JWT auth).
  The Vue frontend fetches each widget with a Bearer token, imports it as a
  blob URL, and mounts it inside a `DynamicWidget` component using a
  `mount(container, value)` / `unmount?(container)` contract. A synchronous
  module-level cache ensures all instances of the same widget name share a
  single module scope (important for singleton-state widgets such as
  quirematrix). See `docs/widget-api.md` for the full contract.
- `GET /api/widgets` — lists `.js` filenames in `projects/{app}/widgets/`
- `GET /api/widget/{name}` — serves widget JS source; names must be lowercase
  letters and hyphens only; path traversal rejected with HTTP 400
- **Hurl phase 12** — data import end-to-end tests (`12_import.hurl`)
  covering CSV field preview, GeoJSON import, and data import; added to
  `tests/api/run.sh`
- **PHPUnit tests for vocabularies, search-replace, and frontpage editor**:
  `VocabulariesCtrlTest` (32 assertions), `SearchReplaceCtrlTest`,
  `FrontpageEditorCtrlTest`; the vocabulary controller was also fixed
  (response-envelope contract, duplicate-check on add)

### Fixed (cont.)
- **M021 migration — `plugin_of` back-fill for v4→v5 upgrades**: databases
  migrated from v4 via M011 had `is_plugin = 1` on plugin tables but
  `plugin_of = NULL`, because the v4 config stored the plugin list in the
  parent table's `extra` JSON. M021 reads that JSON, sets `plugin_of` on
  each child table, and removes the `plugin` key from the parent's `extra`.
  Idempotent; a no-op when `plugin_of` is already set.
- **MapLibre GL — invalid `$type` filter with `Multi*` geometries**: MapLibre's
  `$type` expression returns only the simplified geometry type (`Point`,
  `LineString`, `Polygon`) — never `MultiPoint`, `MultiLineString`, or
  `MultiPolygon`. Filters using `['in', '$type', 'LineString', 'MultiLineString']`
  caused a runtime error. Changed to `['==', '$type', 'LineString']` and
  `['==', '$type', 'Polygon']` in the GeoFace layer configuration; Multi*
  geometries are now matched correctly by the simplified type.
- **MapLibre GL — popup not respecting dark / light theme**: the
  `.maplibregl-popup-content` element is injected by MapLibre outside Vue's
  shadow DOM, so scoped styles had no effect. Added `:global()` CSS rules
  that map PrimeVue design tokens (`--p-content-background`,
  `--p-text-color`, `--p-content-border-color`) onto the popup container and
  all four tip-anchor directions, making the popup switch correctly with the
  application theme.

### Changed (cont.)
- **PHPUnit test suite grows from 167 to 571** tests (2 331 assertions) as
  widget, OAuth, migration, vocabulary, search-replace, frontpage-editor, and
  relation tests are added across multiple sessions.

## [4.4.7] - 2026-05-09

### Fixed
- Re-enabled SQL query validation (`Validator`) which was permanently disabled by a `true === false` guard. Root cause (system tables and auto-joined aliased tables triggering false exceptions) is now fixed: the Validator skips tables not present in the user config instead of throwing.

## [4.4.6] - 2026-05-09

### Fixed
- Removed stale TODO comment in `geoface::saveNew()`: the referenced bug (`$new_id` undefined) had already been resolved; comment was misleading

## [4.4.5] - 2026-05-09

### Fixed
- Fixed inverted condition in `GetChart` that prevented any chart from being retrieved via the API
- Fixed wrong column reference in `GetChart` (`sql` → `sqltext`) that would have caused a DB error after the condition fix

## [4.4.4] - 2026-05-09

### Security
- Upgraded password hashing from SHA1 to bcrypt (`password_hash`/`password_verify`). Existing SHA1 hashes are transparently migrated to bcrypt on next successful login.

## [4.4.3] - 2024-02-06

### Fixed
- Fixed issue short_sql of links not formatting properly table name

## [4.4.2] - 2022-12-22

### Fixed
- Fixed issue with line-breaks being removed from template text upon update

## [4.4.1] - 2022-12-20

### Fixed
- Fixed issue with id_link type not being set correctly on new plugin table creation
- Fixed blocking issue that prevented new vocabularies to be added

## [4.4.0] - 2022-11-28

### Changed 
- Updated copyright year

### Added
- Added the possibility to rotate images in the file galley view

## [4.3.2] - 2022-11-26

### Added

- Fast links to other records support now strings. Spaces must be replaced with `+`. Valid examples are: `@testtable.1`, `@testtable.my+id`, `@testtable.1[One]`, `@testtable.my+id[Some text]`

## [4.3.1] - 2022-11-26

### Fixed

- Fixed issue with vocabularies names not being pushed to popup when a new vocabulary itam was created (issue #11).


## [4.3.0] - 2022-11-24

### Removed
- DARE basemap for GeoFace was removed as a default option. It can be added as a custom Web Tile Service.
- DARE basemap for GeoFace was removed since the external service was not working anymore.

### Added
- GeoFace can be configured to use custom WMS, WTS and locally stored csv, gpx, kml, wkt, topojson, and geojson files

## [4.2.5] - 2022-08-04

### Fixed

- Fixed bug menuValues not working with PostgreSQL

## [4.2.4] - 2022-02-18

### Fixed
- Fixed bug with chart edit module refering sql instead of sqltext

## [4.2.3] - 2022-02-18

### Changed 
- Updated twig/twig from v3.3.2 to v3.3.8
- Updated intervention/image from v2.6.1 to v2.7.1
- Updated michelf/php-markdown from v1.9.0 to v1.9.1
- Updated monolog/monolog from v2.3.2 to v2.3.5

## [4.2.3] - 2022-02-18

### Fixed
- Updated README.md

## [4.2.2] - 2022-01-28

### Fixed
- Fixed issue with pluging not being shown with api.record.read used id_field

## [4.2.1] - 2021-10-19

### Fixed
- Fixed typo auth for aut(omatic)


## [4.2.0] - 2021-10-19

### Added
- Added CITATION.cff

### Removed
- Removed circle feature in geoface

### Fixed
- Fixed bug with Geoface not handling correctly polygon drawing
- Fixed bug preventing the appearance of change password

### Changed
- Updated gulp from v3.9.x to v4.0.x
- Updated datatables.net from v1.10.25 to v1.11.3
- Updated datatables.net-bs from v1.10.25 to v1.11.3
- Updated gulp-terser from v2.0.1 to v2.1.0
- Replaced instances of bradypus.net with bdus.cloud
- Updated sortablejs from 1.13.0 to 1.14.0
- Updated monolog/monolog from 2.2.0 to 2.3.2
- Updated intervention/image from 2.5.1 to 2.6.1
- Code formatting


## [4.1.2] - 2021-08-22

### Fixed
- Keep alive was logging on success not on error.

### Added
- Added CITATION.cff

## [4.1.1] - 2021-06-24
### Fixed
- Fixed bug with utils::recursiveFilter

## [4.1.0] - 2021-06-24

### Added
- Added new admin function: create, edit, delete, rename templates
- Added new function keepAlive: session is automatically updated every 3 minutes
- Added Controller::is_online that replaces utils::is_online
- Inline documentation for \DB\DB
- Inline documentation for \Inspect

### Changed
- Updated select2 from 4.0.13 to 4.1.0-rc.0
- Updated datatables.net from 1.10.24 to 1.10.25
- Updated datatables.net-bs from 1.10.24 to 2.1.1
- Updated gulp-less from 4.0.1 to 5.0.0
- Sub-template system for records uses Controller as Twig initializer
- Updated guzzlehttp/psr7 from 1.8.1 to 1.8.2
- Updated psr/log from 1.1.3 to 1.1.4
- Updated symfony/process from v5.2.4 to v5.2.7
- Updated twig/twig from v3.3.0 to v3.3.2
- UAC object is initialized by Bdus\App and initialized in Controller

### Deprecated
- Deprecated \utils::alert_div
- Deprecated utils::is_online replaced by Controller::is_online

### Removed
- Removed unused Controller::getCacheSettings

### Fixed
- pref::set $val parameter might be string, array or null. Removed type check
- pref::get might return string, array or null. Removed type check
- Fixed bug with Geoface not starting on table without geodata
- Fixed indentation and error handling in version
- Previous (v3) backup files are listed and do not throw error
- Fixed bug with used data not being injected correctly to utils::canUser
- Fixed bugs with type checking

## [4.0.10] - 2021-05-26
### Removed
- Removed support for php 5.4 in api

### Fixed
- Removed debug message in \Record\Read

## [4.0.9] - 2021-05-18
### Added
- All values inserted in system configuration are trimmed by default

### Changed
- Column `creator` is by default of type INTEGER
- Columns `table_link` and `id_link` are hidden bu default
- Button `Create new application` is hidden on success

## [4.0.8] - 2021-05-11
### Added
- Added template method `print.geodata` as shorthand for `print.plg('geodata')`

### Fixed
- Fixed bug with Geodata not being shown
- Code cleaning

## [4.0.7] - 2021-04-17
### Added
- Added support for literal fieldnames (autojoin disabled) in ShortSQL: prefixed by `^`

### Fixed
- Record\Read::getBackLinks uses literal fieldname in ShortSQL
- Fixed bug with ShortSQL returning the same records many times when search on plugins or geodata is performed


## [4.0.6] - 2021-04-11
### Fixed
- Fixed bug introduces with QueryObject code cleaning, and auto_joining not being enabled bu default
- Fixed bug with plugins first record being marked as core
- Fixed bug with plugins not being saved
- Fixed bug with uid not being set on template files
- Many syntax typos fixed


## [4.0.5] - 2021-04-11
### Added
- Changelog visible in app and markdown parser added to composer

### Fixed
- Fixed bug with Links::showCoreLinks not disabling autojoin for shortSql
- Fixed bug with QueryFromRequest::advSearch non opening bracket
- Fixed issue with legacy saved queries with empty values


## [4.0.4] - 2021-04-05
### Fixed
- Fixed issue with Template::value returning null


## [4.0.3] - 2021-04-05
### Fixed
- Fixed issue with adding new record throwing error
- Bigger text for choose application input in login page


## [4.0.2] - 2021-04-04
### Changed
- Cleaner parameter's definition for \Record\Read: first is always integer, second always string


## [4.0.1] - 2021-04-01
### Changed
- Added vendor/**/docs to .gitignore
- Remove manual correction of WKT coordinates
- Config::__construct throws Exception instaead of silently adding errors
- Updated datatables.net from v1.10.23 to v1.10.24
- Updated datatables.net-bs from v1.10.23 to v1.10.24
- Updated datatables.net-plugins from v1.10.22 to v1.10.24
- Updated jquery from v3.5.1 to v3.6.0
- Updated guzzlehttp/psr7 from v1.7.0 to v1.8.1

### Fixed
- Fixed bug with Links::showCoreLinks silently failing
- Fixed bug with automatic replace of legacy template class span-{n} with col-sm-{n} acivated by default
- Addedd funiq/geophp files to git
- utils::debug was not defined as static and was throwing warning
- Typo fix in Changelog


## [4.0.0] - 2021-03-26
### Added
- Almost complete refactoring of PHP scripts
- Added support for MySQL and PostgreSQL
- Added full support for application creation and full management via GUI
- Added support for ShortSQL in API
- Added Changelog
- Add application validation and error fix
- Added Gulp.js for js and css minification
- Added @fancyapps/fancybox
- Added method Controller::response replacing utils::response
- Added depencency spatie/db-dumper
- New error handling using Monolog
- Composer is used for php dependencies
- Security check before accessing system configuration

### Changed
- Deprecated symm/gisconverter replaced by phayes/geophp
- Read::getBackLinks and Read::getLinks return ShortSQL in where key
- cache and cache/img are required directories
- Changed parameter order in QueryObject::setField. Tb is now last parameter
- Missing JS file throw error 404 in .htaccess
- Updated favicon
- Updated dataTables and Sortable
- Refactored file thumbnails
- Composer updated
- Enhanced error reporting
- License changed to GNU AGPL-3.0
- Image (thumbnail) path is set relatively
- Error reporting is set to none in prod and E_ALL & ~E_WARNING & ~E_NOTICE in dev
- Default error reporting is set to: E_ALL & ~E_WARNING & ~E_NOTICE
- Rewritten .htaccess
- chart.sql renamed to chart.sqltext
- Enhaced inline documentation for constants and index
- Enhaced inline documentation for Bdus\App
- Table creation function also creates service columns for plugin tables
- Change column name regex: allowed underscores and min length is set to 2
- package.json in version control
- OSM tiles loaded over https
- Updated Record\Edit and Record\Persist and added Record\Edit::persist method
- New logo
- Record\Read parameters follow little endian order
- Replaced glyphicon with font-awesome
- Javascript moved to js file in vocabularies module
- jquery-sortable replaced by sortable.js
- Font Awesome updated from 4.3.0 to 4.7.0 and loaded from node
- Select2 updated from 4.0.3 to 4.0.13 and loaded from node
- DataTables updated from 1.9.4 to 1.10.22 and loaded from node
- Composer updated
- Same icon for export feature
- Find and replace looks also in plugins
- New template system used in production
- Record\Read::getPlugins renamed Record\Read::getPlugin
- Record\Read supports id_field
- Declare paramaters in record_ctrl
- New column layout for myTemplate module
- Infinte scroll renamed to Infinite scrolling
- Record\Read uses paramater-less methods
- Record\Read uses internal cache
- Config links uses only drop-down menus for fields
- Refactored Export
- Main config db_engine is set to sqlite, if db file exists and cfg is not set (v3 compatibility issue)
- Refactored backup
- Backup uses spatie/db-dumper
- version.user renamed to version.userid
- Namespaced usage cases of pref
- Namespaced usage cases of utils
- Vocabulary definition renamed to item
- utils::dirContent ignores .git
- New login UI
- utils::is_online does not depend on bradypus.net domain
- Explicitly set class form-control on inputs
- Boostrap 3.3.5 updated latest v3 branch 3.4.1
- JS depencencies as node dev dependencies
- Updated jquery from 2.1.1 to 3.5.1
- pnotify replaced by izitoast
- Translation enhanced
- Removed support for tr::sget replaced by tr::get with second optional argument
- Removed support for tr::show and replaced with echo tr::get
- ReadRecord renamed to \Record\Read
- Controller has not direct access to GET / POST / REQUEST
- Google Analytics id is now an application level option
- Cleaner login ui
- Application dies if project folders are not writtable
- Cleaned GeoFace: load valid local layers automatically: no table settings

### Removed
- Removed support for API version 1 and 2 and versioning
- Removed in-app docs in favour of documentation site (docs.bdus.cloud)
- Removed usage of APP constant outside index.php and constants.php
- Removed usage of DIRECTORY_SEPARATOR
- Reduced use of constants in modules
- Removed constant LOCALE_DIR
- Removed constant MOD_DIR
- Removed constant LIB_DIR
- Dropped support for PROJ_TMP_DIR, replaced by system tmp dir
- Dropped support jquery.insertAtCaret.js
- Dropped support for PHP minification of CSS and JS
- Dropped support images class
- Dropped support in file.php for images class
- Some Notices suppressed
- Dropped support for local session dir
- Removed class cfg
- Removed prefix from table name in permalinks
- Removed print.simpleSum
- Removed support for geodata.geo_el_elips and geodata.geo_el_asl
- Dropped support for multiupload
- Disabled backup restore for pgsql
- Removed BackupMySQL
- Removed support for User class
- Removed support for bootstrap-datepicker for HTML5 input date
- Removed support for bootstrap-slider for HTML5 input range
- Removed unnecessary usage of query PDO::FETCH_NUM
- Removed support for download Harris Matrix
- Dropped support for FTP sync
- Removed support for most recent query
- Removed support for GET/POST/REQUEST access in modules
- Removed support for .html templates. Only .twig extension is supported
- Removed backtick syntax from SQL
- Dropped support for Vocabulary class
- Dropped support for Database import
- Removed support for Meta class
- Removed support for myException
- Removed system constants from DB_connection
- Dropped support for controllers not extending Controller
- Removed supporto for Pelagios Imperium (not available anymore) and updated DARE Imperium Url

### Security
- Conditional logging in DB
- Secured API access


## [3.15.17] - 2021-01-07
### Fixed
- Fixed bug with Vocabulary manager


## [3.15.16] - 2020-08-31
### Fixed
- Fixed bug with QueryBuilder


## [3.15.11] - 2020-08-10
### Fixed
- Fixed issue with countable not initialized


## [3.15.10] - 2020-08-06
### Fixed
- readRecord::getFiles uses JOIN and solves issue with sorting


## [3.15.9] - 2020-07-19
### Changed
- ShortSqlToJson uses ReadRecord::getFull instead of Record::readFull


## [3.15.8] - 2020-07-19
### Fixed
- Fixed bug with API v2 post processing data


## [3.15.7] - 2020-07-18
### Fixed
- RS element of ReadRecord::getFull (api2::read)depends on system cfg


## [3.15.6] - 2020-07-11
### Changed
- Better management of PDO options


## [3.15.5] - 2020-07-11
### Fixed
- Bug fixed with DSN string creation


## [3.15.4] - 2020-07-11
### Added
- Inline docs for ShortSQL


## [3.15.3] - 2020-07-11
### Fixed
- Fixed typo in ShortSQL docs


## [3.15.2] - 2020-07-05
### Fixed
- Fixed minor GUI bug in SqlExpert module


## [3.15.1] - 2020-07-05
### Fixed
- Fixed bug History showing error log


## [3.15.0] - 2020-07-01
### Removed
- Removed support for constants PROJ_CFG_TB, PROJ_CFG_APPDATA, PROJ_CFG_DIR, PROJ_TMPL_DIR, PROJ_EXP_DIR, PROJ_BUP_DIR, PROJ_FILES_DIR, PROJ_GEO_DIR, PROJ_DB.

### Changed
- Enhanced error reporting
- DB connection information for engines other than sqlite are located in app_data.json
- Throwable catched in index and controller

### Fixed
- Fixed bug with password recover


## [3.14.0] - 2020-01-20
- Api2::getUniqueValue uses Short Sql syntax
- Fixed bug with debug module
- Refactor QueryBuilder and added JOIN support
- Api::getUniqueVal extracted as new class
- Api::getVocabulary uses Vocabulary class
- Introduced class SqlFromStr in Api::getVocabulary and Api::Search


## [3.13.11]
### Fixed
- Fixed bug with getFullFiles method


## [3.13.10]
### Fixed
- Fixed bug with duplicate values in api2::getUniqueVal


## [3.13.9] - 2020-06-05
### Fixed
- Fixed bug not allowing new vocabularies to be defined if no vocabularies are available


## [3.13.8] - 2020-05-02
- Removed app_data.search_code


## [3.13.7] - 2020-03-03
### Fixed
- Fixed bug with geoface not pasing table variable


## [3.13.6] - 2019-12-13
### Changed
- Api::getVocabulary return max 500 records, sorted by `sort`


## [3.13.5] - 2019-12-03
- Fixed bug with id_from_tb referencing same tables multiple times for same record


## [3.13.4] - 2019-11-19
### Added
- New API method getVocabulary


## [3.13.3] - 2019-10-23
### Added
- Inline docs enhanced & minors fixes in User class

### Fixed
- Fixed issue with incorect initialization of Meta DB
- Fixed minor undefined Constat issue


## [3.13.2] - 2019-08-06
### Fixed
- Fixed minor bug in support for automated internal links in the form of @tb.id or @tb.id[label]


## [3.13.1] - 2019-07-16
### Fixed
- Fixed bug with JOIN statement in read record, api version 2


## [3.13.0] - 2019-07-12
### Added
- It is possible to add reference to internal elements via @{table-name}.{record-id} syntax


## [3.11.3] - 2019-07-10
### Fixed
- Fixed bug with toGeoJson accepting now complex geometry field name


## [3.11.2] - 2019-07-09
### Fixed
- Fixed bug with ReadRecord::getTbRecord


## [3.11.1] - 2019-07-01
### Changed
- In GeoJSON export process single row error does not block entire process


## [3.11.8]
### Removed
- Removed hardcoded prefix divider (__)


## [3.11.7] -  2019-01-23
### Changed
- Files are sorted in ReadRecords


## [3.11.6] - 2019-01-23
### Fixed
- Fixed bug with getFiles in API


## [3.11.5] - 2018-10-21
### Changed
- Plugin records are ordered using sort, if available

### Fixed
- Bug fixed with getUniqueVal in id_from_tb fields


## [3.11.4] - 2018-09-17
- Meta::addVersion is run before edit query is executed


## [3.11.3] - 2018-09-17
### Added
- API inspect outputs also plugin fields


## [3.11.2] - 2018-09-14
### Added
- Added support for getUniqueVal API method
- New API method getChart
- New API method inspect all, and more information returned in view record method
### Changed
- Backlinks return `DISTINCT` values


## [3.11.1] - 2019-06-22
### Changed
- Added support for a more granulated error & history log


## [3.10.5] - 2019-12-13
### Fixed
- Fixed bug with force update


## [3.10.5] - 2019-12-03
### Changed
- Updated info


## [3.10.3] - 2019-12-03
### Added
- Added field setting force_default


## [3.10.2] - 2019-12-03
### Changed
- Def_value is now available also in editing mode


## [3.10.1] - 2019-06-28
### Changed
- In GeoJSON export process single row error does not block entire process

### Fixed
- Fixed bug with version::current


## [3.10.0] - 2019-06-28
### Changed
- Images are down-sized to 1500px by default. The value can be changed in app data


## [3.9.8] - 2019-05-20
### Added
- Added control for sessions folder, created on the fly, if not available


## [3.9.7] - 2019-05-18
### Changed
- Set default field order (id) for tables with order not set


## [3.9.6] - 2019-05-14
### Fixed
- Fixed bug with ambiguous field name in Query::setOrder


## [3.9.5] - 2018-08-29
### Added
- Added support for getChart method
- Added support for preprocess in API
- API's geojson param supports GET & POST
- Encoded query in api supports GET & POST
- Addedd support for geojson export
- API v2 implemented verb inspect
- API v2 implemented and tested read method
- Added support for API v2
- Version info added
- Table label is added to corelinks array
- Added support for backlinks in view record API method
- Added metadata section in the view record object with full information about the referenced table
- Added support for inspect all in API + enhanced docs
- Added Meta::logError & Meta::logException
- New error log module
- Automatically load modules
- Added method Meta::guessTable
- Added support for a more granular error & history log

### Changed
- Empty rows are excluded in advanced search
- Updated support for query_where in response
- Enhanced error reporting in API v2
- Enhanced error reporting in API
- Better control of backlinks
- Input class instace can be Exception or Error
- Enhanced error log, removing redundancy
- getTraceAsString replaced by full trace export
- Insert queries are excluded from versoning
- Page parameter is always converted to integer and is bigger than 0
- Fatal errors are captured and logged in catch group
- Empty queries are not run
- Query can be null
- Added possibility to run modules without need of js files
- Module debug fetches data from Meta table
- error_log replaced by Meta::addErrorLog
- My history gets data from Meta
- DB uses Meta for record history and error log

### Removed
- Removed support for self refering joins
- Empty links and plugins are not returned
- Files are removed from from manual links
- Deleted useless js debug modules
- Dropped support for utils::packlog
- Dropped support for logs packing and user log managed by Meta
- Dropped support for constant PROJ_HISTORY
- Dropped support for history::autopack and history delete

### Fixed
- Fixed bug with chart deletion
- Fixed bug with history file size being compressed
- Minor fixes in autoloader
- Typo fix
- Fixed bug with Where statement repeated
- Inline docs enhanced
- Bug fixed with wrong filter


## [3.9.4] - 2018-06-22
### Fixed
- Fixed bug with history file size being compressed


## [3.9.3] - 2018-05-07
### Added
- Added support for data preprocess & manipulation in API


## [3.9.2] - 2018-05-07
### Added
- Added support for data postprocess & manupulation in API

### Changed
- History log files are gzipped and backed up if bigger then 1Mb


## [3.9.1] - 2018-05-03
### Added
- Added support for data postprocess & manipulation in API


## [3.8.17] - 2018-05-03
### Changed
- Autoloader supports both php & inc extensions


## [3.8.16] - 2018-05-02
### Added
- Implemented verb inspect in API
- Api support for geoJSON
- Added docs for geoJSON
- Added support for sorting plugins items using the `sort` field

### Changed
- Simplified geoJSON creation

### Fixed
- Fixed bug with multi select fields in plugins


## [3.8.15] - 2018-04-17
### Changed
- Simplified geoJSON creation


## [3.8.14] - 2018-03-26
### Added
- Added support for sorting plugins items using the `sort` field


## [3.8.13] - 2018-03-15
### Fixed
- Fixed bug with multi select fields in plugins


## [3.8.12] - 2018-03-08
### Changed
- New SQL query for matrixes


## [3.8.11] - 2018-03-08
### Added
- All delete errors are logged


## [3.8.10] - 2018-03-01
### Fixed
- Fixed bug with js clone method


## [3.8.9] - 2018-02-11
### Added
- API supports limit_start and limit_end parameters
- Per table options show after data is loaded


## [3.8.8] - 2017-12-31
### Fixed
- Fixed bug with vocabulary lit not being editable


## [3.8.7] - 2017-12-13
### Added
- Added foreign keys constraint for sqlite


## [3.8.6] - 2017-12-11
### Fixed
- Fixed bug with query pagination
- Fixed bug Multiple records view
- Fixed bug with stratigraphic relationship module non running with multiple records
- Fixed bug with Print all button not working with multiple records


## [3.8.5] - 2017-12-02
### Added
- Added support for limiting in queries & API
- Added support for grouping in API
- Added support for grouping in queries

## [3.8.4] - 2017-11-25
### Removed
- No round corners in layout

### Added
- Added table_label in Api

## [3.8.3] - 2017-11-13
### Added
- Added backlinks support

## [3.8.2] - 2017-10-12
### Changed
- Google maps key moved to main application data

## [3.8.1] - 2017-10-11
### Added
- New Imperium (Pelagios & DARE) and AWMC basemaps to Geoface

## [3.7.3] - 2017-10-10
- jQuery change observer replaced with on('change'
- Fixed bug with ambiguous plugin field names

## [3.7.2] - 2017-09-28
- Log file's max size lowered to 3 mb

## [3.7.1] - 2017-09-24
- Added new fullrecords parameter in API
- If GoogleMaps key is missing google layers will not load by default
- Addedd https support for domain db.bradypus.net
- Bug fixed in Query::getResults()



## [3.7.0] - 2017-09-23
- Added support for csv, gpx, kml, wkt, topojson, geojson
- Updated version of Leaflet Omnivore
- Removed support for EPSG other then 4326
- Removed support for openLayers geoface and updated leaflet geoface with support for googlemaps key
- Leaflet updated to v 1.2.0
- Leaflet KML replaced by Leaflet Omnivore
- Leaflet.Draw updated to v.0.4.12 (from v. 0.2.0-dev)
- Leaflet updated to v 1.2 (from v. 0.7.2)
- Geoface2 renamed to geoface
- Removed support for OpenLayers geoface
- Leaflet GoogleMutant replaces Leaflet Google
- Added support for GoogleMaps key


## [3.6.2] - 2017-09-15
- Bug fixed with Advanced search inputs

## [3.6.1] - 2017-09-12
- API: q_encoded accepts fields and join parameters
- API: added the possibility to list output fields in url
- Added sqlExpert method to API
- Field list is get fromQuery object

## [3.5.11] - 2017-09-09
- Fixed bug with enhanced inputs not extending to 100% width
- menuValues::getValues groups values for get_values_from_tb

## [3.5.10] - 2017-09-05
- Bug fixed with plugins having custom template file (index was not spread to the whole template)

## [3.5.9] - 2017-08-18
- Aborted XHR calls do not throw errors anymore

## [3.5.8] - 2017-07-24
- Geodata can be entered as plugin data
- Added direction: rtl option for html.fld for right-to-left scripts

## [3.5.7] - 2017-07-10
- New API documentation
- Disabled advanced and sqlExpert queries. Encoded should be used instead of them
- A cleaner e more (inline) documented version of the API is available
- Removed php_value upload_max_filesize and php_value post_max_size 16M from .htaccess file

## [3.5.6] - 2017-06-21
- Bug fixed with advanced search (select2 inputs) interface

## [3.5.5] - 2017-06-28
- New template function Fieled::value to get nude value of a field

## [3.5.4] - 2017-06-21
- Bug fixes: Record::getAllPlugins was not loading plugins due to a comparison error
- Removed custom uid template parameter
- Added join support

## [3.5.3] - 2017-06-14
- Bug fixes: select inputs have now Bootstrap's class form-control

## [3.5.2] - 2017-06-12
- New feature: advanced query ui supports auto-population for fields id_from_tb
- New feature: advance query works now also for fields populated by id form other tables
- Fields marked as id_from_tb show now correct values instead of ids in read/edit mode
- All select input have a blank option as first value
- Bug fixed with combobox not accepting user custom values

## [3.5.1] - 2017-06-05
- Bug fixed with menuValues not setting the ids correctly

## [3.5.0] - 2017-06-02
- New backlinks functionality, for records referred by 1-n plugins
- All dropdown menus are handled via AJAX
- Select2 updated to version 4.0.3
- Added new QueryBuilder class
- Added Controller::json method to echo json data with correct header


## [3.4.7] - 2017-05-13
- First draft of API markdown docs
- Addedd AWMS and Imperium basemaps to GeoFace
- Record::delete and Record::fullFiles is value wrapped in single quotes

## [3.4.6] - 2017-04-26
- Bug fixed with userlinks using first cell of row for row id. The tr#id is used now
- Userlink actions linked to buttons in UI are now namespaced: multiple actions were attached to same button

## [3.4.5] - 2017-02-04
- Field help is now tooltip content not title
- Plugin templates have automatic unique id template variable

## [3.4.4] - 2017-01-08
- Text linkify function considers also https protocol

## [3.4.3] - 2016-09-29
- Plugin fields in Advanced query UI bear the name of the plugin

## [3.4.2] - 2016-09-19
- Minor bug fix in main CSS file
- JS & CSS compressors and outputers use sha256 algorithm to check changes in files

## [3.4.1] - 2016-09-05
- Fixed translation error in Vocabularies module
- Bug fixed with Record::loadAllPlugins
- A more detailed error report in class Query
- API uses Access-Control-Allow-Origin: *instead of jsonp

## [3.4.0] - 2016-09-03
- Minor bug with regex check: empty values are not tested
- New function: the virtual keyboard is now an option of main application, by default turned off
- New function: new search input in home page to easily find a function
- Debug mode is handled via DEBUG_ON constant
- Translation strings are not stored in SESSION variable anymore
- Configuration data are not stored in SESSION variable anymore
- Added docx, xlsx and pptx to known file extensions

## [3.3.9] - 2016-05-26
- Fixed bug with spaces and other special chars used in fields which content is used in RS module

## [3.3.8] - 2016-04-02
- Fixed minor graphical issue: image thumbnails without proper margin

## [3.3.7] - 2016-03-14
- Minified js and css are refer to main version number when loaded
- Bug fixed: date-picker on cloned inputs was not being destroyed and reinitialized

## [3.3.6] - 2016-02-03
- Session files older than 24h will be deleted on logout

## [3.3.5] - 2016-02-02
- Bug fixed with error in deleting user

## [3.3.4] - 2015-12-27
- Minor graphical improvements in Geodata Import Module

## [3.3.3] - 2015-07-05
- ID columns are hidden from result tables if id column is not used as user column (is hidden in cfg file)

## [3.3.2] - 2015-07-05
- FTP sync enhancement

## [3.3.1] - 2015-07-05
- Twig upgraded to v. 1.18.2
- Font Awesome updated to version 4.3.0
- Twitter Bootstrap updated to v. 3.3.5
- Production ready javascript included in git
- Bug fixed with icon path
- Added to git production ready css file

## [3.3.0] - 2015-07-05
- Added new utility class for project structure check
- Added support for Italian in getIP module (windows only)
- Minor style fixes in multiupload module
- Removed composer
- Removed rjkip/ftp-php from composer
- Removed symm/gisconverter from composer
- Removed ojejorge/less.php from composer
- Removed Twig from composer
- Upgraded Twig to v. 1.16.2
- Upgraded oyejorge/less.php to v1.7.0.2

## [3.2.11] - 2014-12-17
- Bug fixed with Matrix error report

## [3.2.10] - 2014-10-12
- API accepts new optional GET parameter: records_per_page (default value: 30)

## [3.2.9] - 2014-07-29
- Bug fixed with javascript compressing tool

## [3.2.8] - 2014-07-27
- Bug fixed with user privilege management in login module

## [3.2.7] - 2014-06-27
- Font Awesome updated to version 4.1.0

## [3.2.6] - 2014-06-27
- Bootstrap updated to version 3.2

## [3.2.5] - 2014-06-27
- Bug fixed with offline and frozen apps

## [3.2.4] - 2014-06-27
- Removed offline status option

## [3.2.3] - 2014-06-22
- Bug fixed with user template setting

## [3.2.2] - 2014-06-12
- Bug fixed with reset password module

## [3.2.1] - 2014-05-18
- Bug fixed with user data editing module
- Bug fixed with default value field setting not saving properly in the database
- Bug fixed with advanced search templates, still looking for .html templates
- Bug fixed with result UI component: Harris' Matrix icon was not showing
- Bug fixed with Field::permalink. Main div element was not closed
- Bug fixed with utils::recursiveFilter

## [3.2.0] - 2014-05-03
- New feature: added the possibility to upload and link to existing records external geoJSON files
- Minify_CSS_Compressor deprecated. oyejorge/less.php is now used for LESS compilation and CSS minification
- jQuery updated to v 2.1.1
3.2.0[] =  "New mode for handling compressed javascript files
- Leaflet updated to v 0.7.2
- Leaflet.Draw updated to v. 0.2.0-dev
- Font Awesome updated to v. 4.0.3
- Changed all .html extensions in modules template files to .twig
- Updated all code headers with new license file

## [3.1.27] - 2014-05-03
- Code inline documentation
- utils::recursiveFilter accepts now a second, optional, argument to use as filter function
- Log compression is performed also on cookie authentication
- User information is now logged also on cookie authentication

## [3.1.26] - 2014-04-27
- Bug fixed with record::showResults method, ignoring select_one parameter

## [3.1.25] - 2014-04-27
- Minor bug fixed with Geoface 2 onclick popups: no link is showed in the popup window

## [3.1.24] - 2014-04-27
- Query::getResults returns more complete results about records

## [3.1.23] - 2014-04-27
- Minor bug solved on login module: app name was not correctly transmitted

## [3.1.22]
- Geojson layers in Geoface 2 module show now popup infowindows on click

## [3.1.21] - 2014-03-25
- Bug fixed with API module and RS plugin

## [3.1.20] - 2014-03-22
### Changed
- jQuery updated to v.2.1.0
- Twitter Bootstrap updated to v.3.1.1

## [3.1.19]
### Fixed
- Compatibility fixes: multiupload template files have now .twig extension
- Minor code layout fixes
- Bug fixed with show saved query results triggered by hash data

## [3.1.18]
### Added
- uid variable available in all application templates
### Fixed
- Minor code layout fixes in Controller
- Minor layout fixes in Backup module
- Bug fixed with myHistory modal rendering

## [3.1.17]
### Added
- New API function: #/app/table/{id} hash show record by id #/app/table/{id}/id shows record by id field

## [3.1.16]
### Changed
- Vector layer of GeoFace (2) is now rendered using circles, not icons
- Minor UI and code layout fixes
- New versioning system

## [3.1.15]
### Fixed
- Minor layout fix in Chart module
- Bug fixed with user permissions (R&W own records users)
- Minor typo fix
- Bug fixed with multiselect fieldtype in read mode (dynamic linking)


## [3.1.14]
### Fixed
- Bug fixed with date format


## [3.1.13]
### Fixed
- Bug fixed with main record controller: ID and ID field were confused


## [3.1.12]
### Changed
- Multiselect values are now processed separately in read and edit mode

## [3.1.11]
### Fixed
- Fixed bug with multiple record read mode (last record was missing)

## [3.1.10]
### Changed
- Links now show link to Harris' Matrix, if destination table has Stratigraphic Relationship plugin activated

### Fixed
- Bug fixed wit boolean field types

## [3.1.9]
### Removed
- Removed XML metadata XSD files

## [3.1.8]
### Changed
- Controller::render() is initialized with autoescape set to false by default
- Replaced, when possible, manual initialization of Twig with Controller::render() method

### Fixed
- Bug fixed with print all function (read multiple records)


## [3.1.7]
- Print style enhancment. Bug fixed with images height and added new css classes in Field to hide in print mode services as geoface, links, permalink, etc.

## [3.1.6]
- Geodata Field element uses now Geoface2 instead of geoface
- Bug fixed with geoface2, not using filter sql
- makeproject class is now part of the core installation

## [3.1.5]
- Critical bug in plugin (template) management: plugins could not be edited or deleted!
- New function: admin users have superadmin privilege in offline installation

## [3.1.4]
- New function: if any field as defined the new cfg value, active_link, in read mode it's content will be active, a link to a query result with similar records will be shown

## [3.1.3]
- Chart builder UI enhancements and new function: Function is now defined for each Bar, not for each chart.

## [3.1.2]
- Inline documentation enhancment to api.js
- Bug fix with dot creation in (Harris) Matrix main class
- Bug fix with matrix svg height in FF (now is set via javascript)
- RS table columns now have equal width

## [3.1.1]
- Bug fix with record/result show query (rev.329)
- Enhanced dynamic linking in read mode from fields valued to other records

## [3.1.0]
- Bug fixed: urlencoded query was nor urlencoded!
- Composer dependency update check (no update performed)
- Local repository name change: v3_final is now v3
- FTP connection is now handled via Composer
- Export GUI used TBS, instead of inline style
- Bug fixed with GeoFace 2 (Leflet). Map initialized with empty DB vector layer had no view /zoom set
- Bug fixed with GeoFace 2 (Leflet). Second initialization did not work
- Bug fixed with Chrome not submitting forms on Enter pressed
- New function in Sync: change online application status.
- Matrix icon changed to font awsome sitemap
- New function: Bi-directiontal sync (super_admin privilege required; available only in offline mode; config FTP connection required)
- Short version of utils::is_online
- Comment added to utils::canUser()
- GET, POST and REQUEST parameters are now optional in class initialization
- Comments added to core.message()
- Composer file added
- New Geoface module added
- External libraries are now managed with Composer
- External libraries are now managed with Composer
- Add new relation form is inline again (BS 3 fix)
- When a relation is successfully added the input will be cleared
- Harris Matrix builder now supports equal relationship
- Minor typo fix
- Error text is displayed in BS alert
- api.record.read makes difference now in tab title in cade of use of ID and ID_FIELD.
- Prefix also is removed from tab title
- Record::addRS removes whitespaces before and after each element to add in database (trim)
- Fixed bug with non existing records
- Updated external code list
- New Harris Matrix module
- Matrix has a new Font Awesome icon
- Bootstrap 3 compatibility and translation fixes
- DB::query's can return a single column, by setting $type to an integer (the offset -0 based- of the column to return)
- New comment for core.getJSON method
- Added new migration script (AD project)
- Added new migration script (AD project)
- Controller::render supports TWIG extension for modules templates
- Bug fixed with fields config: get_values_from_tb will not always check for id_field (plugin tables do not have id_field)
- Local geodata files are loaded only if they are geojson
- Minor typo fixing
- Fixed some typos
- More inline comments
- Record::addGeodata returns last added id, instead of boolean value
- utils::response has now a new argument used to pass default key-values in response
- Removed console log on dialog close
- BS v3 compatibility issue fixed (navbar-default class added to map toolbar)
- Version displayed in login page
- Test module moved in frontpage's Options section
- Bug fixed with combobox and select+ cloned elements; options were doubled.
- method Field::cell($nr) added to interface and commented
- BS Glyphicon instead of Font Awesome icon for multiupload
- BS v3 compatibility issue fixed
- Bug solved with vocabularies control class: method had parameters, now removed
- Bug solved with Advance query brackets: cloned bracked did not have any action defined
- All instances of col-lg-* replaced with col-sm-* (or col-md-*)
- Bootstrap 3 minor comptaibility fixes
- Production divided from development and test environment for js files
- Production divided from development and test environment for css&less files
- Production divided from development and test environment for css&less files
- Bootstrap update
- Production divided from development and test environment for css&less files
- Panel component fixes (div.panel now needs panel-default)
- Bug fixed: rs field was named id_field
- Upgraded to jQuery 2.0.3 (from 1.9.0)
- Minor typo fix
- New tbs_editor module, with new, more readeable, layout
- Solved bug with modal (black background still visible after modal was closed)
- Enlarged input colmn
- Jumbotron application name is now contained in Div.visible.xs
- Bug fixed: backups created from utils::packLog did not have log extension
- Added support for Twig's debug function, in debug mode
- Bug fixed and new function added to myClone
- Bug with jquery ajax solved.
- Compatibility fix for BS 3 (rc2)
- Problem with overflow-x solved
- Bootstrap Update (v3 RC2)
- Upated Info page with new libraries and paths to licenses texts
- PHP-SQL-PARSER moved in dev, not used yet
- New function: All system logs are automatically packed (gzipped), backed-up and emptied if bigger than 5MB, when user logs in
- Resetted test module
- Login style enhancment
- Updated todo list
- Icon play near application name is hidden in small devices
- New feature in Advanced search: get list of used values if needed
- New responsive layout of advanced and expert search
- Removed any reference of col-lg-*; col-sm-* in Bootstrap 3-rc2 is sufficient
- Added Aplication name and description in home page, in phone view
- Updated to Twitter Bootstrap v3 rc2 and some small bug fixing
- Datatables compatible now with Twitter Bootstrap 3
- Using now uncompressed DataTables
- Fixed Datepicker
- Fixed Slider
- Some more changes to meet TB3
- jQuery-UI sorting replaced
- jGrowl replaced
- Boostrap LESS adopted
- All instances of span* replaced
- Many instances of button.btn fixed
- All instances of .icon fixed
- Deleted referenced to jQuery-UI, css, js and images

## [3.0.56]
- Minor bug fixing
- New (superAdmin) function: empty application cache

## [3.0.55]
- Removed deprecated core.myDia()

## [3.0.54]
- Minor bug fixing in record > show

## [3.0.53]
- New re-organized GUI for file & image display & management (Field::image_thumbs)

## [3.0.52]
- api.upload deprecated and removed (commented out)
- api.filUpload accepts new option: button_text

## [3.0.51]
- New functionality: files can be uploaded and automatically linked to records in Edit Record page.

## [3.0.50]
- New functionality: now plugins can have a layout (based on Twig)

## [3.0.49]
- English locale minor fixes

## [3.0.48]
- New function can be used in template to show complex, sql based, sums of fields of from other tables somehow related (see filtering options) to the current record in the current table: <sqlSum tb="other_tb_name" fld="other_table_field_name_whose_values_will_be_summed" filter="some_filtering_options_refer_to_doc_for_more_info" />

## [3.0.47]
- New function can be used in template to show simple sums of fields of the current record in the current table: <simpleSum fields="field1, field2, etc" />

## [3.0.46]
- Minor bug fixed: htmlComboSelect can now have 0 menu values

## [3.0.45]
- Bug fixed cookie authentication

## [3.0.44]
- Bug fixed with sqlite character encoding in migration script

## [3.0.43]
- Documentation enhancment and minor bug fixing with application migration system

## [3.0.42]
- API supports now JSONP instead of JSON cross-domain calls

## [3.0.41]
- Documentation improvement

## [3.0.40]
- Query::getResults now can return full records (with links and images)

## [3.0.39]
- Bug fixed with htmlMultiSelect without values.

## [3.0.38]
- Removed any support for jquery-ui dialog

## [3.0.37]
- Removed jquery.dialogextend.js

## [3.0.36]
- 

## [3.0.35]
- Bug fixed with edit form submission

## [3.0.34]
- New system email GUI

## [3.0.33]
- Bug fixed with cookie authentication (cookie was used by default)
- Bug fixed with cookie authentication (cookie was used by default)

## [3.0.32]
- Javascript core.myDia deprecated!

## [3.0.31]
- Changed myImport and removed core.myDia

## [3.0.30]
- Controller::render now supports Twig's debug functon in debug mode
- Translate function is now automatically added in Controller::render()
- Search&replace functionality does not use anymure jqueryUI dialog

## [3.0.29]
- Locale update
- CSS Bug fixed (real time form control messages)
- New feature added: duplicate records (excluding plugin data and manual links)

## [3.0.28]
- Bug fixed with edits and multiple tabs opened
- minor layout issue

## [3.0.27]
- Bug fixed with new records. Last ID is returned directly from recod object
- Autocomplete on is the default value in forms
- Bug fixed with Fast serach and sql Expert (url en/decode)
- Bug fixed with Advanced query, not empty option
- Bug fixed with search/replace function
- Added new core functionality: API for external access to the data
- Table name added in Advanced search and SQL Expert result tab

## [3.0.26]
- 

## [3.0.25]
- New functionality: preview fields can be configured by each user on users' preference panel

## [3.0.24]
- Online version of Harris matrix creation now relys on BraDypUS public Graphviz API (https://github.com/jbogdani/phpGraphviz)

## [3.0.23]
- New functionality: user can set number of most recent records to show in result view in user's preferences panel

## [3.0.22]
- app_data_editor uses template system
- api.requireRestart uses bootstrap modal instead of jqueryUI dialog

## [3.0.21]
- Bug Fixing: logout did not work if DD data was not initialized properly. New method added that do not use not the DB.
- Bug Fixing: RS links were not styled as links (underline)

## [3.0.20]
- GeoFace accepts x/y user input data and browser (GPS on mobile device) location data.

## [3.0.19]
- (dev) TODO updated
- (dev) TODO updated

## [3.0.18]
- Enhanced and tested Run Free SQL
- Bugfixing in api.fileUpload
- Bugfixing in DB: duQuery now catches PDO exceptions and throws myException
- (dev) TODO update
- (dev) New geolocation (browser) concept

## [3.0.17]
- Index did not included updated reference to fineupload
- Bug in cookie data loading: User preferences was not saved in cookie variable.

## [3.0.16]
- Valums fileuploader updated to fine-uploader 3.4.0
- All file upload references use now a single javascript API (api.fileUpload) and a fingli php processor API (file_ctrl::upload)
- Controller file_ctr extends Controller

## [3.0.15]
- Upload file bug: when a file thet is not an image was loaded(eg. pdf file), No icon was shown in the preview!

## [3.0.14]
- CSS fix: a and span.a in content fields is shown as underlined

## [3.0.13]
- Multiupload procedure uses class Record to save links ad data in the database
- Translation update

## [3.0.12]
- In multiupload procedure links can be added by users using GUI

## [3.0.11]
- On add link result view double click on record (read) is disabled

## [3.0.10]
- Toogle select was repeated two times in Result's navbar menu

## [3.0.9]
- New Charts GUI and API (no myDia)

## [3.0.8]
- New Charts GUI and API (no myDia)
- New multiupload GUI (no myDia)

## [3.0.7]
- layout loaded option has as unique parameter the jQuery object where content is inserted

## [3.0.6]
- Saved Queries and search modules does not use anymore myDia

## [3.0.5]
### Changed
- Locale update
- Debug module does not use any more deprecated myDia

## [3.0.4]
### Changed
- New login GUI, modal-less, for easier visualization on tablet/phone

## [3.0.3]
### Added
- getJSON accepts multi-type get parameters (string, array, plain objects)

### Changed
- Debug info can be send as POST or GET (REQUEST is used instead of GET)
- Render method accepts $data = false
- core.runModule now has a third parameter, loaded, a function that is run after init method is called

### Fixed
- Translation updated
- List of apps in login screen is not a javsacriot function, but a html template
- Compatibility mode for systems missing MySQL
- Login module enhancment: all login functionality is now handled by this module.

## [3.0.2]
### Added
- New functionality: save preferences in database

## [3.0.1]
### Added
- New functionality: remain connected
- Important: record_ctrl now works with request data (both post & get) not only with get
- Combo field type added
- LESS instead of CSS
- Bootstrap update
- uddiyana migration
- Uddiyana migration
- layout.tabs andlayout.dialog accepts plain object as param method
- javascript mini removed from svn
- New tbs_editor GUI (tab based)
- New flds_editore GUI (tab based)
### Fixed
- Minor debug and todo fixes
- Login styling transfered to CSS
- Image preview enhancment (keep image ratio)
- makeProject enhancement: bug fixing and migrateApp method add, for easily migrating existing apps

## [3.0.0]
- New 3 version
