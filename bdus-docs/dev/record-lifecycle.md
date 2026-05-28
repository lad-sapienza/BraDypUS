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
]
```

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
    db:     $db,
    cfg:    $cfg
);
```

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

All results are **cached** on the `Read` instance — repeated calls to
`getCore()` etc. within the same request hit no additional queries.

### Links vs back-links

**`getLinks()`** follows config-defined `link` entries on the current table.
Each link describes which fields in another table reference this record. The
response includes the total count and a ShortSQL `where` string the frontend
uses to navigate to the linked records.

**`getBackLinks()`** follows `backlinks` config entries. Each backlink
describes a plugin table that links another table back to this one via
`table_link` / `id_link`. The ShortSQL `where` uses an inline sub-query:

```
id|in|{@plugin_table~[id_link|distinct~?table_link|=|ref_table||and|^fld|=|value}
```

### `id_from_tb` auto-joins

`getTbRecord()` (the private fetch kernel) detects fields with an
`id_from_tb` config property and automatically adds a `LEFT JOIN` to the
referenced table. The joined label is returned as `val_label`; the raw FK
integer is in `val`.

---

## `Record\Edit` — mutate

`Edit` wraps a model loaded by `Read::getFull()` and provides setter methods
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

### `persist($db, $cfg): array`

Delegates to `Persist::all()` and returns the result:

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

The canonical entry point. Runs all sections in order and returns counts:

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
   - All plugin rows (`table_link = tb AND id_link = id`)
   - All `bdus_userlinks` entries in both directions
   - All `bdus_rs` entries referencing this record's RS field value
   - `bdus_files` rows with no other links + `bdus_file_links` entries
3. Physical file deletion runs **after** the transaction commit (best-effort;
   `unlink()` is not rolled back on failure).

### `persistPlugins(): array`

Iterates plugin rows in the model. Uses a single DB transaction for all
plugin tables of a record. DELETE → `DELETE FROM plugin WHERE id = ?`;
UPDATE → `UPDATE plugin SET fld = ? WHERE id = ?`; INSERT → adds
`table_link` and `id_link` automatically if absent.

### `persistManualLinks(): array`

Handles `bdus_userlinks`: INSERT stores both ends of the link
(`tb_one`, `id_one`, `tb_two`, `id_two`); UPDATE changes only `sort`.

### `persistRs(): array`

Handles `bdus_rs`: INSERT sets `tb`, `first`, `second`, `relation`.

### Versioning

Before every UPDATE and DELETE, `persistCore()` calls `DB::saveSnapshot()`:

```php
$db->saveSnapshot(
    tb:        'sites',
    id:        42,
    content:   ['core' => [...], 'plugins' => [...]],
    operation: 'update'  // or 'delete'
);
```

This inserts a row into `bdus_versions` with the full pre-write state as JSON,
enabling unlimited undo / diff history accessible from the record detail view.

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

## Where the controller fits in

`record_ctrl` in `modules/record/record.php` is the HTTP boundary. It:

1. Reads `$this->get['tb']` and `$this->get['id']` from the request.
2. Instantiates `Record\Read`.
3. For write operations, instantiates `Record\Edit`, calls the appropriate
   setter(s), then calls `$edit->persist($this->db, $this->cfg)`.
4. Calls `$this->returnJson([...])` with the result.

All UAC checks happen in the controller **before** `Record\Read` /
`Record\Edit` are called.
