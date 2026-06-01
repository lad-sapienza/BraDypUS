# ════════════════════════════════════════════════════════════════════
# Phase 30 — User-defined DB indexes
#
# Tests CREATE / LIST / DELETE for explicit indexes on user tables.
# Uses the "us" table (exists from phase 3, has multiple columns).
#
# Auto-indexes generated when FK relations are saved (phase 14) are
# already present; this phase tests EXPLICIT user-defined indexes.
#
# Endpoints:
#   GET    /api/config/table/{tb}/indexes          list
#   POST   /api/config/table/{tb}/indexes          create
#   DELETE /api/config/table/{tb}/indexes/{id}     delete
# ════════════════════════════════════════════════════════════════════


# ── 30a. List — empty for a table with no explicit indexes ──────────
# (auto-indexes from FK creation in phase 14 may be present;
#  we just verify the shape is correct)
GET {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data"   isCollection
[Captures]
us_index_count_before: jsonpath "$.data" count


# ── 30b. Create — simple B-tree index on one column ─────────────────
POST {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":    "idx_us_sigla",
  "columns": ["sigla"],
  "is_unique": false
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "index_saved"
jsonpath "$.id"     isInteger
[Captures]
idx_sigla_id: jsonpath "$.id"


# ── 30c. List — new index appears ───────────────────────────────────
GET {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"                 == "success"
jsonpath "$.data[*].name"           contains "idx_us_sigla"


# ── 30d. Index shape is complete ────────────────────────────────────
GET {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[?(@.name == 'idx_us_sigla')].id"      isInteger
jsonpath "$.data[?(@.name == 'idx_us_sigla')].tb"      contains "us"
jsonpath "$.data[?(@.name == 'idx_us_sigla')].columns" isCollection
jsonpath "$.data[?(@.name == 'idx_us_sigla')].is_unique"  == false


# ── 30e. Create — unique index on one column ────────────────────────
POST {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":    "idx_us_sigla_uniq",
  "columns": ["sigla"],
  "is_unique": true
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "index_saved"
[Captures]
idx_uniq_id: jsonpath "$.id"


# ── 30f. Create — composite index on multiple columns ───────────────
POST {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":    "idx_us_sito_saggio",
  "columns": ["sito_id", "saggio_id"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "index_saved"
[Captures]
idx_composite_id: jsonpath "$.id"


# ── 30g. Composite index has correct columns array ───────────────────
GET {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[?(@.name == 'idx_us_sito_saggio')].columns" count == 2


# ── 30h. Indexes on different tables are isolated ───────────────────
# Adding an index on "siti" must not appear in "us" list.
POST {{base_url}}/api/config/table/siti/indexes
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":    "idx_siti_nome",
  "columns": ["nome"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
idx_siti_id: jsonpath "$.id"

GET {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[*].name" not contains "idx_siti_nome"


# ── 30i. Delete — simple index ──────────────────────────────────────
DELETE {{base_url}}/api/config/table/us/indexes/{{idx_sigla_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "index_deleted"


# ── 30j. Deleted index no longer appears in list ────────────────────
GET {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[*].name" not contains "idx_us_sigla"


# ── 30k. Delete — unique index ──────────────────────────────────────
DELETE {{base_url}}/api/config/table/us/indexes/{{idx_uniq_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 30l. Delete — composite index ───────────────────────────────────
DELETE {{base_url}}/api/config/table/us/indexes/{{idx_composite_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 30m. Delete — siti index ────────────────────────────────────────
DELETE {{base_url}}/api/config/table/siti/indexes/{{idx_siti_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 30n. List after deletes — back to before-count ──────────────────
GET {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data"   count == {{us_index_count_before}}


# ════════════════════════════════════════════════════════════════════
# Negative cases
# ════════════════════════════════════════════════════════════════════

# ── 30o-neg. Missing tb → error ──────────────────────────────────────
GET {{base_url}}/api/config/table/nonexistent_tb_xyz/indexes
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data"   isCollection


# ── 30p-neg. Missing name → error ────────────────────────────────────
POST {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "columns": ["sigla"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 30q-neg. Missing columns → error ─────────────────────────────────
POST {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "idx_us_test"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 30r-neg. Duplicate name for same table → error ───────────────────
POST {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "idx_us_dup_test", "columns": ["sigla"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
idx_dup_id: jsonpath "$.id"

POST {{base_url}}/api/config/table/us/indexes
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "idx_us_dup_test", "columns": ["complesso_id"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "index_already_exists"

DELETE {{base_url}}/api/config/table/us/indexes/{{idx_dup_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 30s-neg. Delete non-existent id → error ──────────────────────────
DELETE {{base_url}}/api/config/table/us/indexes/999999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_found"


# ── 30t-neg. Delete id belonging to different table → error ──────────
# First create an index on "complessi", then try to delete it as if it belongs to "us".
POST {{base_url}}/api/config/table/complessi/indexes
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "idx_complessi_nome", "columns": ["nome"] }
HTTP 200
[Captures]
idx_wrong_tb_id: jsonpath "$.id"

DELETE {{base_url}}/api/config/table/us/indexes/{{idx_wrong_tb_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_found"

# Clean up — delete from the correct table
DELETE {{base_url}}/api/config/table/complessi/indexes/{{idx_wrong_tb_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
