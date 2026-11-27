---
title: Record lifecycle
---

# Record lifecycle

Every user-visible record in BraDypUS is assembled, mutated, and persisted
through three dedicated classes: `Record\Read`, `Record\Edit`, and
`Record\Persist`. Understanding their responsibilities is the prerequisite
for working on any feature that touches record data.

```
Record\Read  ──►  Record\Edit  ──►  Record\Persist
  (load)           (mutate)          (write)
```

`Record\Save` drives the three for a create or an update, after checking access
and validating the payload with `Record\Validator` (see "the whole write path"
below).

All three operate on the same **model** — a structured PHP array that
represents a single record together with all its associated data.

---

## The record model

`Record\Read::getFull()` returns the canonical model:

```php
[
  'metadata'    => ['tb_id' => 'sites', 'rec_id' => 42, 'tb_label' => 'Sites'],
  'core'        => [...],   // main table fields
  'plugins'     => [...],   // plugin table rows
  'links'       => [...],   // outgoing config-defined links to other tables
  'backlinks'   => [...],   // incoming links via plugin tables
  'manualLinks' => [...],   // free-form cross-record links (bdus_userlinks)
  'files'       => [...],   // attached files (bdus_files + bdus_file_links)
  'geodata'     => [...],   // geospatial features (bdus_geodata)
  'rs'          => [...],   // stratigraphic relations (bdus_rs)
  'bibliography'=> [...],   // Zotero links (bdus_zotero_links)
]
```

`geodata`, `rs` and `bibliography` are filled only when the table config
enables them (`tables.{tb}.geodata`, `.rs`, `.zotero`); otherwise they are `[]`.
A `Read` model carries the `metadata` key too: `tb_label` comes from
`tables.{tb}.label`.

### Core field structure

Each field in `core` (and in plugin rows) uses an enriched descriptor:

```php
'name_field' => [
    'name'      => 'name_field',    // column name
    'label'     => 'Site name',     // human label from config
    'val'       => 'Cosa',          // current DB value
    'val_label' => 'Cosa (Rome)',   // resolved label for id_from_tb fields
]
```

`Record\Edit` signals a pending change by adding `_val` alongside `val`.
`Record\Persist` reads `_val` and writes it; `val` is never touched by
Persist and remains the pre-write snapshot.

---

## `Record\Read` — load

### Construction

```php
$record = new \Record\Read(
    id:     42,
    id_fld: null,       // alternative: lookup by id_field value instead of PK
    tb:     'sites',
    db:     $db,        // DB\DBInterface
    cfg:    $cfg        // Config\Config
);
```

`getTb()` returns the table name; `getCore('fld', true)` returns the raw
`val` of a single field and `getPlugin('plg', $index, 'fld')` narrows a plugin
lookup the same way.

Pass `id_fld` instead of `id` to look up the record by its configured
`id_field` (the human-readable identifier column) rather than the PK.

### `getFull(): array`

Assembles the complete model by calling all sub-loaders:

| Method | Returns |
|---|---|
| `getCore()` | Main table fields + `val_label` for `id_from_tb` fields |
| `getPlugin()` | All plugin table rows for this record |
| `getLinks()` | Config-defined outgoing links (count + ShortSQL where) |
| `getBackLinks()` | Incoming links via plugin tables (count + ShortSQL where) |
| `getManualLinks()` | `bdus_userlinks` entries in both directions |
| `getFiles()` | `bdus_files` joined with `bdus_file_links` |
| `getGeodata()` | `bdus_geodata` rows + GeoJSON representation |
| `getRs()` | `bdus_rs` pairs where `first` or `second` = this record |
| `getBibliography()` | `bdus_zotero_links` for this record, keyed by link id (`[]` if the table does not exist) |

All results are **cached** on the `Read` instance — repeated calls to
`getCore()` etc. within the same request hit no additional queries.

### Links vs back-links

**`getLinks()`** follows config-defined `link` entries on the current table.
Each link describes which fields in another table reference this record. The
response includes the total count and a ShortSQL `where` string the frontend
uses to navigate to the linked records.

**`getBackLinks()`** follows `backlinks` config entries. Each backlink
describes a plugin table that links another table back to this one via
`id_link`. The ShortSQL `where` uses an inline sub-query:

```
id|in|{@plugin_table~[id_link|distinct~?fld|=|value}
```

The `filter` returned with each backlink (and passed unchanged by clients to
`/records/{tb}?filter=`) selects exactly the `tot` records. When the bridge table
is a plugin of the referenced table it is relational and constant-size:

```json
{ "m_msplaces": { "place": { "_eq": 53 } } }
```

Otherwise it falls back to `{ "id": { "_in": [...] } }` with deduplicated
numeric ids, capped at 200; a capped list adds `"filter_truncated": true`.
Direct `links.<tb>.filter` entries are already relational (`{ fk: { _eq: v } }`).

### `id_from_tb` auto-joins

`getTbRecord()` (the private fetch kernel) detects fields with an
`id_from_tb` config property and automatically adds a `LEFT JOIN` to the
referenced table. The joined label is returned as `val_label`; the raw FK
integer is in `val`.

---

## `Record\Edit` — mutate

`Edit` wraps a model loaded by `Read::getFull()` (the constructor calls it) and
provides setter methods
that stage changes using `_val` / `_delete` markers. Nothing is written to the
DB until `persist()` is called.

### Construction

```php
$edit = new \Record\Edit($record);   // $record: a Record\Read instance
```

### Setters

| Method | Effect |
|---|---|
| `setCore(array $data)` | Stages field changes: `core[fld]['_val'] = newVal` |
| `delete()` | Marks the core record for deletion: `core['id']['_delete'] = true` |
| `setPluginRow(string $plugin, ?int $id, array $data)` | Insert / update / delete a plugin row |
| `setFile(?int $id, array $data, $file)` | Insert / update / delete a file attachment |
| `setRs(?int $id, ?string $first, ?string $second, ?string $relation)` | Insert / update / delete an RS relation |
| `setManualLink(?int $id, ?string $toTable, ?int $toId, ?int $sort)` | Insert / update / delete a manual link |

`setCore()` ignores the `id` field and silently skips fields where the new
value equals the existing `val` (to avoid spurious history entries).

All setters return `$this`, so calls can be chained. `getModel(bool $json,
bool $pretty)` returns the staged model (array or JSON) and `getLog()` the
messages collected while staging.

### `persist($db, $cfg): array`

Delegates to `Persist::all($this->getModel(), $db, $cfg)` and returns the result:

```php
$result = $edit->persist($db, $cfg);
// ['core' => ['affected'=>1, 'id'=>42], 'plugins' => [...], ...]
```

---

## `Record\Persist` — write

`Persist` translates the staged model into SQL. It is called via the static
factory `Persist::all()` or by instantiating and calling individual
`persist*()` methods.

### `Persist::all(array $model, DB $db, Config $cfg): array`

The canonical entry point. Runs core, plugins, manual links and RS, in this
order, and returns counts. Files are handled by the controller, not by
`Persist`.

```php
[
  'core'        => ['affected' => 1, 'id' => 42],
  'plugins'     => ['inserted' => 0, 'updated' => 1, 'deleted' => 0],
  'manualLinks' => ['inserted' => 0, 'updated' => 0, 'deleted' => 0],
  'rs'          => ['inserted' => 0, 'updated' => 0, 'deleted' => 0],
]
```

### `persistCore(): array`

Handles INSERT, UPDATE, and full cascaded DELETE of the core record.

**UPDATE path**:
1. A second-pass filter removes apparent changes that are only type
   mismatches (DB returns `"5"` / string, form sends `5` / int).
2. `DB::saveSnapshot()` is called with the **pre-write** state so
   the change can be undone.
3. `UPDATE table SET fld = ? WHERE id = ?` is executed.

**INSERT path**:
Builds `INSERT INTO table (fields) VALUES (?)`. The new auto-increment PK
is stored on the `Persist` instance so subsequent plugin/link insertions
can reference it.

**DELETE path** (triggered by `core['id']['_delete'] = true`):
1. Snapshot is saved first.
2. `deleteAll()` runs in a single transaction:
   - Core row
   - All plugin rows (`id_link = id`)
   - All `bdus_userlinks` entries in both directions
   - All `bdus_rs` entries referencing this record's RS field value
   - the `bdus_file_links` entries of the record; and, for each file linked **only** to it, the caller's
     choice (`Edit::delete($fileChoices, $filesDefault)` → `_files_on_delete` in the model): `delete` removes
     the `bdus_files` row, `keep` (the default) leaves an orphan. A file with other links is never touched.
3. Physical file deletion (binary and derived versions, `File\Store::remove()`) runs **after** the transaction
   commit, only for the files chosen for deletion (best-effort; `unlink()` is not rolled back on failure).
   `Record\Delete` exposes the outcome (`orphanedFiles`, `deletedFiles`) and `Delete::exclusiveFiles()` lists
   the files a deletion would leave without records (behind `GET /api/record/{tb}/files-before-delete`).

### `persistPlugins(): array`

Iterates plugin rows in the model. Uses a single DB transaction for all
plugin tables of a record. DELETE → `DELETE FROM plugin WHERE id = ?`;
UPDATE → `UPDATE plugin SET fld = ? WHERE id = ?`; INSERT → adds
`id_link` automatically if absent.

### `persistManualLinks(): array`

Handles `bdus_userlinks`: INSERT stores both ends of the link
(`tb_one`, `id_one`, `tb_two`, `id_two`); UPDATE changes only `sort`.

### `persistRs(): array`

Handles `bdus_rs`: INSERT sets `tb`, `first`, `second`, `relation`.

### Versioning

`persistCore()` calls `DB::saveSnapshot()` for every write:

| Write | Entry in `bdus_versions` |
|---|---|
| INSERT | `operation = 'create'`, **no content** (`{core: [], plugins: []}`): who created the record, when, through which channel |
| UPDATE | `operation = 'update'`, the record and its plugin rows as they were **before** the change |
| DELETE | `operation = 'delete'`, the record and its plugin rows before deletion |

```php
$db->saveSnapshot(
    tb:        'sites',
    id:        42,
    content:   ['core' => [...], 'plugins' => [...]],
    operation: 'update'
);
```

The snapshot of an UPDATE and of a DELETE is written in the same transaction as
the write: a change the database refuses (a constraint, a trigger) leaves no
entry. The `create` entry is written right after the INSERT.

Each entry also says where the change came from (M053): `channel` (`web`, `api`,
`mcp`, `import`, `cli` — see `Auth\Channel`), `key_id` (the API key) and
`client_id` (the OAuth client). An entry point that is not the plain REST API
names itself with `Auth\Channel::set()`.

A `create` entry holds no state: `restoreVersion` answers
`version_not_restorable` for it and the interface does not open it.

`tb` and the snapshot's columns are those of the table name used in the request;
for a [SQL view registered as a table](/guide/setup/views) that is the view name
and the view's columns only.

---

## Complete read–edit–write example

```php
// 1. Load
$record = new \Record\Read(id: 42, tb: 'sites', db: $db, cfg: $cfg);

// 2. Inspect
$full  = $record->getFull();
$label = $record->getCore('site_name', true);  // returns 'val' only

// 3. Edit
$edit = new \Record\Edit($record);
$edit->setCore(['site_name' => 'Cosa (updated)', 'typology' => 'villa']);
$edit->setPluginRow('sites_contexts', 42, ['description' => 'new text']);

// 4. Persist
$result = $edit->persist($db, $cfg);
// $result['core'] === ['affected' => 1, 'id' => 42]
```

---

## `Record\Save` — the whole write path

`Record\Save::save($tb, $id, $core, $plugins): array` creates (`$id = null`) or
updates one record with its plugin rows, and answers with the envelope of
`POST /api/record/{tb}` (`status`, `code`, `id`, or `errors` / `detail`). It
knows nothing of the HTTP request, so every entry point that writes a record
goes through it and gets the same rules, in this order:

1. the table exists and is not a read-only view (`view_read_only`);
2. the access gate — `Auth\WriteGate::mayChange()` for an existing record (tier
   or group rule, ownership for a self writer, row scope, locks),
   `Authorization::can('add_new')` or `WriteGate::mayAddPluginRow()` for a new one;
3. the shape of the payload — `Record\Validator::payloadShapeErrors()`: only
   fields of the table and of its plugin tables, plain values;
4. the values a group rule pins — `Access::enforceValues()`;
5. validation — `Record\Validator::validatePayload()`: `required`, `int`,
   `real`, `email`, `min` / `max`, `max_length`, `pattern`, `no_dupl`,
   `valid_wkt`, and the existence of a lookup target (`fk_not_found`); an empty value
   for a numeric / boolean / date column whose database column is `NOT NULL` without a
   default is `required` too;
6. the write: `Record\Edit` → `persist()` for an update; for a **new** record
   the model is built directly (`rec_id => null`) and written with
   `Record\Persist::all()`. Radiocarbon plugin rows are calibrated server-side.
   Every value goes through `Record\ColumnValue::forColumn()`: an empty value for a
   numeric, boolean, date or timestamp column (by the field's `db_type`) is written as
   `NULL`, because PostgreSQL refuses `''` there; text columns keep it. The CSV import
   applies the same rule.

`Record\Validator` never writes. `Record\Save::check()` runs steps 1–5 without
step 6 — a dry run — and returns the values as they would be written and the
stored values they replace.

An update of an id that does not exist answers `record_not_found` (after the
access gate, so that the existence of a record is not told to someone who could
not read it).

`Record\Delete::delete($tb, $id)` is the counterpart for deletions: existence,
the same gate, then `Record\Edit::delete()`; it answers `deleted`, `not_found`
or `not_enough_privilege`.

```php
$save   = new \Record\Save($db, $cfg, \Auth\Access::forCurrentUser($db, $cfg), $log);
$result = $save->save('items', null, ['code' => 'A1', 'title' => 'First'], []);
// ['status' => 'success', 'code' => 'success_created', 'id' => 42]
```

## Where the controller fits in

`Bdus\Controllers\Record` (`controllers/Record.php`) is the HTTP boundary. It
reads the request, calls the classes above and emits the answer with
`$this->returnJson([...])`:

- `saveRecord()` reads `tb`, `id`, `core`, `plugins` from the body and hands
  them to `Record\Save`;
- `getRecord()` instantiates `Record\Read` and adds the table schema built by
  `Config\TableSchema::describe()` (fields, plugin tables, features);
- `getFieldOptions()` returns `Record\FieldOptions::resolve()` — the values a
  field offers, limited to the rows the principal may read.

Privilege tiers come from `Router::ROUTE_PRIVILEGE` (see
[Bootstrapping & routing](./architecture)); finer, per-table checks
(`Authorization::can()`, owner/creator rules, group rules) happen **before**
`Record\Read` / `Record\Edit` write anything.

Further reading: `lib/Record/DataStructure.md` in the API repository describes
the model fields in detail.


## Files in the record layer <Badge type="tip" text="v5.30.0" />

`bdus_files` is a configured table (`File\ConfigSeed`, M061), so the generic read path works on it: `Access` reads
it through its own rules **or** the records its files are attached to (`Access::fileReadCondition()`), and
`Record\Read` / the list and record endpoints, search and export follow. The write path is the same
`Record\Save` as for any table, with three file-specific rules: the gate is `Auth\FileAccess::mayChange()`
(a rule on the files table, or every attached record changeable, and no lock on any of them); the columns of the
binary (`uuid`, `ext`, plus `id` and `creator` as everywhere) are dropped from the payload; a row cannot be
**created** (`files_are_uploaded`: uploading creates it, with its uuid and binary). A file cannot be erased as a
record either (`files_are_deleted_as_files`): `DELETE /api/file/{id}` removes the row, its links, the binary and
the derived versions together. `File::updateFile` calls `Save`, so a change to a file is validated, written and
versioned like any record's.
