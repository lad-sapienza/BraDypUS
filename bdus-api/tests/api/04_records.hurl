# ════════════════════════════════════════════════════════════════════
# Phase 4 — Record CRUD
#
# All CRUD operations run against the dedicated crud_test table
# (created in phase 3n) so the archaeological tables remain empty
# and demo-seed ID predictions stay reliable.
#
# Captures exported to run.sh:
#   us_id_1, us_id_2  (IDs of crud_test records — same variable names
#                      kept for backward compatibility with phases 05/20)
# ════════════════════════════════════════════════════════════════════

# ── 4a. Fetch empty record skeleton ─────────────────────────────────
GET {{base_url}}/api/record/crud_test/new
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core" isCollection


# ── 4b. Insert record 1 ─────────────────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":       "T001",
    "descrizione": "Strato di humus superficiale",
    "tipo":        "Strato",
    "periodo":     "Età contemporanea"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "success_created"
jsonpath "$.id"     isInteger
[Captures]
us_id_1: jsonpath "$.id"


# ── 4c. Insert record 2 ─────────────────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":       "T002",
    "descrizione": "Strato argilloso grigio-marrone",
    "tipo":        "Strato",
    "periodo":     "Basso Medioevo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.id"     isInteger
[Captures]
us_id_2: jsonpath "$.id"


# ── 4d. Insert record 3 (will be deleted later) ─────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":       "T003",
    "descrizione": "Buca di palo",
    "tipo":        "Taglio",
    "periodo":     "Età del Bronzo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_3: jsonpath "$.id"


# ── 4f. Read record 1 back ──────────────────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.sigla.val"       == "T001"
jsonpath "$.core.descrizione.val" isString
jsonpath "$.metadata.can_edit"    == true
# crud_test has rs, geodata, zotero enabled (set in phase 3n)
jsonpath "$.schema.rs_field"      isString
jsonpath "$.schema.has_geodata"   == true
jsonpath "$.schema.has_zotero"    == true
jsonpath "$.geodata"              isCollection
jsonpath "$.bibliography"         isCollection


# ── 4g. Update record 1 ─────────────────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{us_id_1}},
  "core": {
    "descrizione": "Strato di formazione moderna (aggiornato)"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "success_saved"
jsonpath "$.id"     == {{us_id_1}}


# ── 4h. Read record 1 — verify update ───────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.descrizione.val" == "Strato di formazione moderna (aggiornato)"


# ── 4i. Get record list ──────────────────────────────────────────────
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 3
jsonpath "$.data"   isCollection


# ── 4j. Delete record 3 ─────────────────────────────────────────────
DELETE {{base_url}}/api/record/crud_test/{{us_id_3}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "all_record_deleted"


# ── 4k. Verify record 3 is gone ─────────────────────────────────────
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == 2


# ── 4l. check-unique — value does not exist → unique: true ───────────
GET {{base_url}}/api/record/crud_test/check-unique?field=sigla&value=ZZ999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.unique" == true


# ── 4m. check-unique — value exists → unique: false ──────────────────
GET {{base_url}}/api/record/crud_test/check-unique?field=sigla&value=T001
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.unique" == false


# ── 4n. check-unique — same record excluded (UPDATE self) ────────────
GET {{base_url}}/api/record/crud_test/check-unique?field=sigla&value=T001&id={{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.unique" == true


# ── 4o. check-unique — missing params → error ────────────────────────
GET {{base_url}}/api/record/crud_test/check-unique?field=sigla
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 4p. check-unique — unknown field → error ─────────────────────────
GET {{base_url}}/api/record/crud_test/check-unique?field=nonexistent&value=x
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "field_not_found"
