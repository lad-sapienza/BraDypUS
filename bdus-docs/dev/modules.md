---
title: Modules directory
---

# Modules directory

Each module lives in `modules/{name}/{name}.php` and defines a class
`{name}_ctrl extends Controller`. The autoloader resolves `foo_ctrl` →
`modules/foo/foo.php` automatically.

25 modules are currently active. They are grouped below by function.

---

## Authentication & session

### `login` — Authentication
`POST /api/auth/login` · `GET /api/auth/apps` · `GET /api/auth/refresh` · `GET /api/auth/logout`

Handles password login, JWT issue, token refresh, and logout.
`listApps()` returns the list of available applications including their
configured OAuth providers.

### `oauth` — OAuth2 / SSO
`GET /api/auth/oauth/{provider}/redirect` · `GET /api/auth/oauth/{provider}/callback`

Authorization Code flow for Google and ORCID.
See [OAuth2 / SSO](./oauth) for the full documentation.

### `confirm_super_adm_pwd` — Password confirmation
`POST /api/admin/check-password`

Used by the frontend to re-confirm the super-admin password before
destructive schema operations.

---

## Data — core CRUD

### `record` — Record management
`GET/POST/DELETE /api/record/{tb}/{id}` · `GET /api/records/{tb}` · and many sub-resources

The most important module. Handles:
- Fetching single records and paginated lists
- Creating, updating, deleting records
- File upload / deletion
- Stratigraphic relations (RS) — add / delete
- Manual links between records
- Record versions (history + restore)
- Export

Delegates all read logic to `Record\Read`, all write logic to `Record\Edit`.

### `search` — Search configuration
`GET /api/search/{tb}/config` · `GET /api/search/{tb}/values`

Returns the advanced search field configuration and the list of distinct
values for a given field (used for autocomplete / filter dropdowns).

### `search_replace` — Bulk find & replace
`GET /api/search-replace/tables` · `GET /api/search-replace/{tb}/fields` · `POST /api/search-replace`

Super-admin-only bulk replace across a table column.

### `vocabularies` — Controlled vocabularies
`GET /api/vocabularies` · `POST /api/vocabularies` · `PATCH /api/vocabulary/{id}` · `DELETE /api/vocabulary/{id}` · `POST /api/vocabularies/sort`

Full CRUD + sort for vocabulary items used in `select`-type fields.

### `import` — Data import
`POST /api/import/data` · `POST /api/import/geojson` · `POST /api/import/photos` · previews

Imports records from CSV, GeoJSON, or bulk photo uploads.
Provides preview endpoints so the frontend can show a dry-run before committing.

---

## Data — reading & navigation

### `home` — Table list & migrations
`GET /api/tables` · `GET /api/migrations`

`listTables()` returns the ordered list of tables the current user can access.
`getMigrations()` returns the migration status (admin-only).

### `myHistory` — Navigation history
`GET /api/history`

Returns the recent record history for the current user (stored in
`bdus_history`), used by the frontend to populate the "Recent" dropdown.

### `saved_queries` — Saved searches
`GET/POST /api/saved-queries` · share / unshare / delete

Users can save and optionally share named search queries stored in
`bdus_queries`.

---

## Configuration & admin

### `config` — Application configuration (super-admin)
`GET/PUT /api/config/app` · `GET/POST/PUT/DELETE/PATCH /api/config/table/{tb}` · fields · relations · geoface · validation

The most complex module. Super-admin only. Manages:
- App-level properties (name, status, language, …)
- Table creation, renaming, deletion, sort order
- Field creation, renaming, deletion, property editing
- GeoFace map layer configuration
- Relation (cross-table link) management
- Schema validation and auto-fix

### `user` — User management (admin)
`GET/POST/DELETE /api/user` · `GET/POST/DELETE /api/user/{id}/privileges`

Admin CRUD for `bdus_users`. Also manages per-table privilege overrides
(`bdus_user_table_privileges`).

### `new_app` — Application wizard
`GET /api/new-app/status` · `POST /api/new-app`

Creates a brand-new BraDypUS application (DB + system tables + config).
`getStatus()` returns whether a fresh install is needed (no apps exist yet).
Delegates to `DB\System\CreateApp`.

### `free_sql` — Raw SQL (super-admin)
`POST /api/free-sql/verify` · `POST /api/free-sql/run`

Lets a super-admin run arbitrary SQL after re-confirming their password.
The verify step re-checks credentials; the run step executes the query.

---

## Visualisation & analysis

### `chart` — Charts
`GET/POST /api/charts` · `POST /api/chart/data` · share / unshare / delete

Manages user-defined charts stored in `bdus_charts`.
`getData()` executes the chart query and returns the result set for the
frontend chart library.

### `geoface` — Geospatial / GeoFace
`GET /api/geoface` · `POST/PUT/DELETE /api/geoface/feature`

Reads and writes geospatial features (GeoJSON) linked to records.
`getGeoJson()` returns a FeatureCollection for the current table + filters.

---

## Presentation

### `frontpage_editor` — Welcome page
`GET /api/welcome` · `PUT /api/welcome`

Reads and writes the HTML welcome page shown on the dashboard
(stored in `bdus_app_settings`).

### `templates` — Print templates
`GET/POST/DELETE /api/template/{tb}/{name}` · list endpoints

CRUD for Twig-based print templates stored in `bdus_cfg_templates`.
Templates control how a record looks when printed / exported to PDF.

### `widget` — Embeddable widgets
`GET /api/widgets` · `GET /api/widget/{name}`

Lists and serves public, read-only data widgets.
See [Widget API](./widget-api) for the full documentation.

---

## System utilities

### `api` — API key management
`GET/POST /api/api-keys` · revoke / delete

Admin CRUD for `bdus_api_keys`. Keys are stored as SHA-256 hashes;
the plain-text key is shown only once at creation.

### `backup` — Database backups
`GET/POST /api/backups` · download / restore / delete

Admin operations on DB dump files in `projects/{app}/backups/`.

### `debug` — Logs
`GET /api/logs` · `POST /api/logs/purge`

Admin access to `bdus_logs` entries.

### `info` — Version & app info
`GET /api/info` · `GET /api/info/app`

`getInfo()` returns the BraDypUS version (public, no auth).
`getAppInfo()` returns extended app info (authenticated).

### `file` — File sort
`POST /api/files/sort`

Updates the display order of files attached to a record.
File upload and deletion are handled by `record_ctrl`.

---

## Module file structure

A typical module:

```
modules/
└── vocabularies/
    └── vocabularies.php   ← class vocabularies_ctrl extends Controller
```

All methods of a controller live in one file — no sub-files, no separate
service classes (those belong in `lib/`).

---

## Adding a module

See [Adding a new endpoint](./architecture#adding-a-new-endpoint).
