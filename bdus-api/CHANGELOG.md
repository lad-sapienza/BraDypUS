# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.0.2] - 2026-06-06

### Added

- **Filtro cross-table a 1 hop con FK esplicita (backlink)** — `JsonFilter` supporta ora due modalità di join cross-table:
  1. **Plugin** (`table_link` / `id_link`): comportamento invariato.
  2. **Backlink** (colonna FK esplicita): quando la tabella richiesta nel filtro non è un plugin della tabella principale ma compare nella sua configurazione `backlinks` nel formato `"refTb:viaTb:fkCol"`, viene generata la subquery corretta: `main.id IN (SELECT fkCol FROM viaTb WHERE …)`.
  - Esempio PAThs (Caso 1): `filter[m_msplaces][type][_eq]=discovery` → `places.id IN (SELECT place FROM m_msplaces WHERE type = ?)`

- **Filtro cross-table a 2 hop (backlink → plugin_of parent)** — all'interno di una condizione su una tabella via (plugin o backlink), se compare un'ulteriore chiave non-campo che corrisponde al `plugin_of` della tabella via, viene generata una subquery annidata:
  ```
  main.id IN (SELECT fkCol FROM viaTb WHERE table_link = ? AND id_link IN (SELECT id FROM parentTb WHERE …))
  ```
  - Esempio PAThs (Caso 2): `filter[m_msplaces][manuscripts][palimpsest][_eq]=1` → `places.id IN (SELECT place FROM m_msplaces WHERE table_link = 'manuscripts' AND id_link IN (SELECT id FROM manuscripts WHERE palimpsest = ?))`.
  - Limitazione: massimo 2 hop; catene di 3 o più livelli non sono supportate.

## [5.0.1] - 2026-06-06

### Fixed

- **`tb_stripped` rimosso dalla risposta API** — il campo ridondante è stato eliminato da `metadata`, dagli item di `manualLinks` e dalla risposta di `POST /api/manual-link`. Le variabili PHP associate rimosse di conseguenza.
- **`links` / `backlinks` / `manualLinks` serializzati come `{}` quando vuoti** — in PHP un array associativo vuoto veniva codificato come `[]`; il cast a oggetto ora avviene nel controller, prima di `returnJson`, senza interferire con `Edit.php` che usa queste strutture come array internamente.
- **`id_field` nullo in `getManualLinks()`** — quando la tabella linkata non ha `id_field` configurato, la query generava `SELECT  as label …` causando un errore SQL; aggiunto fallback a `'id'`.
- **OpenAPI `RecordResponse` riallineato alla risposta reale** — nomi corretti (`tb_id`, `rec_id`, `id_field`, `can_add`), tutti i campi top-level documentati (`backlinks`, `manualLinks`, `geodata`, `rs`, `bibliography`, `schema`), tipo di `links` corretto da `array` a `object`, `$ref: RecordFile` sostituito con il corretto `LinkedFileItem`.
- **`nullable: true` → sintassi OpenAPI 3.1** — 34 occorrenze di `type: X` + `nullable: true` convertite in `type: [X, 'null']`.

## [5.0.0] - 2026-06-06

Complete rewrite of the frontend, from jQuery + Bootstrap 3 + server-side Twig
to a **Vue 3 SPA** (Vite, PrimeVue Aura theme). The PHP backend is preserved and
extended with a clean REST API consumed by the new frontend.

---

### New features

#### Interface

- **New design system** — PrimeVue Aura theme with full dark-mode support and
  CSS custom-property tokens. Responsive layout with a collapsible sidebar
  (desktop) and a slide-in drawer (mobile).
- **Locale switcher** — toggle between 🇮🇹 Italian and 🇬🇧 English at any time;
  the entire UI is internationalised.
- **Per-app primary colour** — administrators can choose from 8 colour presets
  (Indigo, Blue, Violet, Emerald, Teal, Amber, Rose, Slate) in Config → App settings.
  The change takes effect immediately for all users.
- **App name in topbar** — the current application name is displayed next to
  "BraDypUS", making it easy to identify tabs when several apps are open simultaneously.

#### Authentication & security

- **Stateless JWT auth** — PHP sessions are gone. Each browser tab holds its own
  signed token (`sessionStorage`), so multiple applications can be open at the same
  time without interference. Tokens refresh silently when less than 30 minutes remain.
- **OAuth2 / SSO** — users can sign in with Google or ORCID without a local password.
  Providers are configured per-application; unconfigured providers are hidden.
- **API keys** — external integrations authenticate with per-application API keys
  carrying an explicit privilege level (read / edit / admin).
- **Per-table privilege overrides** — admins can grant per-table read/write/admin
  rights to individual users, with an optional SQL WHERE clause for row-level filtering.

#### Data management

- **DataView** — paginated record list with:
  - Fast search, advanced search, and SQL expert mode.
  - Active filter persisted in the URL — the Back button restores the exact search state.
  - Sortable, togglable columns with persistent column order per table.
  - Streaming export (CSV, XLSX, JSON) from any active search.
  - One-click Harris Matrix button for tables with stratigraphic relations.
- **RecordView** — unified view and edit mode:
  - Two-column sticky layout: main fields on the left; links, geodata, bibliography,
    chronology, and RS in a persistent right sidebar.
  - All field types: text, long text, date, boolean, select, combo_select,
    multi_select, slider, link_to, link_out.
  - Plugin tables with inline add / edit / delete rows.
  - Unsaved-changes guard on navigation — only fires when data was actually modified.
  - File gallery with drag & drop upload, sort, and delete.
  - Duplicate record — one click copies all core fields; `creator` is set to the
    current user.
  - Version history with per-field diff and one-click restore.
- **Typed manual links** — when linking two records, an optional free-text relation
  label can be attached (e.g. *cites*, *is part of*). Labels appear as chips in the
  Linked records section.
- **Manual links graph** — toggle between list and an interactive force-directed graph
  of all linked records; clicking a node navigates to that record.

#### Files

- **File management view** — dedicated page (sidebar: *File management*) listing all
  uploaded files in the application. For each file: thumbnail / icon preview,
  filename, inline-editable description and keywords, linked-record badges, orphan
  indicator. Filters: *Orphans only* (files not attached to any record).
  Per-file actions: replace the binary while keeping metadata, or delete.
- **Image auto-resize** — if `maxImageSize` is set in App settings, raster images are
  automatically downscaled on upload. Vector formats and documents are unaffected.

#### Fuzzy-date / Chronology plugin

- Activate per table via Config → Table settings → Chronology toggle.
  Five fields are created in the database (`chrono_from`, `chrono_to`,
  `chrono_label`, `chrono_certainty`, `chrono_period`) and a dedicated
  **ChronoSection** appears in the record's right sidebar.
- Input: free-form chronological range (e.g. `c1 BCE/c4 CE`, `-600/1800`).
  The parser resolves BCE/CE qualifiers, century notation, and fuzzy markers.
- Certainty levels: Certain / Probable / Uncertain — stored as an integer,
  displayed with colour coding.
- Deactivating the plugin removes the section from the UI; the database
  columns are preserved (data protection).

#### Stratigraphic relations (Harris Matrix)

- **Redesigned** — relations now store integer foreign keys (record primary keys)
  instead of free-text identifiers, enabling real referential integrity.
  Existing data is converted automatically at first login after upgrade.
- **Add relation** — AutoComplete search replaces the manual text input; users
  pick a record from the same search used for geodata and manual links.
- **Harris Matrix view** — full-page interactive Cytoscape.js / dagre graph at
  `/:app/matrix/:tb`. Stratigraphic cycles are highlighted in red; edge labels
  use the correct relation name. Available from the DataView toolbar.
- **Inline RS panel** in RecordView — shows direct relations with human-readable
  labels; navigates to any related record.

#### Configuration

- **Bookmarkable URLs** — every config panel has its own URL
  (`/:app/config/app`, `/table/:tb`, `/fields/:tb`, etc.); browser back/forward
  and deep links work.
- **Field form improvements**:
  - All parameter labels translated (no more raw JSON key names).
  - Boolean parameters (`readonly`, `hide`) rendered as toggles.
  - `vocabulary_set`, `get_values_from_tb`, and `id_from_tb` are hidden unless
    the field type is `select`, `combo_select`, or `multi_select`.
  - `get_values_from_tb` replaced by a two-level cascading UI: pick a table,
    then pick a field — no manual string formatting.
- **Table form improvements**:
  - Help text below every parameter.
  - `is_plugin` rendered as a toggle.
  - Preview fields replaced by a MultiSelect with chip display.
  - Plugin tables shown as a labelled list of toggles, matching the system
    plugins layout.
- **FK constraints and user indexes** — define foreign key constraints between
  tables and custom indexes directly from the Relations and Table panels.
  Orphan-check runs before applying a constraint; the relation is saved even
  if the constraint cannot be applied to the DB.
- **Upgrade assistant**:
  - *v4 → v5*: detected automatically on login; a dedicated screen guides the
    superadmin through the one-time migration without touching the normal auth flow.
  - *Minor upgrades (v5.x → v5.y)*: pending database migrations are shown to
    the admin in a post-login confirmation screen before entering the application.

#### Vocabularies

- **Filter** — type to narrow the vocabulary list on the left.
- **Field usage** — selecting a vocabulary shows which table/field combinations
  reference it, so unused vocabularies are easy to identify.

#### Other

- **Design Templates** — visual JSON editor for record-view layouts: create,
  edit, rename, and delete per-table templates with section cards and field rows.
- **Per-app widget system** — drop a native ES module in
  `projects/{app}/widgets/{name}.js` and attach it to a field via the `widget`
  config property; the widget mounts inside RecordView and receives the live
  field value.

---

### Changed (breaking)

- **Frontend technology stack**: jQuery + Bootstrap 3 + Twig replaced by Vue 3
  SPA. Custom jQuery plugins and any code that relied on server-rendered HTML are
  no longer present. All data is exchanged as JSON.
- **Authentication**: PHP sessions replaced by JWT Bearer tokens. Cookies are no
  longer used. Existing passwords are transparently upgraded from SHA-1 to bcrypt
  on first login.
- **Search API**: the `search_type=advanced` / ShortSQL DSL is retired.
  All structured search now uses the Directus-style `filter` format.
  Saved queries using the old format must be recreated.
- **Stratigraphic relations config**: `tables.{tb}.rs` changed from a field-name
  string (e.g. `"sigla"`) to a boolean flag (`1` / `true`). Updated automatically
  by migration M030 on first login after upgrade.
- **Table prefix system** (`APP__`): the application-name prefix on table names is
  removed. SQLite apps are migrated automatically at first login; no manual action
  required.
- **`creator` column** on user data tables: now nullable with a foreign key to
  `bdus_users (id) ON DELETE SET NULL`. Records whose creator was deleted are
  not affected; the `creator` field is simply set to `NULL`.
- **Field parameters removed**: `disabled` (absorbed by `readonly`),
  `force_default`, and `active_link` are no longer read from configs or offered
  in the UI. Existing YAML configs using `disabled: true` are silently treated as
  `readonly: true`; no migration required.
- **Plugin table naming**: the `m_` prefix convention is no longer required or
  enforced. Existing tables with the prefix continue to work unchanged.
- **Obsolete config fields removed**: `gmapskey`, `googleanaytics`,
  `virtual_keyboard`, `api_login_as_user`, `auth_login_as_user` are stripped from
  stored configs by the migration runner.

---

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
- Fixed issue with vocabularies names not being pushed to popup when a new vocabulary item was created (issue #11).

## [4.3.0] - 2022-11-24

### Removed
- DARE basemap for GeoFace was removed as a default option. It can be added as a custom Web Tile Service.

### Added
- GeoFace can be configured to use custom WMS, WTS and locally stored csv, gpx, kml, wkt, topojson, and geojson files

## [4.2.5] - 2022-08-04

### Fixed
- Fixed bug menuValues not working with PostgreSQL

## [4.2.4] - 2022-02-18

### Fixed
- Fixed bug with chart edit module referring sql instead of sqltext

## [4.2.3] - 2022-02-18

### Changed
- Updated twig/twig from v3.3.2 to v3.3.8
- Updated intervention/image from v2.6.1 to v2.7.1
- Updated michelf/php-markdown from v1.9.0 to v1.9.1
- Updated monolog/monolog from v2.3.2 to v2.3.5

## [4.2.2] - 2022-02-18

### Fixed
- Updated README.md
