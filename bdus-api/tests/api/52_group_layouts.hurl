# ════════════════════════════════════════════════════════════════════
# Phase 52 — Record layouts per group (M051, Template\Assign)
#
# A group can name, per table, the templates its members must use. Interface
# convenience, NOT a permission. Checked on crud_test with a reader (level 30):
#   no assignment      → free choice, as before
#   one layout         → locked, and the record opens with it
#   two layouts        → a choice between them only
#   admin              → never restricted
#   rename / delete    → the group follows
# ════════════════════════════════════════════════════════════════════

POST {{base_url}}/{{app_name}}/api/template/crud_test/lay52_a
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "sections": [ { "label": "Lay52 A", "content": [ { "field": "sigla" } ] } ] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/template/crud_test/lay52_b
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "sections": [ { "label": "Lay52 B", "content": [ { "field": "sigla" } ] } ] }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "L5201", "tipo": "l52" }, "plugins": {} }
HTTP 200
[Captures]
l52_rec: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "l52_reader", "email": "l52_reader@bdus.test", "password": "Parity_2025!x", "privilege": 30 }
HTTP 200
[Captures]
l52_user: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "l52_reader@bdus.test", "password": "Parity_2025!x", "app": "{{app_name}}" }
HTTP 200
[Captures]
l52_jwt: jsonpath "$.token"

POST {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "l52 Layouts" }
HTTP 200
[Captures]
l52_group: jsonpath "$.id"

PUT {{base_url}}/{{app_name}}/api/group/{{l52_group}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [ {{l52_user}} ] }
HTTP 200

# ── 52a. No layout assigned: free choice, nothing changes ─────────────
GET {{base_url}}/{{app_name}}/api/record/crud_test/templates
Authorization: Bearer {{l52_jwt}}
HTTP 200
[Asserts]
jsonpath "$.assigned" == false
jsonpath "$.locked" == false
jsonpath "$.templates" contains "lay52_a"
jsonpath "$.templates" contains "lay52_b"

# ── 52b. One layout: locked, and the record opens with it ─────────────
PUT {{base_url}}/{{app_name}}/api/group/{{l52_group}}/layouts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "layouts": { "crud_test": [ "lay52_a" ] } }
HTTP 200
[Asserts]
jsonpath "$.code" == "layouts_saved"

GET {{base_url}}/{{app_name}}/api/group/{{l52_group}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.group.layouts.crud_test[0]" == "lay52_a"

GET {{base_url}}/{{app_name}}/api/record/crud_test/templates
Authorization: Bearer {{l52_jwt}}
HTTP 200
[Asserts]
jsonpath "$.assigned" == true
jsonpath "$.locked" == true
jsonpath "$.templates" count == 1
jsonpath "$.templates[0]" == "lay52_a"

GET {{base_url}}/{{app_name}}/api/record/crud_test/{{l52_rec}}
Authorization: Bearer {{l52_jwt}}
HTTP 200
[Asserts]
jsonpath "$.schema.template.sections[0].label" == "Lay52 A"

# asking for a template outside the assignment is ignored
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{l52_rec}}?template=lay52_b
Authorization: Bearer {{l52_jwt}}
HTTP 200
[Asserts]
jsonpath "$.schema.template.sections[0].label" == "Lay52 A"

# ── 52c. Two layouts: a choice between them only ──────────────────────
PUT {{base_url}}/{{app_name}}/api/group/{{l52_group}}/layouts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "layouts": { "crud_test": [ "lay52_a", "lay52_b" ] } }
HTTP 200

GET {{base_url}}/{{app_name}}/api/record/crud_test/templates
Authorization: Bearer {{l52_jwt}}
HTTP 200
[Asserts]
jsonpath "$.locked" == false
jsonpath "$.templates" count == 2

GET {{base_url}}/{{app_name}}/api/record/crud_test/{{l52_rec}}?template=lay52_b
Authorization: Bearer {{l52_jwt}}
HTTP 200
[Asserts]
jsonpath "$.schema.template.sections[0].label" == "Lay52 B"

# ── 52d. The layout does not change what the API returns ─────────────
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{l52_rec}}
Authorization: Bearer {{l52_jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.tipo.val" == "l52"

# ── 52e. Admin is never restricted ────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/record/crud_test/templates
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.assigned" == false

# ── 52f. Validation ───────────────────────────────────────────────────
PUT {{base_url}}/{{app_name}}/api/group/{{l52_group}}/layouts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "layouts": { "crud_test": [ "ghost" ] } }
HTTP 200
[Asserts]
jsonpath "$.code" == "template_not_found"

PUT {{base_url}}/{{app_name}}/api/group/{{l52_group}}/layouts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "layouts": { "nope": [ "lay52_a" ] } }
HTTP 200
[Asserts]
jsonpath "$.code" == "unknown_table"

PUT {{base_url}}/{{app_name}}/api/group/{{l52_group}}/layouts
Authorization: Bearer {{l52_jwt}}
Content-Type: application/json
{ "layouts": {} }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

# ── 52g. Rename and delete: the group follows ─────────────────────────
POST {{base_url}}/{{app_name}}/api/template/crud_test/lay52_a/rename
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "new": "lay52_c" }
HTTP 200

GET {{base_url}}/{{app_name}}/api/group/{{l52_group}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.group.layouts.crud_test[0]" == "lay52_c"

DELETE {{base_url}}/{{app_name}}/api/template/crud_test/lay52_c
Authorization: Bearer {{jwt}}
HTTP 200

GET {{base_url}}/{{app_name}}/api/group/{{l52_group}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.group.layouts.crud_test[0]" == "lay52_b"
jsonpath "$.group.layouts.crud_test" count == 1

# ── cleanup ───────────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/template/crud_test/lay52_b
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/group/{{l52_group}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/user/{{l52_user}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{l52_rec}}
Authorization: Bearer {{jwt}}
HTTP 200
