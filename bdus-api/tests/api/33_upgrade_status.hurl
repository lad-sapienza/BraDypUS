# ════════════════════════════════════════════════════════════════════
# Phase 33 — Upgrade status endpoint
#
# Tests GET /api/upgrade/status
# Tests POST /api/upgrade/minor (with admin JWT)
#
# For the test app, all migrations are already applied, so:
#   - status returns type: null
#   - runMinor returns "upgrade_complete" with empty applied list
# ════════════════════════════════════════════════════════════════════


# ── 33a. listApps reports upgrade:null for an up-to-date v5 app ─────────
GET {{base_url}}/api/auth/apps
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.apps"            isCollection
# The test app should exist and report no upgrade needed
jsonpath "$.apps[0].upgrade" == null


# ── 33b. Upgrade status endpoint — no auth, type=null for up-to-date app ──
GET {{base_url}}/api/upgrade/status?app={{app}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.type"   == null


# ── 33c. Minor upgrade — admin JWT, no pending → upgrade_complete ────────
POST {{base_url}}/api/upgrade/minor
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.code"     == "upgrade_complete"
jsonpath "$.applied"  isCollection


# ── 33d. Status check after minor run — still null ───────────────────────
GET {{base_url}}/api/upgrade/status?app={{app}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.type"   == null


# ── 33e. runMajor while no major upgrade needed returns error ────────────
POST {{base_url}}/api/upgrade/major
Content-Type: application/json
{
  "app":      "{{app}}",
  "email":    "admin@example.com",
  "password": "adminpassword"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "no_major_upgrade_needed"
