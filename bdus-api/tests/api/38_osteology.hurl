# ════════════════════════════════════════════════════════════════════
# Phase 38 — Osteology plugin API
#
# Routes exercised:
#   POST   /api/config/table/{tb}/osteology   — activate
#   DELETE /api/config/table/{tb}/osteology   — deactivate
#   GET    /api/record/crud_test/{id}          — has_osteology in schema
#   POST   /api/record/crud_test              — create record with osteo_data
#
# Requires: phase 03 setup (crud_test table exists)
# Requires: phase 19 seed (sepolture table with demo data)
#
# Tables used:
#   crud_test  — activate/deactivate/round-trip osteo_data
#   sepolture  — verify demo seed (from phase 19o)
# ════════════════════════════════════════════════════════════════════


# ── 38a. Activate on non-existent table → error code ─────────────
POST {{base_url}}/api/config/table/__no_such_table__/osteology
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "missing_table"


# ── 38b. Activate osteology on crud_test ─────────────────────────
POST {{base_url}}/api/config/table/crud_test/osteology
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "osteology_activated"

# Re-activating is idempotent (column already exists)
POST {{base_url}}/api/config/table/crud_test/osteology
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "osteology_activated"


# ── 38c. New-record response includes has_osteology = true ────────
GET {{base_url}}/api/record/crud_test/new
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"               == "success"
jsonpath "$.schema.has_osteology" == true


# ── 38d. Round-trip: create record with osteo_data ────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "OST001",
    "osteo_data": "{\"individuals\":[{\"id\":1,\"label\":\"Test\",\"notes\":\"round-trip\",\"bones\":{\"cranium\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\"},\"femur_right\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"probable\",\"laterality_certainty\":\"certain\"},\"fibula_left\":{\"present\":false}}}]}"
  },
  "plugins": {}
}
HTTP 200
[Captures]
osteo_rec_id: jsonpath "$.id"
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/api/record/crud_test/{{osteo_rec_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"                                                == "success"
jsonpath "$.core.osteo_data.val"                                   isString
jsonpath "$.schema.has_osteology"                                  == true


# ── 38e. Deactivate osteology ─────────────────────────────────────
DELETE {{base_url}}/api/config/table/crud_test/osteology
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "osteology_deactivated"

# New-record response now shows has_osteology = false
GET {{base_url}}/api/record/crud_test/new
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"               == "success"
jsonpath "$.schema.has_osteology" == false

# Re-deactivating is also idempotent
DELETE {{base_url}}/api/config/table/crud_test/osteology
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "osteology_deactivated"


# Note: demo data verification for sepolture (has_osteology, osteo_data
# round-trip) is covered inline in phase 19o via per-insert asserts.
# The phase 38 scope is limited to crud_test so it runs independently
# of the seed (which executes after run_tests in the standard flow).
