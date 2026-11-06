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
#   PUT  /api/config/table/{tb}/active              (48f: deactivate → reactivate → remove
#   DELETE /api/config/table/{tb}[?keep_data=1]      from config → activate → delete)
#   POST /api/free-sql/run, POST /api/config/table/{tb}/view-writable   (48g: a view, end to end)
# ════════════════════════════════════════════════════════════════════


# ── 48a. List: succeeds, never exposes system tables ──────────────────
GET {{base_url}}/{{app_name}}/api/config/db-objects
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.objects" isCollection
jsonpath "$.objects[?(@.name=='bdus_users')]" not exists
jsonpath "$.objects[?(@.name=='bdus_cfg_tables')]" not exists
# configured tables are not offered again
jsonpath "$.objects[?(@.name=='crud_test')]" not exists


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


# ── 48f. Life cycle: deactivate → reactivate → remove from config → activate → delete ─
POST {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tmp_lifecycle", "label": "Lifecycle", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# soft: configuration kept, table behaves as if it were not there
PUT {{base_url}}/{{app_name}}/api/config/table/tmp_lifecycle/active
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "active": false }
HTTP 200
[Asserts]
jsonpath "$.code" == "table_deactivated"

GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[?(@.name=='tmp_lifecycle')]" not exists

GET {{base_url}}/{{app_name}}/api/config/db-objects
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.objects[?(@.name=='tmp_lifecycle')]" exists
jsonpath "$.objects[?(@.name=='tmp_lifecycle' && @.inactive==true)]" exists

# the name is taken while it is only switched off
POST {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tmp_lifecycle", "label": "Again", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.code" == "tb_already_available"

# reactivation brings the saved configuration back
POST {{base_url}}/{{app_name}}/api/config/db-objects/tmp_lifecycle/activate
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "table_reactivated"

GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[?(@.name=='tmp_lifecycle')]" exists

# hard: configuration removed, table stays and can be activated again
DELETE {{base_url}}/{{app_name}}/api/config/table/tmp_lifecycle?keep_data=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/config/db-objects
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.objects[?(@.name=='tmp_lifecycle' && @.inactive==false)]" exists
jsonpath "$.objects[?(@.name=='tmp_lifecycle' && @.activatable==true)]" exists

POST {{base_url}}/{{app_name}}/api/config/db-objects/tmp_lifecycle/activate
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.kind"   == "table"

# delete: the table is dropped
DELETE {{base_url}}/{{app_name}}/api/config/table/tmp_lifecycle
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/config/db-objects
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.objects[?(@.name=='tmp_lifecycle')]" not exists


# ── 48g. Run SQL is where views are created; BraDypUS then registers them ────
# (on MySQL/MariaDB a CREATE VIEW commits by itself: Run SQL must still say success)
POST {{base_url}}/{{app_name}}/api/free-sql/run
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "sql": "CREATE VIEW hurl_view_sql AS SELECT id, creator, sigla, tipo FROM crud_test" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_free_sql_run"

GET {{base_url}}/{{app_name}}/api/config/db-objects
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.objects[?(@.name=='hurl_view_sql' && @.kind=='view' && @.activatable==true)]" exists

POST {{base_url}}/{{app_name}}/api/config/db-objects/hurl_view_sql/activate
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.kind"   == "view"

# a registered view cannot be altered from the Config panel
POST {{base_url}}/{{app_name}}/api/config/table/hurl_view_sql/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "extra_col", "label": "Extra", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.code" == "view_not_alterable"

# the writability can be re-read (no change: nothing happened to the view)
POST {{base_url}}/{{app_name}}/api/config/table/hurl_view_sql/view-writable
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.changed" == false

# remove it from the configuration (a view is never dropped by BraDypUS), then drop it ourselves
DELETE {{base_url}}/{{app_name}}/api/config/table/hurl_view_sql
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/free-sql/run
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "sql": "DROP VIEW hurl_view_sql" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 48e. No token → no access ─────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/config/db-objects
HTTP 401
