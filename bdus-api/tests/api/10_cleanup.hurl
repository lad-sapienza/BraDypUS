# ════════════════════════════════════════════════════════════════════
# Phase 10 — Cleanup
#
# Runs LAST (after all correctness phases, before the demo seed).
# 1. Drop crud_test_plg (plugin table — must go before parent)
# 2. Drop crud_test (schema + SQL table)
# 3. Logout
#
# After this phase the app contains only the archaeological tables
# (siti, us, saggi, reperti, complessi + plugins) with no records,
# so the demo seed (phase 19) gets predictable auto-increment IDs.
# ════════════════════════════════════════════════════════════════════


# ── 10a. Drop crud_test_plg (plugin must be removed before parent) ────
DELETE {{base_url}}/api/config/table/crud_test_plg
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 10b. Drop crud_test ───────────────────────────────────────────────
DELETE {{base_url}}/api/config/table/crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 10c. Verify both tables are gone ─────────────────────────────────
GET {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[*].name" not contains "crud_test"
jsonpath "$.tables[*].name" not contains "crud_test_plg"


# ── 10d. Logout ───────────────────────────────────────────────────────
GET {{base_url}}/api/auth/logout
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
