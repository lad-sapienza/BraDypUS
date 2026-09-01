# ════════════════════════════════════════════════════════════════════
# Phase 22 — Schema structural changes
#
# Tests every schema-modification operation on a temporary table so
# the main demo tables are never touched:
#
#   add table → add column → change column type → add second column →
#   add data → rename column → verify data → delete records →
#   delete column → rename table → verify under new name →
#   delete table → verify no config residues
#
# Routes exercised:
#   POST   /api/config/tables                     create table
#   POST   /api/config/table/{tb}/field           add column
#   PUT    /api/config/table/{tb}/field/{fld}     change column config/type
#   PATCH  /api/config/table/{tb}/field/{fld}     rename column  (?old_name=&new_name=)
#   DELETE /api/config/table/{tb}/field/{fld}     delete column
#   PATCH  /api/config/table/{tb}                 rename table   (?old_name=&new_name=)
#   DELETE /api/config/table/{tb}                 delete table
# ════════════════════════════════════════════════════════════════════


# ── 22a. Create temporary table ───────────────────────────────────────
POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tmp_struct_test", "label": "Temp structural test", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.tb"     == "tmp_struct_test"


# ── 22b. Add first column: col_a (text) ──────────────────────────────
POST {{base_url}}/api/config/table/tmp_struct_test/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "col_a", "label": "Column A", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.fld"    == "col_a"


# ── 22c. Change col_a type: text → textarea ───────────────────────────
PUT {{base_url}}/api/config/table/tmp_struct_test/field/col_a
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "col_a", "label": "Column A (long)", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# Verify type was updated
GET {{base_url}}/api/config/table/tmp_struct_test/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"           == "success"
jsonpath "$.fields.col_a"      isString


# ── 22d. Add second column: col_b (select with dic) ───────────────────
POST {{base_url}}/api/config/table/tmp_struct_test/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "col_b", "label": "Column B", "type": "select", "db_type": "TEXT",
  "dic": ["Alpha", "Beta", "Gamma"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.fld"    == "col_b"


# ── 22d-neg. Duplicate column name ───────────────────────────────────
POST {{base_url}}/api/config/table/tmp_struct_test/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "col_b", "label": "Duplicate", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "fld_already_available"


# ── 22e. Configure table (order, id_field, preview) ──────────────────
PUT {{base_url}}/api/config/table/tmp_struct_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "tmp_struct_test",
  "label":     "Temp structural test",
  "is_plugin": "0",
  "order":     "col_a",
  "id_field":  "id",
  "preview":   ["col_a"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 22f. Add data records ─────────────────────────────────────────────
POST {{base_url}}/api/record/tmp_struct_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "col_a": "First record",  "col_b": "Alpha" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
tmp_id_1: jsonpath "$.id"

POST {{base_url}}/api/record/tmp_struct_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "col_a": "Second record", "col_b": "Beta" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
tmp_id_2: jsonpath "$.id"

POST {{base_url}}/api/record/tmp_struct_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "col_a": "Third record",  "col_b": "Gamma" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
tmp_id_3: jsonpath "$.id"


# ── 22g. Rename col_b → col_b_renamed ────────────────────────────────
PATCH {{base_url}}/api/config/table/tmp_struct_test/field/col_b
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "new_name": "col_b_renamed" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_renaming_column"

# Verify new name appears, old name is gone
GET {{base_url}}/api/config/table/tmp_struct_test/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.fields.col_b_renamed" isString
jsonpath "$.fields.col_b"         not exists


# ── 22g-neg. Rename to existing column name ───────────────────────────
PATCH {{base_url}}/api/config/table/tmp_struct_test/field/col_b_renamed
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "new_name": "col_a" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "error_renaming_column"


# ── 22h. Verify data survives column rename ───────────────────────────
GET {{base_url}}/api/record/tmp_struct_test/{{tmp_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"                        == "success"
jsonpath "$.core.col_a.val"                == "First record"
jsonpath "$.core.col_b_renamed.val"        == "Alpha"


# ── 22i. Delete one data record ───────────────────────────────────────
DELETE {{base_url}}/api/record/tmp_struct_test/{{tmp_id_3}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# Verify deletion — record list now has 2 entries (not 3)
GET {{base_url}}/api/records/tmp_struct_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 2


# ── 22j. Delete column col_b_renamed ─────────────────────────────────
DELETE {{base_url}}/api/config/table/tmp_struct_test/field/col_b_renamed
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_cfg_column_delete"

# Verify column is gone from config
GET {{base_url}}/api/config/table/tmp_struct_test/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.fields.col_b_renamed" not exists

# Verify remaining records still readable (col_a survives)
GET {{base_url}}/api/record/tmp_struct_test/{{tmp_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.core.col_a.val"  == "First record"



# ── 22k. Rename table: tmp_struct_test → tmp_renamed ─────────────────
PATCH {{base_url}}/api/config/table/tmp_struct_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "new_name": "tmp_renamed" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_renaming_table"

# Verify new name in table list, old name absent
GET {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[*].name"   contains "tmp_renamed"
jsonpath "$.tables[*].name"   not contains "tmp_struct_test"


# ── 22k-neg. Rename to existing table name ───────────────────────────
PATCH {{base_url}}/api/config/table/tmp_renamed
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "new_name": "us" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "error_renaming_table"


# ── 22l. Verify data accessible under new table name ─────────────────
GET {{base_url}}/api/record/tmp_renamed/{{tmp_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"         == "success"
jsonpath "$.core.col_a.val" == "First record"


# ── 22m. Delete table ────────────────────────────────────────────────
DELETE {{base_url}}/api/config/table/tmp_renamed
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_cfg_tb_delete"


# ── 22n. Verify no config residues ───────────────────────────────────
# Table must not appear in table list
GET {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[*].name" not contains "tmp_renamed"
jsonpath "$.tables[*].name" not contains "tmp_struct_test"


