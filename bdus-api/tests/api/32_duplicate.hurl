# ════════════════════════════════════════════════════════════════════
# Phase 32 — Duplicate record
#
# Tests POST /api/record/{tb}/{id}/duplicate
#
# Uses us_id_1 (crud_test record T001) created in phase 4.
# Verifies:
#   - happy path: returns success + new id (integer, > 0)
#   - can_add flag present in getRecord metadata
#   - error for unknown table
#   - error for nonexistent record id
# ════════════════════════════════════════════════════════════════════


# ── 32a. Verify can_add is exposed in getRecord metadata ─────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"              == "success"
jsonpath "$.metadata.can_add"   == true


# ── 32b. Duplicate record — happy path ──────────────────────────
POST {{base_url}}/api/record/crud_test/{{us_id_1}}/duplicate
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "success_duplicated"
jsonpath "$.id"     isInteger
jsonpath "$.id"     > 0
[Captures]
dup_id: jsonpath "$.id"


# ── 32c. New record exists and has same sigla as original ────────
GET {{base_url}}/api/record/crud_test/{{dup_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"           == "success"
jsonpath "$.core.sigla.val"   == "T001"


# ── 32d. Error — unknown table ───────────────────────────────────
POST {{base_url}}/api/record/nonexistent_table/1/duplicate
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "unknown_table"


# ── 32e. Error — record not found ───────────────────────────────
POST {{base_url}}/api/record/crud_test/999999/duplicate
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "record_not_found"


# ── 32f. Clean up the duplicate ─────────────────────────────────
DELETE {{base_url}}/api/record/crud_test/{{dup_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
