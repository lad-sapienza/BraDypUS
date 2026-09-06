# ════════════════════════════════════════════════════════════════════
# Phase 42 — App-scoped API surface (/{app}/api/…)
#
# v5.9.0 contract:
#   - application-scoped endpoints ONLY at /{app}/api/…  (incl. all of
#     /api/auth/* except apps: login, register, password-reset, refresh,
#     logout, oauth)
#   - app-independent endpoints ONLY at bare /api/…  — just
#     /api/auth/apps, /api/new-app, /api/new-app/status, /api/info
#   - a bare /api/… hit on an app-scoped route → 404 app_prefix_required
#   - a /{app}/api/… hit on an app-independent route → 404 app_prefix_not_allowed
#   - URL app ≠ JWT app → 403 app_mismatch
#
# Runs after phase 41, before cleanup (10): crud_test still exists (T001, T002).
# ════════════════════════════════════════════════════════════════════


# ── 42a. App-scoped GET works with the /{app} prefix ──────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 42b. Same endpoint without the prefix → 404 app_prefix_required ───
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{jwt}}
HTTP 404
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.status" == "error"
jsonpath "$.code"   == "app_prefix_required"


# ── 42c. App-scoped config endpoint with the prefix ──────────────────
GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 42d. App-independent endpoint stays reachable at bare /api/… ─────
GET {{base_url}}/api/auth/apps
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.apps"   isCollection


# ── 42e. App-independent endpoint WITH a prefix → 404 not_allowed ────
GET {{base_url}}/{{app_name}}/api/auth/apps
HTTP 404
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.status" == "error"
jsonpath "$.code"   == "app_prefix_not_allowed"


# ── 42f. URL app ≠ JWT app → 403 app_mismatch ───────────────────────
GET {{base_url}}/__wrong_app__/api/records/crud_test
Authorization: Bearer {{jwt}}
HTTP 403
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.status" == "error"
jsonpath "$.code"   == "app_mismatch"


# ── 42g. Export is app-scoped too ───────────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "text/csv"
body contains "T001"


# ── 42h. Login IS app-scoped: /{app}/api/auth/login reaches the controller ──
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": "wrong-on-purpose"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "login_data_not_valid"


# ── 42i. …and the bare form is gone → 404 app_prefix_required ───────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": "{{admin_password}}"
}
HTTP 404
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "app_prefix_required"
