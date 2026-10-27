# ════════════════════════════════════════════════════════════════════
# Phase 47 — The demo profiles, on the demo data (#78)
#
# Runs after the demo seed (--seed), not with --tests: it needs the seeded
# dataset and the two demo users the seed creates —
#
#   Editor Demo    writer (20)
#   Revisore Demo  level Enter (39), in the group "Revisori" that reads all
#                  of `sepolture` and the verified stratigraphic units, with a
#                  lock freezing the verified units
#
# — and adds the two levels no other phase exercises, a self writer (25) and a
# pending user (40), plus an API key in the reviewers' group. For each profile:
# what it sees, what it can change, what it is refused. Nothing it creates
# outlives the phase, and it never changes a seeded record.
# ════════════════════════════════════════════════════════════════════


# ── 47a. What the seeded dataset holds (admin) ──────────────────────
GET {{base_url}}/{{app_name}}/api/records/us?per_page=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" >= 10
[Captures]
us_total: jsonpath "$.total"
us_first: jsonpath "$.data[0].id"


GET {{base_url}}/{{app_name}}/api/records/us?per_page=1&filter[verificata][_eq]=1
Authorization: Bearer {{jwt}}
HTTP 200
[Captures]
us_verified: jsonpath "$.total"


GET {{base_url}}/{{app_name}}/api/records/sepolture?per_page=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" >= 1
[Captures]
sep_total: jsonpath "$.total"
sep_first: jsonpath "$.data[0].id"


GET {{base_url}}/{{app_name}}/api/records/reperti?per_page=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" >= 1
[Captures]
rep_total: jsonpath "$.total"
rep_first: jsonpath "$.data[0].id"


GET {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.groups" count >= 1
[Captures]
reviewers_group: jsonpath "$.groups[0].id"


# ── 47b. The profiles log in (and the two new ones are created) ─────
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "editor@example.com", "password": "Editor1234!", "app": "{{app_name}}" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
editor_jwt: jsonpath "$.token"


POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "revisore@example.com", "password": "Revisore1234!", "app": "{{app_name}}" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rev_jwt: jsonpath "$.token"


POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "G47 Author", "email": "g47author@bdus.test", "password": "Author_2025!", "privilege": 25 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
author_id: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "G47 Pending", "email": "g47pending@bdus.test", "password": "Pending_2025!", "privilege": 40 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
pending_id: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g47author@bdus.test", "password": "Author_2025!", "app": "{{app_name}}" }
HTTP 200
[Captures]
author_jwt: jsonpath "$.token"


POST {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "label": "g47 reviewers key", "privilege": 39, "group_id": {{reviewers_group}} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rev_key: jsonpath "$.key"
rev_key_id: jsonpath "$.id"


# ════════════════════════════════════════════════════════════════════
# Admin — the reference: everything, frozen records included
# ════════════════════════════════════════════════════════════════════
GET {{base_url}}/{{app_name}}/api/record/us/{{us_first}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.metadata.can_edit"   == true
jsonpath "$.metadata.can_delete" == true
jsonpath "$.metadata.locked"     == true


# ════════════════════════════════════════════════════════════════════
# Editor Demo (writer, 20): reads and writes everything but frozen units
# ════════════════════════════════════════════════════════════════════
GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{editor_jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables" count == 6


GET {{base_url}}/{{app_name}}/api/records/us?per_page=1
Authorization: Bearer {{editor_jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{us_total}}


# A frozen unit: visible, not changeable
GET {{base_url}}/{{app_name}}/api/record/us/{{us_first}}
Authorization: Bearer {{editor_jwt}}
HTTP 200
[Asserts]
jsonpath "$.metadata.can_edit"   == false
jsonpath "$.metadata.can_delete" == false
jsonpath "$.metadata.locked"     == true


POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{editor_jwt}}
Content-Type: application/json
{ "id": {{us_first}}, "core": { "note": "changed by the editor" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


DELETE {{base_url}}/{{app_name}}/api/record/us/{{us_first}}
Authorization: Bearer {{editor_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"


# Everything else is theirs to write: create, change, delete a find
POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{editor_jwt}}
Content-Type: application/json
{ "core": { "inventario": "G47-EDITOR-1" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
editor_rep: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{editor_jwt}}
Content-Type: application/json
{ "id": {{editor_rep}}, "core": { "descrizione": "edited" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


DELETE {{base_url}}/{{app_name}}/api/record/reperti/{{editor_rep}}
Authorization: Bearer {{editor_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# Administration is not theirs
GET {{base_url}}/{{app_name}}/api/users
Authorization: Bearer {{editor_jwt}}
HTTP 200
[Asserts]
jsonpath "$.users" count == 1


GET {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{editor_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


POST {{base_url}}/{{app_name}}/api/locks
Authorization: Bearer {{editor_jwt}}
Content-Type: application/json
{ "table": "reperti", "filter": { "id": { "_gt": "0" } } }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{editor_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ════════════════════════════════════════════════════════════════════
# Revisore Demo (level Enter, group Revisori): sepolture and verified units, read only
# ════════════════════════════════════════════════════════════════════
GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables"               count == 2
jsonpath "$.tables[0].name"       == "us"
jsonpath "$.tables[1].name"       == "sepolture"
jsonpath "$.tables[0].can.read"   == true
jsonpath "$.tables[0].can.update" == false
jsonpath "$.tables[1].can.create" == false


GET {{base_url}}/{{app_name}}/api/records/us?per_page=1
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{us_verified}}


GET {{base_url}}/{{app_name}}/api/records/sepolture?per_page=1
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{sep_total}}


GET {{base_url}}/{{app_name}}/api/record/sepolture/{{sep_first}}
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"            == "success"
jsonpath "$.metadata.can_edit" == false


GET {{base_url}}/{{app_name}}/api/record/us/{{us_first}}
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"              == "success"
jsonpath "$.metadata.can_edit"   == false
jsonpath "$.metadata.can_delete" == false


POST {{base_url}}/{{app_name}}/api/chart/data
Authorization: Bearer {{rev_jwt}}
Content-Type: application/json
{ "definition": { "tb": "us", "type": "metric", "field": "id", "function": "COUNT" } }
HTTP 200
[Asserts]
jsonpath "$.value" == {{us_verified}}


# The finds are not theirs, in any way
GET {{base_url}}/{{app_name}}/api/records/reperti
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/record/reperti/{{rep_first}}
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/records/reperti/export?format=csv
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# …and nothing can be written
POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{rev_jwt}}
Content-Type: application/json
{ "id": {{us_first}}, "core": { "note": "changed by the reviewer" } }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


POST {{base_url}}/{{app_name}}/api/record/sepolture
Authorization: Bearer {{rev_jwt}}
Content-Type: application/json
{ "core": { "sigla": "G47" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


DELETE {{base_url}}/{{app_name}}/api/record/sepolture/{{sep_first}}
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"


GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/users
Authorization: Bearer {{rev_jwt}}
HTTP 200
[Asserts]
jsonpath "$.users" count == 1


# ════════════════════════════════════════════════════════════════════
# An API key in the reviewers' group: the same slice, as 403s
# ════════════════════════════════════════════════════════════════════
GET {{base_url}}/{{app_name}}/api/records/us?per_page=1
Authorization: Bearer {{rev_key}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{us_verified}}


GET {{base_url}}/{{app_name}}/api/records/sepolture?per_page=1
Authorization: Bearer {{rev_key}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{sep_total}}


GET {{base_url}}/{{app_name}}/api/records/reperti
Authorization: Bearer {{rev_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{rev_key}}
Content-Type: application/json
{ "id": {{us_first}}, "core": { "note": "changed by the key" } }
HTTP 403


GET {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{rev_key}}
HTTP 403


# ════════════════════════════════════════════════════════════════════
# Self writer (25): reads everything, creates, changes only their own
# ════════════════════════════════════════════════════════════════════
GET {{base_url}}/{{app_name}}/api/records/reperti?per_page=1
Authorization: Bearer {{author_jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{rep_total}}


# Somebody else's record: visible, not theirs to change
GET {{base_url}}/{{app_name}}/api/record/reperti/{{rep_first}}
Authorization: Bearer {{author_jwt}}
HTTP 200
[Asserts]
jsonpath "$.metadata.can_edit"   == false
jsonpath "$.metadata.can_delete" == false
jsonpath "$.metadata.can_add"    == true


POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{author_jwt}}
Content-Type: application/json
{ "id": {{rep_first}}, "core": { "descrizione": "changed by the author" } }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


DELETE {{base_url}}/{{app_name}}/api/record/reperti/{{rep_first}}
Authorization: Bearer {{author_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"


# Their own: create, change, delete
POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{author_jwt}}
Content-Type: application/json
{ "core": { "inventario": "G47-AUTHOR-1" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
author_rep: jsonpath "$.id"


GET {{base_url}}/{{app_name}}/api/record/reperti/{{author_rep}}
Authorization: Bearer {{author_jwt}}
HTTP 200
[Asserts]
jsonpath "$.metadata.can_edit"   == true
jsonpath "$.metadata.can_delete" == true


POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{author_jwt}}
Content-Type: application/json
{ "id": {{author_rep}}, "core": { "descrizione": "edited by its author" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


DELETE {{base_url}}/{{app_name}}/api/record/reperti/{{author_rep}}
Authorization: Bearer {{author_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# The frozen units are not theirs either
POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{author_jwt}}
Content-Type: application/json
{ "id": {{us_first}}, "core": { "note": "changed by the author" } }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{author_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ════════════════════════════════════════════════════════════════════
# Pending (40): signed in, or not, sees and does nothing
# ════════════════════════════════════════════════════════════════════
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g47pending@bdus.test", "password": "Pending_2025!", "app": "{{app_name}}" }
HTTP 200
[Captures]
pending_jwt: jsonpath "$.token"


GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{pending_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/records/us
Authorization: Bearer {{pending_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/record/us/{{us_first}}
Authorization: Bearer {{pending_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{pending_jwt}}
Content-Type: application/json
{ "core": { "inventario": "G47-PENDING-1" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


GET {{base_url}}/{{app_name}}/api/info/app
Authorization: Bearer {{pending_jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ════════════════════════════════════════════════════════════════════
# Nothing was changed, and what this phase created is gone
# ════════════════════════════════════════════════════════════════════
GET {{base_url}}/{{app_name}}/api/records/reperti?per_page=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{rep_total}}


GET {{base_url}}/{{app_name}}/api/records/us?per_page=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{us_total}}


GET {{base_url}}/{{app_name}}/api/records/sepolture?per_page=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{sep_total}}


DELETE {{base_url}}/{{app_name}}/api/api-key/{{rev_key_id}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/user/{{author_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


DELETE {{base_url}}/{{app_name}}/api/user/{{pending_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
