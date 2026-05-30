# ════════════════════════════════════════════════════════════════════
# Phase 1 — Create the test application
#
# Requires BRADYPUS_ALLOW_NEW_APP=1 in the server environment, or
# no pre-existing apps in projects/.
# ════════════════════════════════════════════════════════════════════

# 1a. Check that app creation is permitted before attempting it.
GET {{base_url}}/api/new-app/status
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.permitted" == true

# 1b. Create the app (engine is controlled by the --db flag in test.sh).
POST {{base_url}}/api/new-app
Content-Type: application/json
{
  "name":        "{{app_name}}",
  "definition":  "BraDypUS API test suite — auto-generated, safe to delete",
  "email":       "{{admin_email}}",
  "password":    "{{admin_password}}",
  "db_engine":   "{{db_engine}}",
  "db_host":     "{{db_host}}",
  "db_port":     "{{db_port}}",
  "db_name":     "{{db_name}}",
  "db_username": "{{db_username}}",
  "db_password": "{{db_password}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_app_created"
