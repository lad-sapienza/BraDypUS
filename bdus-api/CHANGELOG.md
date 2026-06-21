# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Plugin osteologico (inventario ossa)** — nuovo plugin di sistema attivabile per tabella tramite **Config → Tabelle → Inventario osteologico**. Aggiunge una colonna `osteo_data TEXT` (JSON) al record e un pannello interattivo in RecordView:
  - **51 elementi anatomici** in 9 regioni corporee (testa, colonna, torace, spalla, arto superiore, pelvi, arto inferiore, piede, denti).
  - **Multi-individuo** — tab per individuo con label e note; il pulsante "Aggiungi individuo" aggiunge un nuovo scheletro vuoto.
  - **SVG interattivo** — visualizzatore skeleton zoomabile (rotella mouse o pulsanti +/−) e pannable (click+drag). Tooltip on hover. Legenda colori in fondo.
  - **BonePanel laterale** — in edit mode, click su un osso apre un pannello con quattro attributi: presenza (sì/no/non documentato), conservazione (completo / >50% / <50% / frammentario / tracce), certezza anatomica (certa/probabile/incerta), certezza lateralità (solo ossa pari).
  - **Disattivazione non distruttiva** — il toggle off rimuove il pannello ma preserva la colonna `osteo_data` e i dati già inseriti.
  - **Demo seed** — tabella `sepolture` nel seed demo con 3 sepolture di esempio (SEP001–SEP003, mono- e bi-individuale).
  - **Test** — 7 test PHPUnit in `ConfigCtrlTest` + fase hurl `38_osteology.hurl`.
  - File: `bonesConfig.js`, `BonePanel.vue`, `OsteologySvg.vue`, `OsteologySection.vue`; endpoint `POST/DELETE /api/config/table/{tb}/osteology`.

- **Tipo di campo `md` (Markdown)** — nuovo tipo di campo `"md"` che memorizza testo Markdown e lo rende in HTML nel modo visualizzazione. In modalità modifica compare una textarea con un pulsante toggle "Anteprima" / "Modifica" per vedere il rendering in tempo reale. La libreria `marked` (già presente come dipendenza) è usata per il parsing.
  - `controllers/fld_structure.json` — aggiunto `"md"` alla lista dei tipi disponibili, tra `"long_text"` e `"select"`.
  - `FieldDisplay.vue` — ramo `v-else-if="schema.type === 'md'"` con `v-html="marked.parse()"` e stili prosa (`.field-md`).
  - `FieldEditor.vue` — ramo `v-else-if="schema.type === 'md'"` con Textarea + toggle preview (`.md-editor-wrap`, `.md-preview`); ref locale `mdPreview` indipendente per ogni istanza del campo.
  - `it.json` + `en.json` — nuova chiave `preview`.

- **Sezioni accordion nel template system** — nuovo tipo di sezione `"type": "accordion"` nel JSON dei template. In questo tipo `content` è un array di pannelli `{ label, open, fields[] }` invece dei soliti `{ field, width }`. Ogni pannello è collassabile indipendentemente; `open: true` (default) lo rende aperto al caricamento.
  - `lib/Template/Loader.php::validate()` — aggiunto ramo `$isAccordion`: valida i campi dentro `panel.fields` con gli stessi controlli (unknown_field, invalid_width) usati per le sezioni core; salta il check di `width` sull'array `content` (che ora contiene pannelli, non field items).
  - `TemplateSection.vue` — ramo `v-if="isAccordion"` che rende `accordion-panels` con stato `openPanels[]` inizializzato da `panel.open`; click sull'header togola il pannello.
  - `TemplatesView.vue` — sostituisce il plugin Select con un selector "Tipo sezione" (core / plugin / accordion); quando il tipo è accordion mostra un editor per pannelli annidati (label, open-by-default, lista campi). `onSectionTypeChange()` resetta `content` e imposta/rimuove `type` e `plugin` in modo consistente. `saveTemplate()` pulisce `type` se diverso da `"accordion"`.
  - `it.json` + `en.json` — 4 nuove chiavi: `add_panel`, `open_by_default`, `panel_label`, `section_type`.

- **Scorciatoia da tastiera CMD+S / CTRL+S (`RecordView.vue`)** — un listener `keydown` su `window` (montato/smontato con il componente) intercetta `metaKey+s` (macOS) e `ctrlKey+s` (Windows/Linux) solo quando `mode === 'edit'`; chiama `saveRecord(keepEditMode = true)`, che salta il passaggio a `mode = 'read'` dopo il salvataggio e ri-popola `editData` tramite `enterEditMode()` dopo il ricaricamento del record. Per i record nuovi, un flag `pendingEditMode` fa sì che `fetchRecord()` ri-entri in modalità modifica dopo la navigazione verso il nuovo URL.

- **DBML import / export** — il pannello Config espone una nuova sezione DBML che permette di esportare l'intera configurazione dell'app (tabelle, campi, vocabolari) in un singolo file `.dbml` annotato e di importare nuove tabelle da un file DBML:
  - **Export** (`GET /api/config/dbml`) — serializza cfg + `bdus_vocabularies` in DBML valido per dbdiagram.io; le tabelle di sistema (`bdus_*`) sono escluse automaticamente; i valori degli Enum sono sempre quotati per compatibilità con spazi e trattini.
  - **Preview** (`POST /api/config/dbml/preview`) — valida il DBML senza scrivere nulla: errori bloccanti (`table_already_exists`, `pk_must_be_id`) e avvisi non bloccanti (`auto_add_id`, `auto_add_creator`).
  - **Apply** (`POST /api/config/dbml/apply`) — crea le tabelle nel DB e scrive la configurazione; tabelle con errori vengono saltate; i vocabolari dagli Enum marcati `// bdus:vocabulary` vengono inseriti in `bdus_vocabularies`.
  - Tre nuove classi: `Bdus\DbmlParser` (parser custom, no dipendenze esterne), `Bdus\DbmlImporter`, `Bdus\DbmlExporter`.
  - 41 nuovi test (15 unit DbmlParser, 12 unit DbmlExporter, 14 integration DbmlImporter) + hurl phase 36.

- **Traversata dei campi lookup (`id_from_tb`) in `JsonFilter`** — i campi configurati con `id_from_tb` memorizzano l'id del record referenziato; un oggetto annidato sul campo viene ora risolto con una subquery sulla tabella referenziata:
  - `filter[parent_id][sigla][_eq]=T001` → `crud_test.parent_id IN (SELECT id FROM crud_test WHERE sigla = ?)`
  - Funziona anche dentro le subquery plugin/backlink: `filter[tags][cat_ref][name][_eq]=Ceramics`
  - Le condizioni annidate sono compilate da un `JsonFilter` ricorsivo sulla tabella referenziata: tutti gli operatori, i gruppi logici `_and`/`_or`, la validazione dei campi e ulteriori hop lookup funzionano in modo trasparente.
  - Le condizioni dirette sul campo (`filter[parent_id][_eq]=3`) restano confronti per id, invariate.
- **Metadati `ref_tb` / `ref_field` in `getAdvancedConfig`** — i campi lookup nella lista campi della ricerca avanzata dichiarano la tabella referenziata e il suo `id_field`; il frontend usa questi metadati per emettere la traversata annidata, allineando la ricerca ai valori suggeriti dall'autocomplete (che provengono dalla tabella referenziata).

- **Analisi Assemblaggio** (`AssemblageAnalysis`, M034) — nuovo controller per la creazione e consultazione di analisi pivot su assemblaggi di materiale; le analisi vengono salvate nel DB e possono essere condivise:
  - **9 endpoint REST**: `GET /api/assemblages` (lista), `POST /api/assemblages` (salva), `GET /api/assemblage/sources` (tabelle sorgente disponibili), `GET /api/assemblage/table-meta` (campi + FK per il path builder), `POST /api/assemblage/data` (esecuzione pivot), `POST /api/assemblage/{id}` (aggiorna), `POST /api/assemblage/{id}/share`, `POST /api/assemblage/{id}/unshare`, `DELETE /api/assemblage/{id}`.
  - **Migrazione M034** (`CreateAssemblageAnalyses`) — crea la tabella `bdus_assemblage_analyses` con wizard-config JSON, flag di condivisione, `created_by` e timestamp.
  - `getSources()` restituisce tabelle normali e plugin con `parent_tb`/`parent_label`; `getTableMeta()` espone campi e relazioni FK per costruire il percorso multi-hop; `getData()` computa la pivot con misura configurabile (count, sum, count_distinct) e filtri JSON.
  - `getData()` restituisce anche `group_labels` (mappa id→etichetta umana via preview field), `group_tb` e `group_field`; la Vue li usa per mostrare sull'asse verticale l'etichetta leggibile invece dell'id numerico e per linkare le schede.

- **Timeline cronologica comparata** (`GET /api/chrono/timeline`, `ChronoTimelineView.vue`) — vista full-page che sovrappone sullo stesso asse temporale tutti i record con dati `fuzzy_date` delle tabelle selezionate; ogni tabella è una riga, ogni record un segmento colorato per certezza; parametri `from`, `to` e `tb[]` per filtro temporale e per tabella; navigazione dalla barra degli strumenti della DataView tramite il pulsante calendario.

- **Distribuzione cronologica derivata** (`GET /api/chrono/related/{tb}/{id}`, `ChronoDensityPanel.vue`) — pannello nel corpo della scheda record che mostra, per ogni tabella relata tramite FK, un istogramma a 60 bin della densità cronologica dei record collegati; evidenzia l'intervallo di picco con etichette `from`/`to`; ogni barra è un link filtrato alla lista dei record.

- **Endpoint upgrade** (`Upgrade` controller) — tre endpoint per la gestione dell'aggiornamento schema:
  - `GET /api/upgrade/status` — stato corrente (`major` / `minor` / `null`) senza autenticazione JWT.
  - `POST /api/upgrade/major` — esegue le migrazioni major (v4→v5); autenticazione diretta superadmin.
  - `POST /api/upgrade/minor` — esegue le migrazioni minor pendenti; richiede JWT admin.

### Changed

- **Ricerca avanzata (`DataView.vue`)** — le righe su campi lookup generano il filtro annidato `{ campo: { ref_field: { _op: valore } } }`; prima il confronto avveniva direttamente sulla colonna (che contiene id), quindi cercare per valore suggerito non trovava mai nulla.
- **Topbar** — aggiunto il link "by LAD" accanto al nome BraDypUS (punta a `https://purl.org/lad`); il burger menu è nascosto sugli schermi ≥ 1024px, dove la sidebar è sempre visibile e il pulsante si limitava a oscurare lo schermo con l'overlay.

### Fixed

- **`Config::setMain()` eliminava `bdus_version` da `config.json`** — il metodo usava `array_intersect_key($main, ...)` con i dati del form (che non contengono `bdus_version`) invece di `$this->cfg['main']` (config caricata + merge con i dati del form). Ogni salvataggio di proprietà app riscriveva un `config.json` incompleto; alla richiesta successiva `isMajorUpgradeNeeded()` trovava la chiave assente e restituiva `true`, bloccando tutte le route autenticate con 503. Fix: aggiunta `bdus_version` a `BOOTSTRAP_KEYS` e corretto l'intersect a usare `$this->cfg['main']`.

- **`Dispatcher` rispondeva HTTP 503 per `major_upgrade_required`** — 503 ("Service Unavailable") segnala ai tool di monitoring che il server è irraggiungibile, mentre il server è perfettamente operativo; è il client che deve eseguire un upgrade. Sostituito con **HTTP 409 Conflict** (conflitto tra lo stato corrente dell'app e la richiesta). Il body `{"status":"error","code":"major_upgrade_required"}` è invariato; il client legge il body e reindirizza al flusso di upgrade.

- **Grafici salvati di altre tabelle** (`ChartPanel.vue`) — eseguire un grafico salvato su una tabella diversa da quella corrente falliva con `invalid_field`: la definizione veniva ricostruita dal builder con la tabella corrente. Ora i grafici di altre tabelle vengono eseguiti con la definizione salvata, contro la loro tabella; "Salva come" persiste la definizione realmente eseguita.

- **Badge "aggiornamento disponibile" persistente in pagina login** — `listApps()` confronta `bdus_version` in `config.json` con la versione da `composer.json`; `writeProjectVersion()` veniva chiamata solo tramite `Migrate::run()`, cioè solo nel flusso di upgrade. Un login su un'app già aggiornata non scriveva mai la versione corrente → la chiave risultava assente o obsoleta → il badge compariva sempre. Fix: `Login::auth()` chiama `Migrate::run()` anche quando non ci sono migrazioni pendenti (non-fatal try/catch); la chiamata è idempotente e costa solo 2 query + 1 file write per login.

- **Etichette gruppi nell'Analisi Assemblaggio sempre vuote (`—`)** (`AssemblageAnalysis::getData()`) — la JOIN per il label lookup usava `ON fkCol = _lbl.{id_field}` dove `id_field` è il campo semantico configurato (es. `'us'`), non la chiave numerica `id`; il confronto tra un intero FK e una stringa non trovava mai corrispondenza. Fix: il JOIN usa sempre `ON ... = _lbl.id` (chiave autoincrement).

- **Dialogo di conferma eliminazione in `AssemblagesView` mostrava la chiave i18n letterale** — la chiave `delete_confirm_message` mancava in entrambi i file locale (`it.json`, `en.json`). Aggiunta la traduzione italiana e inglese corrispondente.

- **Tooltip della Timeline cronologica completamente trasparente** (`ChronoTimelineView.vue`) — le variabili CSS PrimeVue `--p-surface-card` e `--p-surface-overlay` non sono definite su `:root` ma solo all'interno del sotto-albero del tema; gli elementi teletrasportati in `<body>` non le ereditano, risultando in `background: transparent`. Fix: sostituito con `rgba(255,255,255,0.92)` per il tema chiaro e `rgba(30,41,59,0.92)` per il tema scuro (classe `.dark-mode` su `<html>`).

### Removed

- **Connettore XOR** — rimosso da `getAdvancedConfig`: non era supportato da `JsonFilter` (il frontend lo trattava silenziosamente come AND) e non risulta usato in pratica.
- **Pulsanti parentesi nella ricerca avanzata** — erano UI senza effetto: `buildFilterFromRows` non li ha mai considerati (il raggruppamento segue la precedenza standard AND-su-OR).

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
