# ════════════════════════════════════════════════════════════════════
# Phase 59 — Move a user / API key from a data level to Enter + group, and back
#
# An admin moves a Writer (20) to level Enter in the Writer group: the same
# session keeps working with the same access. Restoring puts the level back and
# removes the group. The same for an API key. Only admins may, and only for
# levels 20/25/30.
# ════════════════════════════════════════════════════════════════════

GET {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
HTTP 200
[Captures]
g59_writer: jsonpath "$.groups[?(@.name=='Writer')].id"

# ── A user at level Writer ─────────────────────────────────
POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g59_user", "email": "g59_user@bdus.test", "password": "Parity_2025!x", "privilege": 20 }
HTTP 200
[Captures]
g59_id: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g59_user@bdus.test", "password": "Parity_2025!x", "app": "{{app_name}}" }
HTTP 200
[Captures]
g59_jwt: jsonpath "$.token"

GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{g59_jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[?(@.name=='crud_test')].can.update" == true

# a writer cannot move anyone
POST {{base_url}}/{{app_name}}/api/user/{{g59_id}}/move-to-group
Authorization: Bearer {{g59_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

# ── Move: same access, now through the group ───────────────
POST {{base_url}}/{{app_name}}/api/user/{{g59_id}}/move-to-group
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code" == "moved"
jsonpath "$.level" == 20
jsonpath "$.group" == "Writer"

GET {{base_url}}/{{app_name}}/api/user/{{g59_id}}/groups
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.groups" contains {{g59_writer}}

GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{g59_jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[?(@.name=='crud_test')].can.update" == true
jsonpath "$.tables[?(@.name=='crud_test')].can.delete" == true

# the second move has nothing to do: the user is no longer at a data level
POST {{base_url}}/{{app_name}}/api/user/{{g59_id}}/move-to-group
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code" == "not_data_level"

# ── Restore ────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/user/{{g59_id}}/restore-level
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code" == "restored"
jsonpath "$.level" == 20

GET {{base_url}}/{{app_name}}/api/user/{{g59_id}}/groups
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.groups" count == 0

POST {{base_url}}/{{app_name}}/api/user/{{g59_id}}/restore-level
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_moved"

POST {{base_url}}/{{app_name}}/api/user/999999/move-to-group
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "user_not_found"

# ── An API key ─────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "label": "g59_key", "privilege": 30 }
HTTP 200
[Captures]
g59_key: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/api-key/{{g59_key}}/move-to-group
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "moved"
jsonpath "$.group" == "Reader"

GET {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.keys[?(@.label=='g59_key')].privilege" == 39

POST {{base_url}}/{{app_name}}/api/api-key/{{g59_key}}/restore-level
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "restored"

GET {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.keys[?(@.label=='g59_key')].privilege" == 30

# ── Everyone at once: a dry run writes nothing ─────────────
POST {{base_url}}/{{app_name}}/api/levels/move-to-groups
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "dry_run": true }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.dry_run" == true
jsonpath "$.moved" isCollection
jsonpath "$.refused" isCollection

GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{g59_jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[?(@.name=='crud_test')].can.update" == true

POST {{base_url}}/{{app_name}}/api/levels/move-to-groups
Authorization: Bearer {{g59_jwt}}
Content-Type: application/json
{ "dry_run": true }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

# ── Cleanup ────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/api-key/{{g59_key}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/user/{{g59_id}}
Authorization: Bearer {{jwt}}
HTTP 200
