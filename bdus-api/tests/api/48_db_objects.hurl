# ════════════════════════════════════════════════════════════════════
# Phase 48 — Database objects not in the configuration (#53)
#
# Tables and views that exist in the database but are not configured can be
# listed, previewed and activated. A real table or view cannot be created from
# hurl, so the full activation cycle (types, foreign keys, view flag, view SQL)
# is covered by PHPUnit (ActivateDbObjectTest) and by the engine test
# (InspectObjectsEngineTest, live PostgreSQL / MariaDB); this phase checks the
# routes, the privilege gate and the refusals through the real HTTP stack.
#
# Routes exercised:
#   GET  /api/config/db-objects
#   GET  /api/config/db-objects/{name}
#   POST /api/config/db-objects/{name}/activate
# ════════════════════════════════════════════════════════════════════


# ── 48a. List: succeeds, never exposes system tables ──────────────────
GET {{base_url}}/{{app_name}}/api/config/db-objects
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.objects" isCollection
jsonpath "$.objects[*].name" not includes "bdus_users"
jsonpath "$.objects[*].name" not includes "bdus_cfg_tables"
# configured tables are not offered again
jsonpath "$.objects[*].name" not includes "crud_test"


# ── 48b. Preview / activate of something that is not there ────────────
GET {{base_url}}/{{app_name}}/api/config/db-objects/no_such_object
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_found"

POST {{base_url}}/{{app_name}}/api/config/db-objects/no_such_object/activate
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_found"


# ── 48c. System tables are not reachable through this door ────────────
POST {{base_url}}/{{app_name}}/api/config/db-objects/bdus_users/activate
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_found"


# ── 48d. A table that is already configured is not an orphan ──────────
POST {{base_url}}/{{app_name}}/api/config/db-objects/crud_test/activate
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "already_configured"


# ── 48e. No token → no access ─────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/config/db-objects
HTTP 401
