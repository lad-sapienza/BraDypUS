# Import module

Route: `/import`  
Controller: `import_ctrl` (`modules/import/import.php`)  
Vue view: `vue/src/views/ImportView.vue`

---

## UI spec

A three-step wizard accessible from the sidebar ("Import data"). The user can import data from four source types:

| Type | Description |
|---|---|
| **CSV** | Tabular data with header row. Comma, semicolon or tab delimiter (auto-detected). |
| **JSON** | Array of objects, or `{ "data": [...] }` envelope. |
| **GeoJSON** | `FeatureCollection`. Imports geometry only into the table's `geodata` field; replaces any existing value. |
| **Photos (ZIP + index)** | ZIP archive of image files + CSV index (`filename,record_id`). Links each photo to its record. |

### Step 1 — Setup
- Select import type
- Select target table
- Upload file(s)
- Click **Preview** → sends file to server, receives `temp_id` + preview data

### Step 2 — Configure

**CSV / JSON:**
- Preview table (first 10 rows)
- Column → field mapping dropdown for each source column (`— ignore —` to skip)
- Key field selector: which mapped table field uniquely identifies records for upsert

**GeoJSON:**
- Feature count + geometry types displayed
- Select which GeoJSON property to use as the match key
- Select which table field to match against

**Photos:**
- Index rows preview (first 10)
- Warning if any filenames in the index are missing from the ZIP

### Step 3 — Result
- Success/error message
- Stats: Total / Inserted / Updated / Not matched / Photos linked

---

## API endpoints

### `GET import_ctrl::getTableFields?tb=<table>`
Returns all fields for a table (used to populate mapping dropdowns).

**Requires:** `read` privilege.

**Response:**
```json
{ "status": "success", "fields": [{ "name": "id", "label": "ID", "type": "text" }, …] }
```

---

### `POST import_ctrl::previewFile` (multipart)
Uploads a CSV, JSON or GeoJSON file, stores it temporarily, returns a preview.

**Requires:** `edit` privilege.

**POST fields:** `file` (binary), `type` (`csv`|`json`|`geojson`), `tb` (required for GeoJSON geo-field detection).

**Response (CSV/JSON):**
```json
{
  "status": "success",
  "temp_id": "a1b2c3d4e5f6g7h8",
  "columns": ["col1", "col2"],
  "rows": [["v1", "v2"], …],
  "count": 120
}
```

**Response (GeoJSON):**
```json
{
  "status": "success",
  "temp_id": "…",
  "count": 45,
  "geometry_types": ["Point", "Polygon"],
  "geo_props": ["id", "name", "site_code"],
  "geo_field": "geo_data"
}
```

---

### `POST import_ctrl::previewPhotos` (multipart)
Uploads ZIP + CSV index, validates filenames, returns preview.

**Requires:** `edit` privilege.

**POST fields:** `zip` (binary), `index` (binary CSV).

**Response:**
```json
{
  "status": "success",
  "temp_id": "…",
  "index_rows": [{ "filename": "img1.jpg", "record_id": 42 }, …],
  "missing_files": ["img99.jpg"],
  "total": 150
}
```

---

### `POST import_ctrl::importData` (JSON body)
Upserts CSV/JSON rows atomically.

**Requires:** `edit` privilege.

**Body:**
```json
{
  "temp_id": "…",
  "type": "csv",
  "tb": "myapp_sites",
  "mapping": { "code": "site_code", "name": "name", "lat": null },
  "key_field": "site_code"
}
```

- `mapping`: file column → table field (null = skip)
- `key_field`: the table field used for lookup (must be present in mapping values)
- Upsert logic: SELECT by key → UPDATE if found, INSERT if not
- Entire import runs in a single transaction; first error rolls back everything

**Response:**
```json
{ "status": "success", "code": "ok_import_data", "inserted": 12, "updated": 5, "total": 17 }
```

---

### `POST import_ctrl::importGeoJson` (JSON body)
Updates geodata field for matched records.

**Requires:** `edit` privilege.

**Body:**
```json
{
  "temp_id": "…",
  "tb": "myapp_sites",
  "geo_prop": "site_code",
  "key_field": "site_code"
}
```

- `geo_prop`: GeoJSON `properties` key whose value is used to look up the record
- `key_field`: table field to match against
- Existing geodata value is **replaced** (not merged)
- Atomic transaction; partial failures roll back everything

**Response:**
```json
{ "status": "success", "code": "ok_import_geojson", "updated": 40, "not_found": 5, "total": 45 }
```

---

### `POST import_ctrl::importPhotos` (JSON body)
Extracts photos from ZIP and links them to records.

**Requires:** `edit` privilege.

**Body:**
```json
{ "temp_id": "…", "tb": "myapp_sites" }
```

- Index CSV must have columns `filename` and `record_id` (or `file` / `id` as aliases)
- Files are written to `PROJ_DIR/files/` with a collision-safe name
- A row is inserted into `{prefix}files` (creator = `'import'`) and `{prefix}file_links`
- Atomic DB transaction; already-written files are not deleted on rollback

**Response:**
```json
{ "status": "success", "code": "ok_import_photos", "linked": 148, "not_found": 2, "total": 150 }
```

---

## Error codes

| Code | Meaning |
|---|---|
| `not_enough_privilege` | User lacks required privilege |
| `parameter_missing` | Required parameter not provided |
| `import_error_no_file` | No file uploaded / temp file not found |
| `import_error_file` | Error saving uploaded file to temp |
| `import_error_parse` | Could not parse file content |
| `import_error_unknown_type` | `type` not one of csv/json/geojson |
| `import_error_no_key` | `key_field` not covered by mapping |
| `import_error_no_geo_field` | Table has no `geodata`-type field |
| `import_error_zip` | Could not open ZIP archive |
| `import_error_transaction` | DB error; transaction rolled back |

---

## Implementation notes

### Temp file lifecycle
Files uploaded in Step 1 are stored in `sys_get_temp_dir()` as `bdus_import_{tempId}[.zip/.csv]`.  
They are deleted immediately after a successful or failed import in Step 2.  
If the user abandons the wizard after Step 1, the temp file will expire with the OS's temp cleanup cycle.

### Table name convention
`$tb` received from the frontend always includes the app prefix (e.g. `myapp_sites`).  
The controller uses `$tb` directly in SQL — **never** prepended with `$this->prefix`.  
System tables (`{prefix}files`, `{prefix}file_links`) still use `$this->prefix . 'tablename'`.

### CSV delimiter auto-detection
Reads the first line and counts commas, semicolons and tabs.  
The delimiter with the highest count wins.

### GeoJSON → WKT
Uses `geoPHP\geoPHP::load($geometryJson, 'geojson')->out('wkt')`.  
Requires `geoPHP/geophp` Composer package (already a dependency via geoface module).

---

## Future improvements

- **Dry-run mode**: preview what would be inserted/updated without committing
- **Error row export**: when transaction rolls back, report which row caused the failure
- **Update-only mode**: reject rows that would trigger an INSERT (stricter data discipline)
- **GeoJSON properties → fields**: optionally import GeoJSON properties alongside geometry
- **Photo resize on import**: apply the same max-dimension limit as `file_ctrl::upload()`
- **Progress indicator**: for large files (>1000 rows), stream progress via SSE or polling
