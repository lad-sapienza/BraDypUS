# ════════════════════════════════════════════════════════════════════
# Phase 58 — Level Enter + group reaches the same screens as the levels
#
# A user at level Enter has no data access of their own. In the Reader group
# they open the read-only screens; in the Writer group they also take a backup
# and edit file metadata, charts and the like. Neither can DOWNLOAD a backup
# (admin only). A level-30 Reader no longer sees backups at all.
# ════════════════════════════════════════════════════════════════════

GET {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
HTTP 200
[Captures]
g58_reader: jsonpath "$.groups[?(@.name=='Reader')].id"
g58_writer: jsonpath "$.groups[?(@.name=='Writer')].id"

# ── Enter, no group: nothing ──────────────────────────────
POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g58_enter", "email": "g58_enter@bdus.test", "password": "Parity_2025!x", "privilege": 39 }
HTTP 200
[Captures]
g58_id: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g58_enter@bdus.test", "password": "Parity_2025!x", "app": "{{app_name}}" }
HTTP 200
[Captures]
g58_jwt: jsonpath "$.token"

GET {{base_url}}/{{app_name}}/api/history
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

GET {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

GET {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

# ── Enter + Reader group: read-only screens, no backups ───────
PUT {{base_url}}/{{app_name}}/api/group/{{g58_reader}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [ {{g58_id}} ] }
HTTP 200

GET {{base_url}}/{{app_name}}/api/history
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/info/app
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

POST {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

# ── Enter + Writer group: backups yes, download no ────────────
PUT {{base_url}}/{{app_name}}/api/group/{{g58_reader}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [] }
HTTP 200

PUT {{base_url}}/{{app_name}}/api/group/{{g58_writer}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [ {{g58_id}} ] }
HTTP 200

GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[?(@.name=='crud_test')].can.update" == true

POST {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.can_download" == false
[Captures]
g58_backup: jsonpath "$.backups[0].file"

GET {{base_url}}/{{app_name}}/api/backup/{{g58_backup}}/download
Authorization: Bearer {{g58_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

# the admin can
GET {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.can_download" == true

# ── Cleanup ───────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/backup/{{g58_backup}}
Authorization: Bearer {{jwt}}
HTTP 200

PUT {{base_url}}/{{app_name}}/api/group/{{g58_writer}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [] }
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/user/{{g58_id}}
Authorization: Bearer {{jwt}}
HTTP 200
