# ════════════════════════════════════════════════════════════════════
# Phase 9 — Cleanup: delete tables, then the app is removed by run.sh
# ════════════════════════════════════════════════════════════════════

# ── 9a. Logout (invalidates the token client-side) ───────────────────
GET {{base_url}}/api/auth/logout
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
