# ════════════════════════════════════════════════════════════════════
# Phase 12 — Data import
#
# Tests the full import pipeline:
#   getTableFields → previewFile (CSV) → importData (CSV, insert+upsert)
#   previewFile (JSON) → importData (JSON)
#   previewFile (GeoJSON) → importGeoJson
#   Error paths: missing params, bad temp_id, key_field not mapped
#   Photo import: previewPhotos / importPhotos (validation only — no ZIP fixture)
# ════════════════════════════════════════════════════════════════════

# ── 12a. getTableFields — returns field list for known table ──────────
GET {{base_url}}/api/import/us/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"         == "success"
jsonpath "$.fields"         isCollection
jsonpath "$.fields[0].name" isString
jsonpath "$.fields[0].label" isString
jsonpath "$.fields[0].type" isString


# ── 12b. getTableFields — unknown table returns empty list ────────────
GET {{base_url}}/api/import/nonexistent_table/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.fields" isEmpty


# ── 12c. previewFile — upload CSV, capture temp_id ───────────────────
POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/import_test.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.temp_id"  isString
jsonpath "$.columns"  isCollection
jsonpath "$.count"    == 2
jsonpath "$.rows"     isCollection
[Captures]
csv_temp_id: jsonpath "$.temp_id"
csv_columns: jsonpath "$.columns"


# ── 12d. importData — CSV insert (2 new rows) ─────────────────────────
POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{csv_temp_id}}",
  "type":      "csv",
  "tb":        "us",
  "mapping":   { "sigla": "sigla", "descrizione": "descrizione", "periodo": "periodo" },
  "key_field": "sigla"
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.code"     == "ok_import_data"
jsonpath "$.inserted" == 2
jsonpath "$.updated"  == 0
jsonpath "$.total"    == 2


# ── 12e. importData — CSV upsert (same sigla → update) ───────────────
# Re-upload the same CSV and import again; rows now exist → updated=2
POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/import_test.csv; type=text/csv
HTTP 200
[Captures]
csv_temp_id2: jsonpath "$.temp_id"

POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{csv_temp_id2}}",
  "type":      "csv",
  "tb":        "us",
  "mapping":   { "sigla": "sigla", "descrizione": "descrizione", "periodo": "periodo" },
  "key_field": "sigla"
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.inserted" == 0
jsonpath "$.updated"  == 2
jsonpath "$.total"    == 2


# ── 12f. previewFile — upload JSON, capture temp_id ──────────────────
POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: json
file: file,fixtures/import_test.json; type=application/json
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.temp_id" isString
jsonpath "$.count"   == 2
[Captures]
json_temp_id: jsonpath "$.temp_id"


# ── 12g. importData — JSON insert (2 new rows) ────────────────────────
POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{json_temp_id}}",
  "type":      "json",
  "tb":        "us",
  "mapping":   { "sigla": "sigla", "descrizione": "descrizione", "periodo": "periodo" },
  "key_field": "sigla"
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.inserted" == 2
jsonpath "$.updated"  == 0


# ── 12h. previewFile (GeoJSON) — capture temp_id ─────────────────────
POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: geojson
tb: us
file: file,fixtures/import_test.geojson; type=application/json
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.temp_id"         isString
jsonpath "$.count"           == 1
jsonpath "$.geometry_types"  isCollection
jsonpath "$.geo_props"       isCollection
[Captures]
geojson_temp_id: jsonpath "$.temp_id"


# ── 12i. importGeoJson — feature matched by sigla (IMP001 from 12d) ──
POST {{base_url}}/api/import/geojson
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{geojson_temp_id}}",
  "tb":        "us",
  "geo_prop":  "sigla",
  "key_field": "sigla"
}
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.code"      == "ok_import_geojson"
jsonpath "$.total"     == 1
jsonpath "$.updated"   == 1
jsonpath "$.not_found" == 0


# ── 12j. Error: importData without required params ─────────────────────
POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "temp_id": "x" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 12k. Error: importData with non-existent temp_id ─────────────────
POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "does_not_exist_xyz",
  "type":      "csv",
  "tb":        "us",
  "mapping":   { "sigla": "sigla" },
  "key_field": "sigla"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "import_error_no_file"


# ── 12l. Error: key_field not present in mapping values ───────────────
POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/import_test.csv; type=text/csv
HTTP 200
[Captures]
err_temp_id: jsonpath "$.temp_id"

POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{err_temp_id}}",
  "type":      "csv",
  "tb":        "us",
  "mapping":   { "sigla": "sigla" },
  "key_field": "descrizione"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "import_error_no_key"


# ── 12m. Error: previewFile with unknown type ─────────────────────────
POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: xml
file: file,fixtures/import_test.csv; type=text/plain
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "import_error_unknown_type"


# ── 12n. Error: mapping value is not a valid table field ──────────────
POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/import_test.csv; type=text/csv
HTTP 200
[Captures]
err2_temp_id: jsonpath "$.temp_id"

POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{err2_temp_id}}",
  "type":      "csv",
  "tb":        "us",
  "mapping":   { "sigla": "nonexistent_db_field" },
  "key_field": "nonexistent_db_field"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "import_error_invalid_field"
jsonpath "$.detail" isString


# ── 12o. Error: no mapping key matches any column in the file ─────────
POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/import_test.csv; type=text/csv
HTTP 200
[Captures]
err3_temp_id: jsonpath "$.temp_id"

POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{err3_temp_id}}",
  "type":      "csv",
  "tb":        "us",
  "mapping":   { "colonna_fantasma": "sigla" },
  "key_field": "sigla"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "import_error_mapping_mismatch"
jsonpath "$.detail" isString


# ── 12p. Warning: CSV with only header row (0 data rows) ─────────────
POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/import_empty.csv; type=text/csv
HTTP 200
[Captures]
empty_temp_id: jsonpath "$.temp_id"

POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{empty_temp_id}}",
  "type":      "csv",
  "tb":        "us",
  "mapping":   { "sigla": "sigla" },
  "key_field": "sigla"
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "warning"
jsonpath "$.code"     == "ok_import_data"
jsonpath "$.total"    == 0
jsonpath "$.detail"   isString


# ── 12q. previewPhotos — missing required files → error ──────────────
# (Full photo import requires a ZIP + CSV index pair; here we only test
#  the validation path since no binary ZIP fixture exists in the suite.)
POST {{base_url}}/api/import/preview-photos
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "import_error_no_file"


# ── 12r. importPhotos — missing temp_id + tb → parameter_missing ────
POST {{base_url}}/api/import/photos
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"
