# ════════════════════════════════════════════════════════════════════
# Phase 4b — Plugin row CRUD
#
# Tests the full lifecycle of plugin rows (crud_test_plg) on a
# crud_test record. Uses us_id_1 (record T001) as parent.
#
# Captures exported to run.sh:
#   (none — all operations are self-contained)
# ════════════════════════════════════════════════════════════════════


# ── 4b-a. Read record 1 — plugins section is empty at start ──────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.plugins.crud_test_plg.data"     isCollection
jsonpath "$.plugins.crud_test_plg.metadata.tot" == 0


# ── 4b-b. Save record 1 with one new plugin row ──────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{us_id_1}},
  "core": {},
  "plugins": {
    "crud_test_plg": [
      {
        "id":      null,
        "_isNew":  true,
        "_delete": false,
        "fields": {
          "tipo_misura": "Peso",
          "valore":      120,
          "note_plg":    "Prima misura di test",
          "data_misura": "2024-03-10"
        }
      }
    ]
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "success_saved"


# ── 4b-c. Read back — verify plugin row appeared ─────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.plugins.crud_test_plg.metadata.tot" == 1
jsonpath "$.plugins.crud_test_plg.data"         isCollection
[Captures]
plg_row_id: jsonpath "$.plugins.crud_test_plg.data[0].id.val"


# ── 4b-d. Verify plugin row field values ─────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.plugins.crud_test_plg.data[0].tipo_misura.val" == "Peso"
jsonpath "$.plugins.crud_test_plg.data[0].valore.val"      == 120
jsonpath "$.plugins.crud_test_plg.data[0].note_plg.val"    == "Prima misura di test"
jsonpath "$.plugins.crud_test_plg.data[0].data_misura.val" == "2024-03-10"


# ── 4b-e. Add a second plugin row ────────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{us_id_1}},
  "core": {},
  "plugins": {
    "crud_test_plg": [
      {
        "id":      null,
        "_isNew":  true,
        "_delete": false,
        "fields": {
          "tipo_misura": "Lunghezza",
          "valore":      35
        }
      }
    ]
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 4b-f. Read back — two plugin rows ────────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.plugins.crud_test_plg.metadata.tot" == 2
[Captures]
plg_row_id_2: jsonpath "$.plugins.crud_test_plg.data[1].id.val"


# ── 4b-g. Update first row ───────────────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{us_id_1}},
  "core": {},
  "plugins": {
    "crud_test_plg": [
      {
        "id":      {{plg_row_id}},
        "_isNew":  false,
        "_delete": false,
        "fields": {
          "valore": 125,
          "note_plg": "Prima misura aggiornata"
        }
      }
    ]
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 4b-h. Verify update ──────────────────────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.plugins.crud_test_plg.data[0].valore.val"   == 125
jsonpath "$.plugins.crud_test_plg.data[0].note_plg.val" == "Prima misura aggiornata"


# ── 4b-i. Delete second row ──────────────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{us_id_1}},
  "core": {},
  "plugins": {
    "crud_test_plg": [
      {
        "id":      {{plg_row_id_2}},
        "_isNew":  false,
        "_delete": true,
        "fields":  {}
      }
    ]
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 4b-j. Verify only one row remains ────────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.plugins.crud_test_plg.metadata.tot" == 1
jsonpath "$.plugins.crud_test_plg.data[0].tipo_misura.val" == "Peso"


# ── 4b-neg. Plugin row with missing required field ────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{us_id_1}},
  "core": {},
  "plugins": {
    "crud_test_plg": [
      {
        "id":      null,
        "_isNew":  true,
        "_delete": false,
        "fields": {
          "tipo_misura": "Peso",
          "valore": null
        }
      }
    ]
  }
}
HTTP 200
[Asserts]
jsonpath "$.status"          == "error"
jsonpath "$.code"            == "validation_failed"
jsonpath "$.errors[0].field" == "valore"
jsonpath "$.errors[0].rule"  == "required"


# ════════════════════════════════════════════════════════════════════
# check-plugins-before-delete
# ════════════════════════════════════════════════════════════════════

# ── 4b-check-a. Record with plugin rows returns them in the list ─────
# us_id_1 (crud_test) has crud_test_plg rows created above.
# The config must declare crud_test_plg as plugin_of: crud_test for
# checkPluginsBeforeDelete to find it.
GET {{base_url}}/api/record/crud_test/check-plugins-before-delete?id={{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.plugins" isCollection


# ── 4b-check-b. Record with no plugin rows returns empty list ────────
# us_id_2 has no plugin rows.
GET {{base_url}}/api/record/crud_test/check-plugins-before-delete?id={{us_id_2}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.plugins" isCollection
jsonpath "$.plugins" count == 0


# ── 4b-check-c-neg. Missing id → error ──────────────────────────────
GET {{base_url}}/api/record/crud_test/check-plugins-before-delete
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"
