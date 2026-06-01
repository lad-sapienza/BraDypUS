# ════════════════════════════════════════════════════════════════════
# Phase 20 — Fuzzy-date plugin (end-to-end)
#
# Tests the full lifecycle:
#   activate → save records with chrono values → fetch and verify
#   → deactivate (data preserved) → re-activate (data intact)
#
# Privilege enforcement is covered by ChronoPluginCtrlTest (PHPUnit).
# Depends on: phase 03 (us table created), phase 02 (jwt captured).
# ════════════════════════════════════════════════════════════════════


# ── 20a. Table config shows fuzzy_date=false before activation ───────
GET {{base_url}}/api/config/table/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.table.fuzzy_date" not exists


# ── 20b-neg. Unknown table returns error ─────────────────────────────
POST {{base_url}}/api/config/table/__no_such_table__/fuzzy-date
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"


# ── 20c. Activate plugin on us table ─────────────────────────────────
POST {{base_url}}/api/config/table/crud_test/fuzzy-date
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "fuzzy_date_activated"


# ── 20d. Record schema now shows has_fuzzy_date=true ─────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.schema.has_fuzzy_date" == true


# ── 20e. Activation is idempotent ────────────────────────────────────
POST {{base_url}}/api/config/table/crud_test/fuzzy-date
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "fuzzy_date_activated"


# ── 20f. Save a record with a full chrono range ───────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": null,
  "core": {
    "sigla":            "US099",
    "chrono_from":      -325,
    "chrono_to":        -301,
    "chrono_label":     "Late 4th cent. BCE",
    "chrono_certainty": "probable",
    "chrono_period":    "Hellenistic"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.id"     isInteger
[Captures]
chrono_record_id: jsonpath "$.id"


# ── 20g. Fetch back and verify all chrono values ──────────────────────
GET {{base_url}}/api/record/crud_test/{{chrono_record_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.chrono_from.val"      == -325
jsonpath "$.core.chrono_to.val"        == -301
jsonpath "$.core.chrono_label.val"     == "Late 4th cent. BCE"
jsonpath "$.core.chrono_certainty.val" == "probable"
jsonpath "$.core.chrono_period.val"    == "Hellenistic"


# ── 20h. Save ante-quem record (from=null) ───────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": null,
  "core": {
    "sigla":        "US100",
    "chrono_from":  null,
    "chrono_to":    -300,
    "chrono_label": "Ante quem: 4th cent. BCE"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
ante_quem_id: jsonpath "$.id"


# ── 20i. Fetch ante-quem: from must be null ───────────────────────────
GET {{base_url}}/api/record/crud_test/{{ante_quem_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.chrono_from.val" == null
jsonpath "$.core.chrono_to.val"   == -300


# ── 20j. Save post-quem record (to=null) ─────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": null,
  "core": {
    "sigla":        "US101",
    "chrono_from":  -400,
    "chrono_to":    null,
    "chrono_label": "Post quem: 4th cent. BCE"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
post_quem_id: jsonpath "$.id"


# ── 20k. Fetch post-quem: to must be null ────────────────────────────
GET {{base_url}}/api/record/crud_test/{{post_quem_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.chrono_from.val" == -400
jsonpath "$.core.chrono_to.val"   == null


# ── 20l. Deactivate: config flag cleared ─────────────────────────────
DELETE {{base_url}}/api/config/table/crud_test/fuzzy-date
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "fuzzy_date_deactivated"


# ── 20m. Schema shows has_fuzzy_date=false after deactivation ─────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.schema.has_fuzzy_date" == false


# ── 20n. Re-activate: saved data still intact ────────────────────────
POST {{base_url}}/api/config/table/crud_test/fuzzy-date
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/api/record/crud_test/{{chrono_record_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.chrono_label.val" == "Late 4th cent. BCE"
jsonpath "$.core.chrono_from.val"  == -325


# ── 20o. RS matrix: has_fuzzy_date=true + chrono fields in nodes ─────
# (us table has fuzzy_date active since step 20d, and US099 has chrono_from=-325)
GET {{base_url}}/api/rs/matrix
Authorization: Bearer {{jwt}}
[QueryStringParams]
tb: crud_test
HTTP 200
[Asserts]
jsonpath "$.status"         == "success"
jsonpath "$.has_fuzzy_date" == true
jsonpath "$.nodes"          isCollection
# US099 (chrono_record_id) has from=-325; verify it appears with chrono data
jsonpath "$.nodes[*].chrono_from"  contains -325
jsonpath "$.nodes[*].chrono_to"    contains -301
