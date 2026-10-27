# ════════════════════════════════════════════════════════════════════
# Phase 45 — Groups, rules, locks and scoped API keys (#78)
#
# Runs against crud_test. Two records are told apart by `tipo`:
#   g45_public  — what the group may read / change
#   g45_draft   — everything else
#
# A key with tier 39 and a group has no access of its own: what it gets
# comes from the group's rules, and a refusal is an HTTP 403.
# People keep the 200 + {status:error, code} envelope.
# Detailed rule/lock semantics are covered by PHPUnit; here the HTTP
# contract and the wiring (Dispatcher → controller → rules) end to end.
# ════════════════════════════════════════════════════════════════════


# ── 45a. Two records ────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G451", "tipo": "g45_public" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_pub: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G452", "tipo": "g45_draft" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_draft: jsonpath "$.id"


# ── 45b. A group, its rules, its key ────────────────────────────────
POST {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g45 Public", "description": "phase 45" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "group_created"
[Captures]
group_id: jsonpath "$.id"


PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [ { "table": "crud_test", "action": "read", "filter": { "tipo": { "_eq": "g45_public" } } } ] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "rules_saved"


# An invalid rule is refused and nothing changes
PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [ { "table": "crud_test", "action": "read", "filter": { "no_such_field": { "_eq": 1 } } } ] }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_filter"


GET {{base_url}}/{{app_name}}/api/group/{{group_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.group.name"             == "g45 Public"
jsonpath "$.group.rules"            count == 1
jsonpath "$.group.rules[0].table"   == "crud_test"
jsonpath "$.group.rules[0].action"  == "read"


POST {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "label": "g45 scoped key", "privilege": 39, "group_id": {{group_id}} }
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.group_id" == {{group_id}}
[Captures]
scoped_key: jsonpath "$.key"
scoped_key_id: jsonpath "$.id"


# ── 45c. The key reads only what the group allows ───────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{scoped_key}}
HTTP 200
[Asserts]
jsonpath "$.status"     == "success"
jsonpath "$.total"      == 1
jsonpath "$.data[0].id" == {{rec_pub}}


GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}
Authorization: Bearer {{scoped_key}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# A record outside the scope, another table, a write, the admin API: all 403
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_draft}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/records/siti
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{ "id": {{rec_pub}}, "core": { "descrizione": "by key" } }
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}
Authorization: Bearer {{scoped_key}}
HTTP 403


GET {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{scoped_key}}
HTTP 403


# ── 45d. Adding an update rule lets the key change its records ──────
PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [
    { "table": "crud_test", "action": "read",   "filter": { "tipo": { "_eq": "g45_public" } } },
    { "table": "crud_test", "action": "update", "filter": { "tipo": { "_eq": "g45_public" } } }
] }
HTTP 200
[Asserts]
jsonpath "$.code" == "rules_saved"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{ "id": {{rec_pub}}, "core": { "descrizione": "by key" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# …but not the others
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{ "id": {{rec_draft}}, "core": { "descrizione": "by key" } }
HTTP 403


# ── 45e. A lock freezes the published record for everyone but admins ─
POST {{base_url}}/{{app_name}}/api/locks
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "table": "crud_test", "filter": { "tipo": { "_eq": "g45_public" } }, "note": "published" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "lock_created"
[Captures]
lock_id: jsonpath "$.id"


# An empty lock would freeze the whole table: refused
POST {{base_url}}/{{app_name}}/api/locks
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "table": "crud_test", "filter": {} }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_filter"


GET {{base_url}}/{{app_name}}/api/locks
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.locks"          count == 1
jsonpath "$.locks[0].table" == "crud_test"


# The key's update rule no longer reaches the frozen record
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{ "id": {{rec_pub}}, "core": { "descrizione": "frozen" } }
HTTP 403


# A writer (tier 20) is stopped too — as a 200 + error code, being a person
POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "G45 Writer", "email": "g45writer@bdus.test", "password": "Writer_2025!", "privilege": 20 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
g45_writer_id: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g45writer@bdus.test", "password": "Writer_2025!", "app": "{{app_name}}" }
HTTP 200
[Captures]
g45_writer_jwt: jsonpath "$.token"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g45_writer_jwt}}
Content-Type: application/json
{ "id": {{rec_pub}}, "core": { "descrizione": "frozen" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g45_writer_jwt}}
Content-Type: application/json
{ "id": {{rec_draft}}, "core": { "descrizione": "writer edit" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# An admin is never locked out of the data
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rec_pub}}, "core": { "descrizione": "admin edit" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# Removing the lock thaws the record
DELETE {{base_url}}/{{app_name}}/api/lock/{{lock_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "lock_deleted"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g45_writer_jwt}}
Content-Type: application/json
{ "id": {{rec_pub}}, "core": { "descrizione": "thawed" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 45f. Membership: a person in the group (tier 39) sees what it allows ─
POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "G45 Reviewer", "email": "g45reviewer@bdus.test", "password": "Review_2025!", "privilege": 39 }
HTTP 200
[Captures]
g45_reviewer_id: jsonpath "$.id"


PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [ {{g45_reviewer_id}} ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "members_saved"


GET {{base_url}}/{{app_name}}/api/user/{{g45_reviewer_id}}/groups
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.groups" count == 1
jsonpath "$.groups[0]" == {{group_id}}


POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g45reviewer@bdus.test", "password": "Review_2025!", "app": "{{app_name}}" }
HTTP 200
[Captures]
g45_reviewer_jwt: jsonpath "$.token"


GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{g45_reviewer_jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables"        count == 1
jsonpath "$.tables[0].name" == "crud_test"


GET {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{g45_reviewer_jwt}}
HTTP 200
[Asserts]
jsonpath "$.total"      == 1
jsonpath "$.data[0].id" == {{rec_pub}}


GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_draft}}
Authorization: Bearer {{g45_reviewer_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 45g. Cleanup ────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/group/{{group_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "group_deleted"


# The key survives its group but has lost all access
GET {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{scoped_key}}
HTTP 403


DELETE {{base_url}}/{{app_name}}/api/api-key/{{scoped_key_id}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/user/{{g45_writer_id}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/user/{{g45_reviewer_id}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_draft}}
Authorization: Bearer {{jwt}}
HTTP 200
