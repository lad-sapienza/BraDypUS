# ════════════════════════════════════════════════════════════════════
# Phase 24 — Application logs
#
# Routes exercised:
#   GET  /api/logs         retrieve recent log entries
#   POST /api/logs/purge   delete entries older than N days
# ════════════════════════════════════════════════════════════════════


# ── 24a. GET logs — returns data array ───────────────────────────────
GET {{base_url}}/api/logs
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" isInteger
jsonpath "$.data"  isCollection


# ── 24b. GET logs with limit param ───────────────────────────────────
GET {{base_url}}/api/logs?limit=5
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" isInteger
jsonpath "$.data"  isCollection


# ── 24c. Purge with explicit days param (minimum 1 enforced) ─────────
POST {{base_url}}/api/logs/purge
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "days": 7 }
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.code"    == "log_purge_success"
jsonpath "$.deleted" isInteger
jsonpath "$.days"    == 7


# ── 24d. Purge with default days (no body) ───────────────────────────
POST {{base_url}}/api/logs/purge
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.code"    == "log_purge_success"
jsonpath "$.deleted" isInteger
jsonpath "$.days"    isInteger
