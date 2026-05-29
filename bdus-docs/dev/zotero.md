---
title: Zotero integration
---

# Zotero integration

BraDypUS can link any record in any data table to one or more items in
online [Zotero](https://www.zotero.org/) libraries (user or group).
Citations are cached locally and refreshed on demand; Zotero remains the
authoritative source.

---

## Architecture overview

```
bdus_zotero_libs          bdus_zotero_links
─────────────────         ──────────────────────────────────
id                        id
type   (user | group)     tb         (table name)
zotero_id                 record_id
name                      lib_id  ──► bdus_zotero_libs.id
api_key  (nullable)       zotero_key
citation_style            pages, notes, sort
created_at                author_year, full_citation  ← cache
                          zotero_version              ← cache
                          synced_at                   ← cache
                          detached (default 0)
                          created_at
```

`bdus_zotero_links.lib_id` has a `CASCADE` FK to `bdus_zotero_libs.id`:
deleting a library removes all its links automatically.

---

## DB migration

Both tables are created by **M023** (`DB\System\Migrations\M023_ZoteroTables`).
The migration runs automatically on the first request after upgrade (idempotent).

---

## PHP layer

### `Zotero\Client`

`lib/Zotero/Client.php` — thin wrapper around the Zotero REST API v3.

| Method | Description |
|---|---|
| `search(string $q, int $limit): array` | Full-text search; returns raw item arrays |
| `getItems(array $keys): array` | Batch fetch up to 50 keys; returns keyed by `zotero_key` |
| `getFormattedCitation(string $key, string $style): string` | HTML citation via CSL API |
| `extractAuthorYear(array $item): string` | Best-effort `Author YYYY` string from item data |
| `buildPublicUrl(string $key): ?string` | Group URL `zotero.org/groups/{id}/items/{key}`; `null` for user libs |

Constructor: `new Client(string $type, string $zoteroId, ?string $apiKey)`.

Default citation style: `chicago-author-date` (`Client::DEFAULT_STYLE`).

### `zotero_ctrl`

`modules/zotero/zotero.php` — controller with inline privilege guards.

| Route | Privilege | Description |
|---|---|---|
| `GET  /api/zotero/libs` | admin | List libraries (api_key redacted) |
| `POST /api/zotero/lib` | admin | Add a library |
| `DELETE /api/zotero/lib/{id}` | admin | Remove a library |
| `GET  /api/zotero/search?lib_id&q&limit` | edit | Proxy search to Zotero |
| `GET  /api/zotero/links/{tb}/{id}` | read | Get links for a record |
| `POST /api/zotero/link` | edit | Add a link (fetches and caches citation) |
| `PATCH /api/zotero/link/{id}` | edit | Update pages / notes / sort |
| `DELETE /api/zotero/link/{id}` | edit | Remove a link |
| `POST /api/zotero/sync/{tb}/{id}` | edit | Sync cache for one record |
| `POST /api/zotero/sync` | admin | Sync all referenced items |

API keys are **never** returned by `getLibs()`; the response includes
`has_api_key: bool` instead.

### `Record\Read::getBibliography()`

Called by `getFull()` — adds a `bibliography` key to the record model.
Joins `bdus_zotero_links` with `bdus_zotero_libs` and builds `zotero_url`
for group libraries. Guards against missing M023 tables to stay safe on
pre-upgrade instances.

---

## Sync strategy

**Per-record (automatic):** `ZoteroSection.vue` calls
`POST /api/zotero/sync/{tb}/{id}` in `onMounted`, after the record is
already displayed. If the Zotero version of any linked item has changed,
the cache columns are updated and the component refreshes the list.
Non-fatal — network errors are swallowed.

**Global (admin):** The **Sync all** button in **Config → Zotero Libraries**
calls `POST /api/zotero/sync`, which processes one representative row per
unique `(lib_id, zotero_key)` pair.

**Detached items:** If a Zotero item no longer exists in the library,
`detached` is set to `1`. The UI shows an orange warning badge; the link
is never deleted automatically.

---

## Frontend

### `ZoteroSection.vue`

`src/components/record/ZoteroSection.vue` — record detail section.

- Props: `bibliography` (Object), `editMode` (Boolean), `recordTb`, `recordId`
- Emits: `bib-added`, `bib-deleted`
- Features: background sync on mount, drag-to-sort (SortableJS), inline
  pages/notes editing (`@blur` save), external Zotero link for group libs,
  detached badge, search-and-add panel with library Select + debounced input.

### `ZoteroLibsPanel.vue`

`src/components/config/ZoteroLibsPanel.vue` — admin config panel
(reached via **Config → Zotero Libraries** in the sidebar).

- List all libraries with type badge, Zotero ID, citation style, API key
  status.
- Form to add a new library (type, ID, name, optional API key, optional
  citation style — defaults to `chicago-author-date`).
- Delete button per library.
- **Sync all** button with result message.

### Integration in `RecordView.vue`

`ZoteroSection` is rendered after `ManualLinksSection` and before the
Geodata section:

```vue
<ZoteroSection
  v-if="hasBibliography || mode === 'edit'"
  :bibliography="record.bibliography ?? {}"
  :editMode="mode === 'edit'"
  :recordTb="record.metadata.tb_id"
  :recordId="id"
  @bib-added="onBibAdded"
  @bib-deleted="onBibDeleted"
/>
```

`hasBibliography` is a computed that checks
`Object.keys(record.bibliography ?? {}).length > 0`.

---

## Tests

**PHPUnit:**
- `tests/Integration/M023MigrationTest.php` — 11 tests covering table
  creation, column set, indexes, uniqueness, default values, idempotency.
- `tests/Integration/ZoteroCtrlTest.php` — 40 tests covering all
  controller methods (privilege guards, parameter validation, CRUD,
  error paths). Network-dependent calls (search with real API, sync
  with items) are exercised only for their parameter-validation and
  error-handling paths.

**Hurl E2E:**
- `tests/api/17_zotero.hurl` — 16 requests covering the full HTTP API
  lifecycle: list → add lib → verify → add link → edit link → verify →
  sync → delete link → delete lib → verify.

---

## Adding a new citation style

Citation styles follow the
[CSL style repository](https://www.zotero.org/styles) identifiers
(e.g. `apa`, `mla`, `harvard-cite-them-right`). Set `citation_style`
when adding a library or leave it empty to use `chicago-author-date`.
