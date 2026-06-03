# ════════════════════════════════════════════════════════════════════
# Phase 2 — Authenticate and capture the JWT
#
# Negative cases are verified first; the successful login follows and
# its token is captured for all subsequent phases by run.sh.
# ════════════════════════════════════════════════════════════════════

# ── 2a. Non-existent app → app_not_found ────────────────────────────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": "anypassword",
  "app":      "__nonexistent_app__"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "app_not_found"


# ── 2b. Invalid email format → email_password_needed ────────────────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "not-an-email",
  "password": "doesntmatter",
  "app":      "{{app_name}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "email_password_needed"


# ── 2b. Empty password → email_password_needed ──────────────────────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": "",
  "app":      "{{app_name}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "email_password_needed"


# ── 2c. Valid email format, wrong password → login_data_not_valid ────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": "DefinitelyWrongPassword!999",
  "app":      "{{app_name}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "login_data_not_valid"


# ── 2d. Non-existent user → login_data_not_valid ─────────────────────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "nobody@nowhere.invalid",
  "password": "anypassword",
  "app":      "{{app_name}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "login_data_not_valid"


# ── 2e. Valid credentials → JWT issued ──────────────────────────────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "{{admin_email}}",
  "password": "{{admin_password}}",
  "app":      "{{app_name}}"
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
POST {{base_url}}/api/upgrade/minor
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "upgrade_complete"


# ── 2g. Refresh endpoint returns a fresh token ──────────────────────
GET {{base_url}}/api/auth/refresh
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.token"  isString


# ── 2g. App info is reachable with the token ────────────────────────
GET {{base_url}}/api/info/app
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
