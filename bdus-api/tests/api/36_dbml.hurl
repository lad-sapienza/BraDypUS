# ════════════════════════════════════════════════════════════════════
# Phase 36 — DBML import/export
#
# Routes exercised:
#   GET    /api/config/dbml           export full app schema as .dbml file
#   POST   /api/config/dbml/preview   parse & validate DBML (dry-run)
#   POST   /api/config/dbml/apply     create tables from validated DBML
#   (refs → relations: 36k–36o; plugin flags: 36p–36q)
# ════════════════════════════════════════════════════════════════════


# ── 36a. Export — returns a .dbml file download ──────────────────────
GET {{base_url}}/{{app_name}}/api/config/dbml
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Disposition" contains ".dbml"
body contains "Table"


# ── 36b. Preview — valid DBML with a brand-new table ─────────────────
POST {{base_url}}/{{app_name}}/api/config/dbml/preview
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table hurl_test_a {\n  id integer [pk, increment]\n  name varchar\n  Note: 'bdus:label=Hurl Test A bdus:preview=id,name'\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"                       == "success"
jsonpath "$.preview.has_errors"           == false
jsonpath "$.preview.tables"               count == 1
jsonpath "$.preview.tables[0].name"       == "hurl_test_a"
jsonpath "$.preview.tables[0].label"      == "Hurl Test A"
jsonpath "$.preview.tables[0].errors"     isEmpty


# ── 36c. Preview — table that already exists → hard error ────────────
POST {{base_url}}/{{app_name}}/api/config/dbml/preview
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table us {\n  id integer [pk, increment]\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"                             == "success"
jsonpath "$.preview.has_errors"                 == true
jsonpath "$.preview.tables[0].errors[0].code"   == "table_already_exists"


# ── 36d. Preview — PK not named 'id' → hard error ────────────────────
POST {{base_url}}/{{app_name}}/api/config/dbml/preview
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table bad_pk_table {\n  uid integer [pk, increment]\n  name varchar\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"                             == "success"
jsonpath "$.preview.has_errors"                 == true
jsonpath "$.preview.tables[0].errors[0].code"   == "pk_must_be_id"
jsonpath "$.preview.tables[0].errors[0].field"  == "uid"


# ── 36e. Preview — missing 'id' field emits warning (not error) ───────
POST {{base_url}}/{{app_name}}/api/config/dbml/preview
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table no_id_tbl {\n  name varchar\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"                              == "success"
jsonpath "$.preview.has_errors"                  == false
jsonpath "$.preview.tables[0].warnings[0].code"  == "auto_add_id"


# ── 36f. Preview — vocabulary enum included ──────────────────────────
POST {{base_url}}/{{app_name}}/api/config/dbml/preview
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Enum hurl_status {\n  active\n  archived\n  // bdus:vocabulary\n}\n\nTable with_voc_tbl {\n  id integer [pk, increment]\n  status varchar [note: 'bdus:voc=hurl_status']\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"                              == "success"
jsonpath "$.preview.has_errors"                  == false
jsonpath "$.preview.vocabularies"                count == 1
jsonpath "$.preview.vocabularies[0].name"        == "hurl_status"
jsonpath "$.preview.vocabularies[0].values"      count == 2


# ── 36g. Preview — missing dbml body → dbml_empty ────────────────────
POST {{base_url}}/{{app_name}}/api/config/dbml/preview
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "dbml_empty"


# ── 36h. Apply — create a new table ──────────────────────────────────
POST {{base_url}}/{{app_name}}/api/config/dbml/apply
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table hurl_applied {\n  id integer [pk, increment]\n  title varchar\n  Note: 'bdus:label=Hurl Applied bdus:preview=id,title'\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"                  == "success"
jsonpath "$.result.created"          count == 1
jsonpath "$.result.created[0]"       == "hurl_applied"
jsonpath "$.result.skipped"          isEmpty


# ── 36i. Apply — table already exists (re-apply same DBML) → skipped ─
POST {{base_url}}/{{app_name}}/api/config/dbml/apply
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table hurl_applied {\n  id integer [pk, increment]\n  title varchar\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"                  == "success"
jsonpath "$.result.created"          isEmpty
jsonpath "$.result.skipped"          count == 1
jsonpath "$.result.skipped[0]"       == "hurl_applied"


# ── 36j. Apply — missing dbml body → dbml_empty ──────────────────────
POST {{base_url}}/{{app_name}}/api/config/dbml/apply
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "dbml_empty"


# ── 36k. Preview — a ref becomes a relation to create ────────────────
# The referred table is defined AFTER the one that refers to it.
POST {{base_url}}/{{app_name}}/api/config/dbml/preview
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table hurl_rel_find {\n  id integer [pk, increment]\n  site_id integer [ref: > hurl_rel_site.id, note: 'bdus:on_delete=CASCADE']\n  ghost_id integer [ref: > hurl_no_such_table.id]\n}\n\nTable hurl_rel_site {\n  id integer [pk, increment]\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"                                 == "success"
jsonpath "$.preview.has_errors"                     == false
jsonpath "$.preview.tables[0].relations"            count == 1
jsonpath "$.preview.tables[0].relations[0].from_col"  == "site_id"
jsonpath "$.preview.tables[0].relations[0].to_tb"     == "hurl_rel_site"
jsonpath "$.preview.tables[0].relations[0].on_delete" == "CASCADE"
jsonpath "$.preview.tables[0].relations[0].on_update" == "CASCADE"
jsonpath "$.preview.tables[0].warnings[?(@.code == 'ref_target_missing')].field" contains "ghost_id"


# ── 36l. Apply — tables and the relation are created ─────────────────
# The relation comes with its FOREIGN KEY: if the engine refused it the
# importer would report relation_skipped and leave no relation row.
POST {{base_url}}/{{app_name}}/api/config/dbml/apply
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table hurl_rel_find {\n  id integer [pk, increment]\n  site_id integer [ref: > hurl_rel_site.id, note: 'bdus:on_delete=CASCADE']\n}\n\nTable hurl_rel_site {\n  id integer [pk, increment]\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"            == "success"
jsonpath "$.result.created"    count == 2
jsonpath "$.result.skipped"    isEmpty


# ── 36m. The relation is listed in Config → Relations ────────────────
GET {{base_url}}/{{app_name}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[?(@.from_tb == 'hurl_rel_find' && @.from_col == 'site_id')].to_tb"      contains "hurl_rel_site"
jsonpath "$.data[?(@.from_tb == 'hurl_rel_find' && @.from_col == 'site_id')].on_delete"  contains "CASCADE"


# ── 36n. Export carries the ref and the non-default policy ───────────
GET {{base_url}}/{{app_name}}/api/config/dbml
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
body contains "ref: > hurl_rel_site.id"
body contains "bdus:on_delete=CASCADE"


# ── 36o. Re-applying the same DBML skips the tables and adds no relation ─
POST {{base_url}}/{{app_name}}/api/config/dbml/apply
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table hurl_rel_find {\n  id integer [pk, increment]\n  site_id integer [ref: > hurl_rel_site.id]\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"            == "success"
jsonpath "$.result.created"    isEmpty
jsonpath "$.result.skipped"    count == 1


# ── 36p. Apply — bdus:geodata / bdus:fuzzy_date / bdus:rs switch the plugins on ─
POST {{base_url}}/{{app_name}}/api/config/dbml/apply
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "dbml": "Table hurl_flags {\n  id integer [pk, increment]\n  name varchar\n  Note: 'bdus:label=Hurl Flags bdus:geodata=1 bdus:fuzzy_date=1 bdus:rs=1'\n}"
}
HTTP 200
[Asserts]
jsonpath "$.status"            == "success"
jsonpath "$.result.created"    count == 1
jsonpath "$.result.skipped"    isEmpty


# ── 36q. The flags are really in the config: the export writes them back ─
GET {{base_url}}/{{app_name}}/api/config/dbml
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
body matches "Table hurl_flags \\{[^}]*bdus:rs=1 bdus:geodata=1 bdus:fuzzy_date=1"
body matches "Table hurl_flags \\{[^}]*chrono_label"
