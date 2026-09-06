# ════════════════════════════════════════════════════════════════════
# Phase 2 — Authenticate and capture the JWT
#
# v5.9.0: the application is the first URL path segment —
# POST /{app}/api/auth/login  (no `app` in the body).
#
# Negative cases are verified first; the successful login follows and
# its token is captured for all subsequent phases by run.sh.
# ════════════════════════════════════════════════════════════════════

# ── 2a. Non-existent app in the path → app_not_found ────────────────
POST {{base_url}}/__nonexistent_app__/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": "anypassword"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "app_not_found"


# ── 2b. Invalid email format → email_password_needed ────────────────
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{
  "email":    "not-an-email",
  "password": "doesntmatter"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "email_password_needed"


# ── 2b. Empty password → email_password_needed ──────────────────────
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": ""
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "email_password_needed"


# ── 2c. Valid email format, wrong password → login_data_not_valid ────
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": "DefinitelyWrongPassword!999"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "login_data_not_valid"


# ── 2d. Non-existent user → login_data_not_valid ─────────────────────
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{
  "email":    "nobody@nowhere.invalid",
  "password": "anypassword"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "login_data_not_valid"


# ── 2d-bis. Legacy bare /api/auth/login → 404 app_prefix_required ───
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


# ── 2e. Valid credentials → JWT issued ──────────────────────────────
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": "{{admin_password}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.token"  isString
[Captures]
jwt: jsonpath "$.token"


# ── 2f. Apply pending migrations (idempotent — no-op on subsequent runs) ──
# A fresh app has all migrations pending; the admin must explicitly apply them
# before non-admin users can log in.  This mirrors the real-world upgrade flow.
POST {{base_url}}/{{app_name}}/api/upgrade/minor
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "upgrade_complete"


# ── 2g. Refresh endpoint returns a fresh token ──────────────────────
GET {{base_url}}/{{app_name}}/api/auth/refresh
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.token"  isString


# ── 2h. App info is reachable with the token ────────────────────────
GET {{base_url}}/{{app_name}}/api/info/app
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
