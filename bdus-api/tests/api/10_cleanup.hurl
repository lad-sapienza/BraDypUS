# ════════════════════════════════════════════════════════════════════
# Phase 10 — Cleanup
#
# 1. Drop the crud_test table (schema + SQL table) so the app is
#    clean for a subsequent demo seed run.
# 2. Logout.
# ════════════════════════════════════════════════════════════════════

# ── 10a. Drop crud_test table ─────────────────────────────────────────
DELETE {{base_url}}/api/config/table/crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 10b. Logout (invalidates the token client-side) ───────────────────
GET {{base_url}}/api/auth/logout
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
