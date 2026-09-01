# ════════════════════════════════════════════════════════════════════
# Phase 29 — Edit history + confirm-admin-password
#
# Routes exercised:
#   GET  /api/history                     paginated version history
#   POST /api/admin/check-password        super-admin password gate
# ════════════════════════════════════════════════════════════════════


# ── 29a. GET history — shape check ───────────────────────────────────
GET {{base_url}}/api/history
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  isInteger
jsonpath "$.data"   isCollection


# ── 29b. GET history — row shape ─────────────────────────────────────
GET {{base_url}}/api/history?page=1&per_page=5
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"     == "success"
jsonpath "$.total"      isInteger


# ── 29c. GET history filtered by table ───────────────────────────────
GET {{base_url}}/api/history?tb=us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  isInteger
jsonpath "$.data"   isCollection


# ── 29d. check-password — correct password ───────────────────────────
POST {{base_url}}/api/admin/check-password
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "pwd": "{{admin_password}}" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "valid_pasword"


# ── 29e. check-password — wrong password ─────────────────────────────
POST {{base_url}}/api/admin/check-password
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "pwd": "definitely_wrong_password_xyz" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_pasword"


# ── 29f. check-password — empty pwd → invalid ────────────────────────
POST {{base_url}}/api/admin/check-password
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "pwd": "" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_pasword"
