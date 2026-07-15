# ════════════════════════════════════════════════════════════════════
# Phase 39 — Radiocarbon dating plugin API
#
# Routes exercised:
#   POST /api/config/table/{tb}/radiocarbon   — activate (creates {tb}_radiocarbon)
#   POST /api/record/crud_test                — save record with a plugin row
#   GET  /api/record/crud_test/{id}            — round-trip, cal_1s/cal_2s computed
#   GET  /api/records/crud_test                — filter by plugin-table field
#
# Unlike fuzzy-date/osteology (flag + columns on the core table), this
# plugin creates a genuine child table (table_link/id_link), so a record
# can carry more than one dating, and the calibrated range is filterable
# via the generic JSON filter plugin-subquery mechanism.
#
# Requires: phase 03 setup (crud_test table exists)
# ════════════════════════════════════════════════════════════════════


# ── 39a. Activate on non-existent table → error code ──────────────────
POST {{base_url}}/api/config/table/__no_such_table__/radiocarbon
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "missing_table"


# ── 39b. Activate radiocarbon on crud_test ─────────────────────────────
POST {{base_url}}/api/config/table/crud_test/radiocarbon
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "radiocarbon_activated"
jsonpath "$.tb"     == "crud_test_radiocarbon"

# Re-activating is idempotent (table/columns already exist)
POST {{base_url}}/api/config/table/crud_test/radiocarbon
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "radiocarbon_activated"


# ── 39c. Save a record with one radiocarbon dating ─────────────────────
# cal_1s_from/to are deliberately bogus client-side values — the server
# must recompute and overwrite them (see RadiocarbonCtrlTest PHPUnit for
# the equivalent, exact-value regression check).
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": { "sigla": "C14001" },
  "plugins": {
    "crud_test_radiocarbon": [
      {
        "id": null,
        "_isNew": true,
        "fields": {
          "lab_code": "Beta-999999",
          "bp": 3200,
          "bp_error": 40,
          "material": "charcoal",
          "cal_1s_from": 1,
          "cal_1s_to": 2
        }
      }
    ]
  }
}
HTTP 200
[Captures]
c14_rec_id: jsonpath "$.id"
[Asserts]
jsonpath "$.status" == "success"


GET {{base_url}}/api/record/crud_test/{{c14_rec_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"                                                     == "success"
jsonpath "$.plugins.crud_test_radiocarbon.data[0].lab_code.val"         == "Beta-999999"
jsonpath "$.plugins.crud_test_radiocarbon.data[0].bp.val"               == 3200
jsonpath "$.plugins.crud_test_radiocarbon.data[0].bp_error.val"         == 40
# Server-computed, not the bogus client values sent above.
jsonpath "$.plugins.crud_test_radiocarbon.data[0].cal_1s_from.val"      == 3386
jsonpath "$.plugins.crud_test_radiocarbon.data[0].cal_1s_to.val"        == 3450
jsonpath "$.plugins.crud_test_radiocarbon.data[0].cal_2s_from.val"      == 3278
jsonpath "$.plugins.crud_test_radiocarbon.data[0].cal_2s_to.val"        == 3486


# ── 39d. A record can carry a second, independent dating ───────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{c14_rec_id}},
  "core": {},
  "plugins": {
    "crud_test_radiocarbon": [
      {
        "id": null,
        "_isNew": true,
        "fields": { "lab_code": "Beta-1000000", "bp": 500, "bp_error": 30, "material": "bone collagen" }
      }
    ]
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/api/record/crud_test/{{c14_rec_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"                              == "success"
jsonpath "$.plugins.crud_test_radiocarbon.data"   count == 2


# ── 39e. Filter records by calibrated range (plugin-table subquery) ────
GET {{base_url}}/api/records/crud_test?filter[crud_test_radiocarbon][cal_2s_from][_lte]=3300&filter[crud_test_radiocarbon][cal_2s_to][_gte]=3300
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 1

# A range that cannot match any stored dating → no results.
GET {{base_url}}/api/records/crud_test?filter[crud_test_radiocarbon][cal_2s_from][_gte]=90000
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 0
