# ════════════════════════════════════════════════════════════════════
# Phase 4 — Record CRUD
#
# All CRUD operations run against the dedicated crud_test table
# (created in phase 3n) so the archaeological tables remain empty
# and demo-seed ID predictions stay reliable.
#
# crud_test has all field types:
#   text (sigla/tipo/periodo), long_text, boolean, date, integer,
#   select, combo_select, multi_select, id_from_tb (self), email,
#   max_length, slider.
#
# Captures exported to run.sh:
#   us_id_1, us_id_2  (IDs used by phases 05/20/28)
# ════════════════════════════════════════════════════════════════════


# ── 4a. Fetch empty record skeleton ─────────────────────────────────
GET {{base_url}}/api/record/crud_test/new
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.core"   isCollection
# New fields present in skeleton
jsonpath "$.core.data_rec"       isCollection
jsonpath "$.core.punteggio"      isCollection
jsonpath "$.core.categoria"      isCollection
jsonpath "$.core.periodo_libero" isCollection
jsonpath "$.core.etichette"      isCollection
jsonpath "$.core.parent_id"      isCollection
jsonpath "$.core.email_ref"      isCollection
jsonpath "$.core.codice_breve"   isCollection
jsonpath "$.core.valore_slider"  isCollection
# Plugin schema present
jsonpath "$.schema.plugins"         isCollection
jsonpath "$.schema.has_fuzzy_date"  == false


# ── 4b. Insert record 1 — basic fields ──────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":       "T001",
    "descrizione": "Strato di humus superficiale",
    "tipo":        "Strato",
    "periodo":     "Età contemporanea",
    "verificata":  1
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


# ── 4c. Insert record 2 — with id_from_tb (parent_id = record 1) ────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":     "T002",
    "tipo":      "Strato",
    "periodo":   "Basso Medioevo",
    "parent_id": {{us_id_1}}
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.id"     isInteger
[Captures]
us_id_2: jsonpath "$.id"


# ── 4d. Insert record 3 (will be deleted) ───────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":   "T003",
    "tipo":    "Taglio",
    "periodo": "Età del Bronzo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_3: jsonpath "$.id"


# ── 4e. Read record 1 — verify basic shape ───────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"             == "success"
jsonpath "$.core.sigla.val"     == "T001"
jsonpath "$.core.verificata.val" == 1
jsonpath "$.metadata.can_edit"  == true
jsonpath "$.schema.rs_field"    isString
jsonpath "$.schema.has_geodata" == true
jsonpath "$.schema.has_zotero"  == true
jsonpath "$.geodata"            isCollection
jsonpath "$.bibliography"       isCollection
# plugin section present and empty
jsonpath "$.plugins.crud_test_plg" isCollection


# ── 4f. Read record 2 — verify id_from_tb field value ───────────────
GET {{base_url}}/api/record/crud_test/{{us_id_2}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.sigla.val"     == "T002"
jsonpath "$.core.parent_id.val" == {{us_id_1}}
# val_label is null: self-reference JOIN is skipped by design (getTbRecord guard)


# ── 4g. Update record 1 — set ALL new field types ────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{us_id_1}},
  "core": {
    "descrizione":    "Strato di formazione moderna (aggiornato)",
    "data_rec":       "2024-06-15",
    "punteggio":      75,
    "categoria":      "Tipo B",
    "periodo_libero": "Ellenistico",
    "etichette":      "A;B",
    "email_ref":      "test@example.com",
    "codice_breve":   "CT001",
    "valore_slider":  7
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "success_saved"
jsonpath "$.id"     == {{us_id_1}}


# ── 4h. Read record 1 — verify all field types ───────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.descrizione.val"    == "Strato di formazione moderna (aggiornato)"
jsonpath "$.core.data_rec.val"       == "2024-06-15"
jsonpath "$.core.punteggio.val"      == 75
jsonpath "$.core.categoria.val"      == "Tipo B"
jsonpath "$.core.periodo_libero.val" == "Ellenistico"
jsonpath "$.core.etichette.val"      == "A;B"
jsonpath "$.core.email_ref.val"      == "test@example.com"
jsonpath "$.core.codice_breve.val"   == "CT001"
jsonpath "$.core.valore_slider.val"  == 7


# ── 4i. Record list ──────────────────────────────────────────────────
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


# ── 4n. check-unique — self-exclusion (UPDATE) → unique: true ────────
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


# ══════════════════════════════════════════════════════════════════════
# Negative validation tests (server-side validatePayload)
# ══════════════════════════════════════════════════════════════════════

# ── 4q-neg. Empty required field (sigla is not_empty) ────────────────
# Note: omitting the field entirely bypasses server validation (only fields
# present in the payload are validated). Send an empty string to trigger it.
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "", "tipo": "Strato" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status"          == "error"
jsonpath "$.code"            == "validation_failed"
jsonpath "$.errors"          isCollection
jsonpath "$.errors[0].field" == "sigla"
jsonpath "$.errors[0].rule"  == "required"


# ── 4r-neg. Duplicate sigla (no_dupl) ────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "T001", "tipo": "Strato" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status"          == "error"
jsonpath "$.code"            == "validation_failed"
jsonpath "$.errors[0].field" == "sigla"
jsonpath "$.errors[0].rule"  == "no_dupl"


# ── 4s-neg. Sigla fails regex (^[A-Z]{1,3}\\d{3,}$) ─────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "bad-sigla!" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status"          == "error"
jsonpath "$.code"            == "validation_failed"
jsonpath "$.errors[0].field" == "sigla"
jsonpath "$.errors[0].rule"  == "pattern"


# ── 4t-neg. Invalid email ────────────────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "T999", "email_ref": "not-an-email" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status"          == "error"
jsonpath "$.code"            == "validation_failed"
jsonpath "$.errors[0].field" == "email_ref"
jsonpath "$.errors[0].rule"  == "email"


# ── 4u-neg. Punteggio out of max range (> 100) ────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "T999", "punteggio": 999 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status"          == "error"
jsonpath "$.code"            == "validation_failed"
jsonpath "$.errors[0].field" == "punteggio"
jsonpath "$.errors[0].rule"  == "max"


# ── 4v-neg. Codice breve exceeds max_length (> 10 chars) ─────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "T999", "codice_breve": "TOOLONGCODE123" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status"          == "error"
jsonpath "$.code"            == "validation_failed"
jsonpath "$.errors[0].field" == "codice_breve"
jsonpath "$.errors[0].rule"  == "max_length"


# ── 4w-neg. Punteggio not integer (float) ─────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "T999", "punteggio": 3.14 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status"          == "error"
jsonpath "$.code"            == "validation_failed"
jsonpath "$.errors[0].field" == "punteggio"
jsonpath "$.errors[0].rule"  == "int"


# ── 4x-neg. Date out of range (before min 1000-01-01) ─────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "T999", "data_rec": "0500-01-01" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status"          == "error"
jsonpath "$.code"            == "validation_failed"
jsonpath "$.errors[0].field" == "data_rec"
jsonpath "$.errors[0].rule"  == "min"
