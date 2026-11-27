---
title: Files as a first-class table (plan)
---

# Files as a first-class table: plan <Badge type="warning" text="branch feat/files-as-table" />

Decided 2026-11-27. Goal: close the file-access hole **permanently** and give files back what
bdus4 had (permissions like any table, custom fields), then merge the branch as the lasting fix.
Nothing here is implemented yet: this page is the plan, to be approved before the first commit.

::: danger This is a deep change: maximum care
It rewrites how files are stored, named, served, authorised, listed, edited and deleted, and it
changes the URLs every client uses. It touches the schema, the auth transport, the record layer,
the frontend and the deploy configuration at once. Therefore, **and without exception**:

- **Every existing test that touches files must be reviewed and fixed**, not skipped, deleted or
  loosened to go green (inventory in §6). A test that has to change is changed for a stated reason.
- **Every documentation layer must be updated** (guide, dev, API, backup/deploy, OpenAPI,
  assistant entry points, locale strings; inventory in §7). The branch is not mergeable with a
  stale page.
- Every new guard gets a test that **fails when the guard is removed** (mutation check).
- All three DB engines, a migration run on real-shaped data with files on disk, a backup before
  every production migration, and the real apps (PAThs, CAJ, demo) rehearsed first.
- Definition of done is §9a, a checklist: nothing is "done" until each line is ticked and verified
  by actually reading the test output.
:::

## 0. Decisions already taken

| # | Decision |
|---|---|
| 1 | Separate branch `feat/files-as-table` (from `v5`), developed in its own worktree. Big change, one coherent merge. |
| 2 | **Stop-gap ("phase 0") is NOT in this branch.** It is handled in another session; see §10 for what overlaps. |
| 3 | `bdus_files.id` stays an integer; a new `uuid` column (random v4) becomes the public identifier and the on-disk name. |
| 4 | The browser loads `<img>` through a **cookie**. API keys / MCP keep the `Authorization: Bearer` header. |
| 5 | Permissions: **both** explicit rules on `bdus_files` **and** inheritance from linked records. |
| 6 | Inherited **write** needs **all** linked records to be changeable (as `deleteFile` does today). |
| 7 | File ↔ record links stay **N-N** in `bdus_file_links` (same model as bdus4, whose `bdus_userlinks` was N-N too). |
| 8 | New file field `public`; each image preset gets an access level (`public` / `inherit`) and an `enabled` switch. A preset can be disabled, never deleted. Public presets are visible in the admin UI (Config → App). |
| 9 | Direct static access to `projects/{app}/files/` is blocked as the **last step** of this branch (§9, step 9) and needs an explicit go, because it is the breaking one. |
| 10 | **Policy:** anything that may leave the system anonymously is a **preset**; an original is never anonymous. |
| 11 | Rules on **all tables** (the `*` rules, and the global tiers) **include** `bdus_files`. |
| 12 | `thumb` stays the built-in default preset, always listed in the admin UI (so the cache is visible); table and card views use it. |
| 13 | An orphan file is reachable only through **direct** access (an explicit rule); access that comes only from linked data never covers orphans, and the creator gets no special right. |
| 14 | Deleting a record **asks, for each file linked only to it, whether to delete the file too** (fewer orphans). |
| 15 | Cross-origin cookies (`SameSite=None`) are **supported** (configurable, HTTPS only). |
| 16 | Release type: **minor** (with prominent breaking notes). |
| 17 | The Files page gets a **table view** (like any table, with previews) and a **cards view**. |

## 1. Where we are (verified in the code, 2026-11-27)

- **Binaries are public.** Files live at `projects/{app}/files/{id}.{ext}`; `.htaccess` sends to
  PHP only paths that do not exist as files, so the web server serves them directly. Ids are
  sequential. No permission can apply.
- **Writes check "edit on some table".** `File::updateFile`, `replaceFile`, `sortFiles` only call
  `canOnSomeTable('edit')`; `Record::deleteFile` checks the links but an orphan file passes
  (empty loop). `File::getFiles` already filters by readable linked records.
- **No custom fields.** `bdus_files` is seeded in `bdus_cfg_tables/fields` (`CreateApp`), but Config,
  Home, Templates skip it; its columns are hard-coded in `File.php` (`SELECT`/`UPDATE`), the
  `Expand` whitelist, and the INSERTs of `Record::uploadFile` and `Import`.
- **Auth is header-only.** JWT / API key travel in `Authorization`; nothing sets or reads a cookie.
- **Presets** (`Image\Presets`, stored in `bdus_cfg_app.image_presets`): `thumb` is always present,
  can be redefined, not removed; derived images are served by `File::serveDerived` with
  privilege `none` (public).

## 2. Target model

### 2.1 `bdus_files`

| Column | Notes |
|---|---|
| `id` | integer PK, unchanged (internal: relations, versions, rules, links) |
| `uuid` | **new**, unique, `CHAR(36)`/`TEXT`, random v4; name on disk and in every URL |
| `creator`, `ext` | readonly, as today |
| `filename`, `keywords`, `description`, `printable`, `attribution` | editable, as today |
| `public` | **new**, boolean, default `0`: the file may be shown through a *public* preset (§2.4) |
| *custom fields* | added from Config like in any table (§5) |

System fields (`id`, `uuid`, `creator`, `ext`, `filename`, `public`…) cannot be deleted or have their
type changed in the config UI; custom ones can.

### 2.2 Who may do what

`Access` already speaks `table = 'bdus_files'` (rules, filters, enforce, locks); we add one class,
`Auth\FileAccess`, that combines it with the links. Let `explicit(a)` = a rule on `bdus_files`
grants action `a` on that row, and `links` = the file's rows in `bdus_file_links`.

| Action | Allowed when |
|---|---|
| **read** (list, metadata, binary, derived with `inherit`) | `explicit(read)` **or** at least one linked record is readable |
| **update** metadata / **replace** binary | `explicit(update)` **or** (`links` not empty **and every** linked record is changeable) |
| **delete** (purge) | `explicit(delete)` **or** (`links` not empty **and every** linked record is changeable) |
| **link / unlink** | the **record's** update right (unchanged) |
| **upload from a record** | the record's update right; creates file + link in one transaction |
| **standalone upload** (Files page) | `explicit(create)` |
| **orphan file** | explicit rules and admin only; no inheritance (nothing to inherit from), no creator exception |

Rules on **all tables** (`Access::ALL_TABLES = '*'`) and the global tiers cover `bdus_files` like
any other table. Effect on existing apps: a group with a `*` update/delete rule can now also act
on **orphan** files directly (on linked files it already could by inheritance, since `*` covers
every linked table). The migration report lists groups and keys this newly reaches, so the admin
sees it before upgrading.

Admins bypass; locks on `bdus_files` apply as for any table; `frozen`/`off` app status applies.
The single source of truth is `FileAccess::readCondition()` (SQL fragment used by the list, the
serve endpoint, search) and `FileAccess::may($action, $fileId)` for single files. **No other code
decides file permissions.**

### 2.3 Serving

- Original: `GET /api/file/{uuid}` (streams from `files/{uuid}.{ext}`; `Content-Disposition`,
  ETag, `Cache-Control: private`). Auth: cookie **or** Bearer.
- Derived: `GET /api/file/{preset}/{uuid}.{ext}` (replaces the `{id}` form). Rules in §2.4.
- 404 (not 403) for files the principal cannot read, to avoid confirming existence.
- Replacing a binary keeps the `uuid`; derived versions are purged as today.

### 2.4 Preset access and `public`

Each preset gains `access` (`public` | `inherit`) and `enabled` (bool, default true).

| Preset | File | Served to |
|---|---|---|
| disabled | any | nobody (404); never generated; cache kept but not served |
| `public` | `public = 1` | **anyone**, no login |
| `public` | `public = 0` | follows the original (login + read right) |
| `inherit` | any | follows the original |

The original is **never** anonymous (decision 10). `thumb` is the built-in default preset
(`access = inherit`): always listed, redefinable, and **not disableable** because the table and
card views depend on it (assumption, to confirm: say so if you want it disableable with an icon
fallback). The admin page (Config → App → Image presets) lists **every** preset, built-in
included, with its access, its state and a switch to enable/disable it; `public` presets carry a
visible warning tag ("publishes derived images of files marked public"). Disabling is for admins
only and does not delete the definition or the cache.

### 2.4a Deleting a record: what happens to its files

Today `Persist` silently purges every file linked only to the deleted record. New behaviour:

1. Before deleting, a pre-check (same pattern as `checkPluginsBeforeDelete`) returns the files
   **linked only to the records being deleted**, with a preview, and whether the principal may
   delete each one (it may, by §2.2, because all their links are being removed with the record).
2. The UI asks **per file**: *delete the file too* / *keep it (it becomes an orphan)*, with
   "apply to all" for batches.
3. `erase` takes the decision as a parameter (`files: { "<id>": "delete" | "keep" }`); files linked
   to other records are never deleted, only unlinked. Without the parameter the API keeps the
   files (the safe default) and the response lists the orphans created (open point 3).
4. Same for bulk deletion, MCP `records_delete` and the REST API; each documents the parameter.

### 2.5 Cookie

- On login the API also sets `bdus_session` (HttpOnly, `Secure` when HTTPS, `SameSite=Lax`, the
  JWT or an equivalent opaque token, same expiry, subject to `token_version` revocation, M028).
  Logout / revoke clears it.
- The cookie authenticates **only `GET`/`HEAD` on the file-serving routes**; every other route
  keeps requiring the header. This removes the CSRF surface of cookie auth by construction.
- The frontend simply uses `<img src="/{app}/api/file/…">` (dev proxy: the Vite `/projects/` rule
  is replaced by `/api/`).
- Cross-origin deployments (front-end and API on different sites) are **supported**: a setting
  switches the cookie to `SameSite=None; Secure` (HTTPS only; refused on plain HTTP), and CORS
  answers with `Access-Control-Allow-Credentials: true` for an explicit **allow-list of origins**
  (never `*`). The existing CORS handling is inspected first and the two are made consistent.
  Documented with an nginx/Caddy example and tested.

## 3. Data migration (M060+, "next free number at that date")

Runs through the normal minor upgrade (`Upgrader`, no admin login), **idempotent and resumable**:

1. Add `bdus_files.uuid` (nullable), `public` (default 0).
2. Backfill `uuid` for every row; add the unique index.
3. Rename `files/{id}.{ext}` → `files/{uuid}.{ext}` one file at a time; a missing file is logged
   and skipped (never aborts); a journal (table or `files/.migration.json`) allows a re-run to
   continue and a rollback tool to reverse it.
4. Purge the whole derived cache (keyed by id today).
5. **Preserve today's behaviour for public sites:** existing presets get `access = public` and
   existing files `public = 1`. New uploads default to `public = 0`. The release notes tell admins
   to review this (open point 2).
6. Extend `DB\Verify\MigrationVerifier`: every row has a `uuid`, every file exists under its uuid,
   no old-style `{id}.{ext}` left. Add a **report-only** scan for old URLs
   (`projects/…/files/{id}.{ext}`) inside text/markdown fields, templates and the welcome text.

Backup/restore and `Snapshot` must carry the new column and the renamed files; the migration
needs a backup first (as for any production app).

## 4. Backend work (by area)

| Area | Change |
|---|---|
| `Auth\FileAccess` (new) | §2.2; unit-tested as a matrix |
| `controllers/File.php` | `getFiles`, `updateFile`, `replaceFile`, `sortFiles`, `serveDerived`, new `download` all go through `FileAccess`; hard-coded column lists replaced by the configured fields |
| `controllers/Record.php` | `uploadFile`, `deleteFile`, `linkFile`, `unlinkFile`: generate `uuid`, use `FileAccess`; `deleteFile` fixes the orphan case |
| `lib/Record/*` | `bdus_files` goes through `Read`/`Save`/`Persist` like any table (versions included); `Expand` whitelist derived from config; `Persist` delete cascade uses `uuid` |
| `Import.php` | creates files through the same code as the upload |
| `lib/Auth`, `Router.php`, login/logout | cookie issue/clear/accept (GET/HEAD on file routes only); `ROUTE_PRIVILEGE` rows for the new routes; deny-by-default test updated |
| `Image\Presets`, `Derivative`, `AppSettings`, `Config.php` | `access`, `enabled`, validation, cache path by `uuid`; admin endpoint to toggle |
| `Config` / `Home` / `Templates` | stop skipping `bdus_files`; protect its system fields |
| MCP, `Mcp/Server`, `DbmlExporter/Importer`, Backup, Export | list/read files with the new columns and rules; DBML marks system columns |
| `openapi.yaml` | new/changed routes and schemas; `OpenApiParityTest` stays green |

## 5. Frontend work

- **Files page with two views, switchable and remembered per user:**
  - **Table view**, built like any other table, i.e. on the same pieces `DataView.vue` uses
    (`QueryBar` + `FilterBuilder`, `useRecordQuery`, `useRecordList`, `useColumnPrefs`,
    `ResultActions`): live text search, filters on any field including custom ones, sortable and
    reorderable columns, paging, export, selection. Extra **preview column** (the `thumb` preset,
    a type icon for non-images) and a link-count column.
  - **Cards view**: grid of cards with the preview large, filename, a few chosen fields and the
    status badges (public, orphan); same query bar, paging and selection as the table.
  - The preview opens the existing viewer with Previous/Next; bulk actions work in both views.
  - Cards and table must be fast on thousands of files: lazy-loaded thumbnails, server-side
    paging, no per-card requests.
- `FilesView.vue` is refactored, not patched: the file list becomes a variant of the generic table
  view instead of its own bespoke table, so features added to tables later reach files too.
- `FileGallery.vue`, the preview and every `assetUrl('projects/…/files/…')` → the new URLs;
  metadata form built from the **configured** fields (system + custom), reusing the record
  `FieldEditor`; `public` as a toggle.
- Record deletion dialog with the per-file choice (§2.4a), in the single and bulk flows.
- Files appear in the table list / search / command palette like any table (read-only where the
  rules say so); Users → Groups rules UI offers `bdus_files` as a table.
- Config → App → Image presets: access tag, enabled switch, public warning (§2.4).
- Config → Tables → Files: add/remove custom fields (system ones locked).
- Locale (it/en), tests for the stores/components touched.

## 6. Tests (non-negotiable before "done")

- PHPUnit: `FileAccess` matrix (explicit × inherited × orphan × shared links × locks × frozen);
  preset states; uuid generation; cookie accepted **only** on GET/HEAD file routes.
- hurl: one phase for files (read/serve/update/replace/delete/sort per role and per principal:
  user, API key, group-scoped key, anonymous); public preset served anonymously only with
  `public = 1`; disabled preset → 404; the migration with real files on disk.
- **Mutation-check** each guard (remove it, the test must fail). All three engines
  (`test.sh --all-engines`). Read PASSED/FAILED before any commit; the demo seed does not run
  under `--tests`, so run the full `bdus-api/test.sh`.

**Inventory of existing tests to review and fix** (found by `grep`, 2026-11-27; the list is
re-generated at the start of each step and nothing stays unreviewed):

- hurl phases: `04c_file_upload`, `19_seed_demo` (seed files, uuid), `30_file_sort`,
  `34_file_management`, `35_file_link_unlink`, `44_ids_only`, `46_deny_by_default`,
  `49_derived_files`, plus `_phases.sh` for the new phase(s).
- PHPUnit, files directly: `FileCtrlTest`, `RecordCtrlFileUploadTest`, `RecordCtrlFileLinkTest`,
  `DerivedServeTest`, `DerivedPurgeTest`, `ImageDerivativeTest`, `ConfigImagePresetsTest`,
  `ImportCtrlTest`, `BdusTestCase` (fixtures).
- PHPUnit, access: `AccessMetadataTest`, `AccessWriteEnforcementTest`, `AccessWriteSatellitesTest`,
  `LevelGroupParityTest`, `RouterResolveRequestTest` (new routes, cookie).
- PHPUnit, record/expand: `RecordCtrlFieldsExpandTest`, `RecordCtrlGetRecordTest`, `McpLinksTest`.
- PHPUnit, migrations/verify: `M011`, `M015`, `M017M018`, `M033`, `M047` migration tests
  (they create `bdus_files`: the new column must not break them), `MigrationVerifierTest`,
  `ConfigFromDbTest`, `RadiocarbonCtrlTest` (file references).
- `OpenApiParityTest` (Router ↔ spec).
- Frontend: the existing component/store tests that touch `FilesView`, `FileGallery`, `auth`,
  `api/index.js` (`assetUrl`), and new ones for the table/cards views, the delete dialog and the
  presets admin panel.
- New: `FileAccess` matrix, cookie (GET/HEAD only, CORS credentials), uuid, migration with files
  on disk (resume after a crash, missing file, rollback), preset `access`/`enabled`, record
  deletion with per-file choice (single, bulk, MCP, API default).

## 7. Documentation and assistants

Nothing is left stale. Every page below is opened, read and updated (or explicitly marked "no
change needed" in the branch's checklist). The list comes from a `grep` of the docs (2026-11-27)
and is regenerated at step 8:

- **Guide**: setup (`groups`, `users`, `main-app-config` for presets, `record-layouts`, `dbml`,
  `mcp`, `text-search`, `finalizing-setup`), usage (authentication → cookie; files; deleting
  records), `create-app` anatomy, `tree-structure`, `conventions`, `api/index`, install
  (`containers`, `manual-download`, `update`, `uninstall`), `deploy`, `environment`, system
  plugins, `evaluate`. New or rewritten: a guide page on **files** (table/cards, permissions,
  custom fields, public flag, presets) and the update notes for this migration.
- **Dev**: `architecture`, `config`, `database`, `frontend`, `groups-permissions`, `lib-map`,
  `mcp`, `migrations`, `modules`, `oauth`, `record-lifecycle`, `sql-layer`, `testing`,
  `widget-api`, `index`; this page, turned into a reference once done.
- **API**: `openapi.yaml` (routes, schemas, security schemes incl. the cookie), the REST docs, MCP
  tool descriptions and prompts.
- **Deploy/backup**: `.htaccess`, nginx/Caddy snippets, docker files, `docker-backup.sh` /
  `docker-restore.sh`, the `bdus-ops` toolbox, backup/restore pages.
- **Assistant entry points**: `llms.txt`, `guide/evaluate.md`, skill `bradypus-bootstrap`.
- **Release**: `CHANGELOG`, release notes with the breaking items, locale strings (it/en),
  demo seed (files under uuid, richest-records convention kept).

## 8. Release type

**Minor** (decision 16). Even so it is breaking in practice (file URLs change, direct static
access ends), so the release notes open with that, say exactly what the upgrade does, and the
upgrade page tells embedders to switch to public preset URLs. Needs: a backup, the migration run
on the demo and on every real app first (PAThs, CAJ).

## 9. Order of work (each step green on its own, inside the branch)

Each step ends with: its tests written and the **existing tests it affects fixed**, the docs it
affects updated, the full `bdus-api/test.sh` read to the end, then a commit (one concern per
commit).

1. **uuid** ✅ *done 2026-11-27 (commits aafd9cad…841c4adf; PHPUnit on 3 engines, hurl 71 phases on 3 engines, seed).* M060, `uuid` + `public` columns, upload/import/replace/delete by uuid, derived path by
   uuid, verifier, rename of files on disk; the frontend moves to the new URLs in the same step.
2. **Serving through PHP**: `GET /api/file/{uuid}` and the derived route by uuid, Bearer auth.
3. **Cookie** (GET/HEAD file routes only), cross-origin support, frontend `<img src>`.
4. **`FileAccess`** + gates on every file endpoint (§2.2) + the matrix tests + `*`/tier coverage.
5. **`bdus_files` as a normal table**: config UI, custom fields, generic record path (versions
   included), MCP/DBML/export/import.
6. **Record deletion with the per-file choice** (§2.4a): pre-check, API parameter, UI dialog, MCP.
7. **Files page**: table view + cards view on the generic table pieces (§5).
8. **`public` field + preset `access`/`enabled`** + admin UI (thumb always listed).
9. **Docs, assistants, changelog, release notes** (§7), then **block direct static access**
   (`.htaccess`, nginx/Caddy snippets, `bdus-ops`, docker) behind an explicit go.

Notes on step 1 (deviations from §3, to finish later):

- The preset `access` backfill (existing presets → `public`, §3.5) cannot live in M060: the field
  does not exist yet. It goes in the migration of step 8.
- The **report-only scan for old URLs** inside text/markdown fields, templates and the welcome
  text (§3.6) is **not done yet**; the verifier already checks uuid and binary names.
- Rows inserted by tools that bypass the API (e.g. the Directus migrator) have `uuid = NULL`
  and cannot be served: the verifier reports them (`file_uuids`), but a repair command
  (assign uuid + rename) is still to be written.
- Frontend: unit tests and the build of the pure helpers pass; the gallery and Files page were
  **not exercised in a browser** yet.

## 9b. Final rehearsal on real applications (added 2026-11-27)

Before the merge, on **local copies** (never on the production VM): **PAThs** (a real v4→v5 data set with
many files: the M060 rename, M061, the verifier report, access by role, the table/cards views) and **Atlas**
(the public application: public presets, `public` flag, the website embedding derived images, the direct
address closed). The copies come from a backup restored with `bdus-ops`; the user provides them. Each run
starts from a fresh restore and a backup, and ends with the verifier clean and a manual pass in the UI.

## 9a. Definition of done (every line verified, none assumed)

- [ ] Every test in the §6 inventory reviewed; each change to an existing test justified in its
      commit message; none deleted or weakened to pass.
- [ ] New tests for every new behaviour; each guard mutation-checked.
- [ ] `bdus-api/test.sh` and `test.sh --all-engines` green, output actually read; frontend tests
      and build green.
- [ ] Migration rehearsed on a copy of each real app (PAThs, CAJ) and on the demo, with a backup,
      including interruption and re-run; verifier clean.
- [ ] Every page of §7 updated; `OpenApiParityTest` and OpenAPI lint clean.
- [ ] Rehearsal on local copies of PAThs and Atlas (§9b).
- [ ] Manual pass in the real UI: permissions per role, table and cards views, delete dialog,
      presets admin, cookie in a clean browser, cross-origin setup, direct URL now refused.
- [ ] Release notes written; version and CHANGELOG per the bump-version flow.

## 10. Overlap with the other session (phase 0)

Phase 0 is being decided elsewhere. Anything it ships first reduces this plan: per-file gates on
`updateFile`/`replaceFile`/`sortFiles`/`deleteFile` (→ step 4 starts from them), a download
endpoint (→ step 2), the cookie choice (→ step 3). **Open item: ask that session what it will
ship, and rebase this branch after it lands.** The `v5` checkout currently has uncommitted edits
to `Config.php`, `Derivative.php` and the locale files (presets area): they must be committed or
discarded there before this branch touches the same code. `feat/views-and-orphan-tables` may
conflict on system-table handling in Config/Home.

## 11. Resolved decisions and what is still open

Resolved on 2026-11-27: public = presets only (10); existing files/presets keep today's
behaviour (`public = 1`, presets `public`); `*` rules and tiers include files (11); required
custom fields enforced on update only; thumb always listed (12); orphans only by direct access (13);
record deletion asks about files (14); cross-origin cookies supported (15); minor release (16).

Still to confirm (each has a default; say if you disagree):

1. **`thumb` not disableable** (it feeds the table and card views). Default: not disableable.
2. **Existing presets** are all marked `access = public` by the migration to keep sites working;
   the built-in `thumb` is `inherit`. Default: as stated.
3. **API default when a record is deleted without the `files` parameter**: keep the files (safe,
   creates orphans, reports them). This changes today's behaviour (auto-purge). Default: keep.
4. **Preview of non-images** in table/cards: a type icon, no server-side rendering of PDFs for now.
5. **Phase 0 overlap** (§10): to be reconciled with the other session before step 2.
