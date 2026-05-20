# ════════════════════════════════════════════════════════════════════
# Phase 2 — Authenticate and capture the JWT
#
# The token is captured here and injected into all subsequent phases
# by run.sh via --variable jwt=<value>.
# ════════════════════════════════════════════════════════════════════

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


# 2b. The refresh endpoint must return a fresh token.
GET {{base_url}}/api/auth/refresh
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.token"  isString


# 2c. App info is reachable with the token.
GET {{base_url}}/api/info/app
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
