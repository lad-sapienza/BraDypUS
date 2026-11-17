# ════════════════════════════════════════════════════════════════════
# Phase 10 — Cleanup
#
# Runs LAST (after all correctness phases, before the demo seed).
# 1. Switch crud_test_plg and crud_test off (an active table is not dropped)
# 2. Drop crud_test_plg (plugin table — must go before parent)
# 3. Drop crud_test (schema + SQL table)
# 4. Logout
#
# After this phase the app contains only the archaeological tables
# (siti, us, saggi, reperti, complessi + plugins) with no records,
# so the demo seed (phase 19) gets predictable auto-increment IDs.
# ════════════════════════════════════════════════════════════════════


# A table that is part of the configuration cannot be dropped (`table_active`):
# switch it off first. The plugin goes before its parent.
# ── 10a-0. Plugin and parent are refused while they are on ────────────
DELETE {{base_url}}/{{app_name}}/api/config/table/crud_test_plg
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "table_active"

# The system plugin of phase 39 is attached again at the end of that phase: detach it
# (its table and data stay, as before) so that crud_test has no plugin on.
DELETE {{base_url}}/{{app_name}}/api/config/table/crud_test/radiocarbon
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/crud_test_plg/active
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "active": false }
HTTP 200
[Asserts]
jsonpath "$.code" == "table_deactivated"

PUT {{base_url}}/{{app_name}}/api/config/table/crud_test/active
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "active": false }
HTTP 200
[Asserts]
jsonpath "$.code" == "table_deactivated"


# ── 10a. Drop crud_test_plg (plugin must be removed before parent) ────
DELETE {{base_url}}/{{app_name}}/api/config/table/crud_test_plg
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 10b. Drop crud_test ───────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/config/table/crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 10c. Verify both tables are gone ─────────────────────────────────
GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[*].name" not contains "crud_test"
jsonpath "$.tables[*].name" not contains "crud_test_plg"


# ── 10d. Logout ───────────────────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/auth/logout
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
