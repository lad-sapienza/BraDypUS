# ════════════════════════════════════════════════════════════════════
# Phase 46 — Deny by default: a scoped user and a scoped API key (#78)
#
# Two principals with no access of their own — a person at level Enter (39)
# and an API key at level Enter — both in a group that may read only the
# crud_test rows whose `tipo` is g46_pub. Every other entry point must refuse
# them: tables without a rule, rows outside the rule, every write, and the
# administration, configuration and system routes.
#
# A refused key gets HTTP 403; a refused person gets the usual HTTP 200 with
# {status:error, code:not_enough_privilege}.
#
# Written to catch the kind of hole that is invisible from the SPA (which hides
# the buttons): an endpoint that checks nothing. The first run of this matrix
# found DELETE /api/user/{id} open to any signed-in user.
#
# What the principals CAN do is checked first, so the refusals below cannot be
# the result of a principal that simply does not work.
# ════════════════════════════════════════════════════════════════════


# ── 46a. Setup (admin): two records in crud_test, one in siti ─────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G461", "tipo": "g46_pub" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_pub: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G462", "tipo": "g46_hidden" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_hid: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rec_hid}}, "core": { "descrizione": "edited once, so it has a version" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_hid}}/versions
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.versions" count >= 1
[Captures]
ver_hid: jsonpath "$.versions[0].id"


POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "G46 site" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_siti: jsonpath "$.id"


# How many records siti holds now (earlier phases may have left some): nothing may change it
GET {{base_url}}/{{app_name}}/api/records/siti
Authorization: Bearer {{jwt}}
HTTP 200
[Captures]
siti_total: jsonpath "$.total"


# ── 46b. The group, its one rule, a person and a key ─────────────────
POST {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g46 scoped" }
HTTP 200
[Asserts]
jsonpath "$.code" == "group_created"
[Captures]
group_id: jsonpath "$.id"


PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [ { "table": "crud_test", "action": "read", "filter": { "tipo": { "_eq": "g46_pub" } } } ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "rules_saved"


POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "G46 Scoped", "email": "g46scoped@bdus.test", "password": "Scoped_2025!", "privilege": 39 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
g46_user_id: jsonpath "$.id"


PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [ {{g46_user_id}} ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "members_saved"


POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g46scoped@bdus.test", "password": "Scoped_2025!", "app": "{{app_name}}" }
HTTP 200
[Captures]
user_jwt: jsonpath "$.token"


POST {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "label": "g46 scoped key", "privilege": 39, "group_id": {{group_id}} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
scoped_key: jsonpath "$.key"
scoped_key_id: jsonpath "$.id"


# The id of the admin (what the destructive probes below aim at)
GET {{base_url}}/{{app_name}}/api/users
Authorization: Bearer {{jwt}}
HTTP 200
[Captures]
admin_id: jsonpath "$.users[0].id"


# ════════════════════════════════════════════════════════════════════
# 46c. What the two principals CAN do
# ════════════════════════════════════════════════════════════════════


# ── user: sees exactly one readable table ──
GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables"         count == 1
jsonpath "$.tables[0].name" == "crud_test"
jsonpath "$.tables[0].can.read"   == true
jsonpath "$.tables[0].can.update" == false


# ── user: lists only the rows inside the rule ──
GET {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.total"      == 1
jsonpath "$.data[0].id" == {{rec_pub}}


# ── user: opens the readable record, not the other one ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"            == "success"
jsonpath "$.metadata.can_edit" == false


# ── user: the values on offer come from the readable rows only ──
GET {{base_url}}/{{app_name}}/api/search/crud_test/values?fld=tipo
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.values" count == 1
jsonpath "$.values[0]" == "g46_pub"


# ── user: an aggregate counts only the readable rows ──
POST {{base_url}}/{{app_name}}/api/chart/data
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"definition": {"tb": "crud_test", "type": "metric", "field": "id", "function": "COUNT"}}
HTTP 200
[Asserts]
jsonpath "$.value" == 1


# ── user: the chrono routes list nothing outside the scope ──
GET {{base_url}}/{{app_name}}/api/chrono/related/siti/{{rec_siti}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.sources" count == 0



# ── key: sees exactly one readable table ──
GET {{base_url}}/{{app_name}}/api/tables
Authorization: Bearer {{scoped_key}}
HTTP 200
[Asserts]
jsonpath "$.tables"         count == 1
jsonpath "$.tables[0].name" == "crud_test"
jsonpath "$.tables[0].can.read"   == true
jsonpath "$.tables[0].can.update" == false


# ── key: lists only the rows inside the rule ──
GET {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{scoped_key}}
HTTP 200
[Asserts]
jsonpath "$.total"      == 1
jsonpath "$.data[0].id" == {{rec_pub}}


# ── key: opens the readable record, not the other one ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}
Authorization: Bearer {{scoped_key}}
HTTP 200
[Asserts]
jsonpath "$.status"            == "success"
jsonpath "$.metadata.can_edit" == false


# ── key: the values on offer come from the readable rows only ──
GET {{base_url}}/{{app_name}}/api/search/crud_test/values?fld=tipo
Authorization: Bearer {{scoped_key}}
HTTP 200
[Asserts]
jsonpath "$.values" count == 1
jsonpath "$.values[0]" == "g46_pub"


# ── key: an aggregate counts only the readable rows ──
POST {{base_url}}/{{app_name}}/api/chart/data
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"definition": {"tb": "crud_test", "type": "metric", "field": "id", "function": "COUNT"}}
HTTP 200
[Asserts]
jsonpath "$.value" == 1


# ── key: the chrono routes list nothing outside the scope ──
GET {{base_url}}/{{app_name}}/api/chrono/related/siti/{{rec_siti}}
Authorization: Bearer {{scoped_key}}
HTTP 200
[Asserts]
jsonpath "$.sources" count == 0



# ════════════════════════════════════════════════════════════════════
# 46d. What neither principal may do — every entry point, both principals
# ════════════════════════════════════════════════════════════════════


# ── user: list a table without a rule ──
GET {{base_url}}/{{app_name}}/api/records/siti
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: list a table without a rule ──
GET {{base_url}}/{{app_name}}/api/records/siti
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: export a table without a rule ──
GET {{base_url}}/{{app_name}}/api/records/siti/export?format=csv
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: export a table without a rule ──
GET {{base_url}}/{{app_name}}/api/records/siti/export?format=csv
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: open a record of a table without a rule ──
GET {{base_url}}/{{app_name}}/api/record/siti/{{rec_siti}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: open a record of a table without a rule ──
GET {{base_url}}/{{app_name}}/api/record/siti/{{rec_siti}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: blank record form of a table without a rule ──
GET {{base_url}}/{{app_name}}/api/record/siti/new
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: blank record form of a table without a rule ──
GET {{base_url}}/{{app_name}}/api/record/siti/new
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: field options ──
GET {{base_url}}/{{app_name}}/api/record/siti/field-options?fld=nome
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.options" count == 0


# ── key: field options ──
GET {{base_url}}/{{app_name}}/api/record/siti/field-options?fld=nome
Authorization: Bearer {{scoped_key}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.options" count == 0


# ── user: uniqueness probe ──
GET {{base_url}}/{{app_name}}/api/record/siti/check-unique?field=nome&value=G46
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: uniqueness probe ──
GET {{base_url}}/{{app_name}}/api/record/siti/check-unique?field=nome&value=G46
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: link candidates ──
GET {{base_url}}/{{app_name}}/api/record/siti/link-candidates
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: link candidates ──
GET {{base_url}}/{{app_name}}/api/record/siti/link-candidates
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: deleted records ──
GET {{base_url}}/{{app_name}}/api/record/siti/deleted
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: deleted records ──
GET {{base_url}}/{{app_name}}/api/record/siti/deleted
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: versions of a record ──
GET {{base_url}}/{{app_name}}/api/record/siti/{{rec_siti}}/versions
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: versions of a record ──
GET {{base_url}}/{{app_name}}/api/record/siti/{{rec_siti}}/versions
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: templates of the table ──
GET {{base_url}}/{{app_name}}/api/record/siti/templates
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: templates of the table ──
GET {{base_url}}/{{app_name}}/api/record/siti/templates
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: RS matrix ──
GET {{base_url}}/{{app_name}}/api/rs/matrix?tb=siti
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: RS matrix ──
GET {{base_url}}/{{app_name}}/api/rs/matrix?tb=siti
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: used values ──
GET {{base_url}}/{{app_name}}/api/search/siti/values?fld=nome
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: used values ──
GET {{base_url}}/{{app_name}}/api/search/siti/values?fld=nome
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: search configuration (field names) ──
GET {{base_url}}/{{app_name}}/api/search/siti/config
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: search configuration (field names) ──
GET {{base_url}}/{{app_name}}/api/search/siti/config
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: chart data ──
POST {{base_url}}/{{app_name}}/api/chart/data
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"definition": {"tb": "siti", "type": "metric", "field": "id", "function": "COUNT"}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: chart data ──
POST {{base_url}}/{{app_name}}/api/chart/data
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"definition": {"tb": "siti", "type": "metric", "field": "id", "function": "COUNT"}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: assemblage table meta ──
GET {{base_url}}/{{app_name}}/api/assemblage/table-meta?tb=siti
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: assemblage table meta ──
GET {{base_url}}/{{app_name}}/api/assemblage/table-meta?tb=siti
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: assemblage data ──
POST {{base_url}}/{{app_name}}/api/assemblage/data
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"definition": {"source_tb": "siti", "char_field": "nome", "group_field": "nome", "measure": "count"}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: assemblage data ──
POST {{base_url}}/{{app_name}}/api/assemblage/data
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"definition": {"source_tb": "siti", "char_field": "nome", "group_field": "nome", "measure": "count"}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: GeoFace layer ──
GET {{base_url}}/{{app_name}}/api/geoface?tb=siti
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: GeoFace layer ──
GET {{base_url}}/{{app_name}}/api/geoface?tb=siti
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: import fields of the table ──
GET {{base_url}}/{{app_name}}/api/import/siti/fields
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: import fields of the table ──
GET {{base_url}}/{{app_name}}/api/import/siti/fields
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: Zotero links of a record ──
GET {{base_url}}/{{app_name}}/api/zotero/links/siti/{{rec_siti}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: Zotero links of a record ──
GET {{base_url}}/{{app_name}}/api/zotero/links/siti/{{rec_siti}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: open a record outside the scope ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_hid}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: open a record outside the scope ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_hid}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: versions of a record outside the scope ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_hid}}/versions
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: versions of a record outside the scope ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_hid}}/versions
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: version diff of a record outside the scope ──
GET {{base_url}}/{{app_name}}/api/version/{{ver_hid}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: version diff of a record outside the scope ──
GET {{base_url}}/{{app_name}}/api/version/{{ver_hid}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: deleted records (needs the whole table) ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/deleted
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: deleted records (needs the whole table) ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/deleted
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: Zotero links of a record outside the scope ──
GET {{base_url}}/{{app_name}}/api/zotero/links/crud_test/{{rec_hid}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: Zotero links of a record outside the scope ──
GET {{base_url}}/{{app_name}}/api/zotero/links/crud_test/{{rec_hid}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: create in a table without a rule ──
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"core": {"nome": "G46 intruder"}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: create in a table without a rule ──
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"core": {"nome": "G46 intruder"}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: create where the group only reads ──
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"core": {"sigla": "G469"}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: create where the group only reads ──
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"core": {"sigla": "G469"}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: update a record the group can read ──
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"id": "{{rec_pub}}", "core": {"descrizione": "x"}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: update a record the group can read ──
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"id": "{{rec_pub}}", "core": {"descrizione": "x"}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: update a record outside the scope ──
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"id": "{{rec_hid}}", "core": {"descrizione": "x"}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: update a record outside the scope ──
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"id": "{{rec_hid}}", "core": {"descrizione": "x"}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: delete a record the group can read ──
DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: delete a record the group can read ──
DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: delete in a table without a rule ──
DELETE {{base_url}}/{{app_name}}/api/record/siti/{{rec_siti}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: delete in a table without a rule ──
DELETE {{base_url}}/{{app_name}}/api/record/siti/{{rec_siti}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: duplicate a record ──
POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}/duplicate
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: duplicate a record ──
POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}/duplicate
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: duplicate a record outside the scope ──
POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_hid}}/duplicate
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: duplicate a record outside the scope ──
POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_hid}}/duplicate
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: erase plugin rows ──
DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}/plugins
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: erase plugin rows ──
DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}/plugins
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: check plugins before delete ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/check-plugins-before-delete?id={{rec_pub}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: check plugins before delete ──
GET {{base_url}}/{{app_name}}/api/record/crud_test/check-plugins-before-delete?id={{rec_pub}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: link a file ──
POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}/link-file
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"fileId": 1}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: link a file ──
POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}/link-file
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"fileId": 1}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: delete a file ──
DELETE {{base_url}}/{{app_name}}/api/file/1
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: delete a file ──
DELETE {{base_url}}/{{app_name}}/api/file/1
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: unlink a file ──
DELETE {{base_url}}/{{app_name}}/api/file-link/1
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: unlink a file ──
DELETE {{base_url}}/{{app_name}}/api/file-link/1
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: add a stratigraphic relation ──
POST {{base_url}}/{{app_name}}/api/record/crud_test/rs
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"tb": "crud_test", "first": "{{rec_pub}}", "second": "{{rec_hid}}", "relation": 1}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: add a stratigraphic relation ──
POST {{base_url}}/{{app_name}}/api/record/crud_test/rs
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"tb": "crud_test", "first": "{{rec_pub}}", "second": "{{rec_hid}}", "relation": 1}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: delete a stratigraphic relation ──
DELETE {{base_url}}/{{app_name}}/api/rs/1
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: delete a stratigraphic relation ──
DELETE {{base_url}}/{{app_name}}/api/rs/1
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: add a manual link ──
POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"tb_one": "crud_test", "id_one": "{{rec_pub}}", "tb_two": "crud_test", "id_two": "{{rec_hid}}"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: add a manual link ──
POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"tb_one": "crud_test", "id_one": "{{rec_pub}}", "tb_two": "crud_test", "id_two": "{{rec_hid}}"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: delete a manual link ──
DELETE {{base_url}}/{{app_name}}/api/manual-link/1
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: delete a manual link ──
DELETE {{base_url}}/{{app_name}}/api/manual-link/1
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: add a geometry ──
POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"tb": "crud_test", "id": "{{rec_pub}}", "geometry": "POINT(1 1)"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: add a geometry ──
POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"tb": "crud_test", "id": "{{rec_pub}}", "geometry": "POINT(1 1)"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: change a geometry ──
PUT {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"geodata": [{"id": 1, "geometry": "POINT(2 2)"}]}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: change a geometry ──
PUT {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"geodata": [{"id": 1, "geometry": "POINT(2 2)"}]}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: delete a geometry ──
DELETE {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"ids": [1]}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: delete a geometry ──
DELETE {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"ids": [1]}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: import data ──
POST {{base_url}}/{{app_name}}/api/import/data
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"temp_id": "x", "tb": "crud_test", "type": "csv", "mapping": {"a": "sigla"}, "key_field": "sigla"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: import data ──
POST {{base_url}}/{{app_name}}/api/import/data
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"temp_id": "x", "tb": "crud_test", "type": "csv", "mapping": {"a": "sigla"}, "key_field": "sigla"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: import GeoJSON ──
POST {{base_url}}/{{app_name}}/api/import/geojson
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"temp_id": "x", "tb": "crud_test", "geo_prop": "a", "key_field": "sigla"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: import GeoJSON ──
POST {{base_url}}/{{app_name}}/api/import/geojson
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"temp_id": "x", "tb": "crud_test", "geo_prop": "a", "key_field": "sigla"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: import photos ──
POST {{base_url}}/{{app_name}}/api/import/photos
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"temp_id": "x", "tb": "crud_test"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: import photos ──
POST {{base_url}}/{{app_name}}/api/import/photos
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"temp_id": "x", "tb": "crud_test"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: add a Zotero link ──
POST {{base_url}}/{{app_name}}/api/zotero/link
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"tb": "crud_test", "record_id": "{{rec_pub}}", "lib_id": 1, "zotero_key": "K"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: add a Zotero link ──
POST {{base_url}}/{{app_name}}/api/zotero/link
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"tb": "crud_test", "record_id": "{{rec_pub}}", "lib_id": 1, "zotero_key": "K"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: delete a Zotero link ──
DELETE {{base_url}}/{{app_name}}/api/zotero/link/1
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: delete a Zotero link ──
DELETE {{base_url}}/{{app_name}}/api/zotero/link/1
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: create a user ──
POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"name": "x", "email": "x@x.test", "password": "Xx_1234567!", "privilege": 1}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: create a user ──
POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"name": "x", "email": "x@x.test", "password": "Xx_1234567!", "privilege": 1}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: delete a user ──
DELETE {{base_url}}/{{app_name}}/api/user/{{admin_id}}
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: delete a user ──
DELETE {{base_url}}/{{app_name}}/api/user/{{admin_id}}
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: end a user's session ──
POST {{base_url}}/{{app_name}}/api/user/{{admin_id}}/revoke
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: end a user's session ──
POST {{base_url}}/{{app_name}}/api/user/{{admin_id}}/revoke
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: list API keys ──
GET {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: list API keys ──
GET {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: create an API key ──
POST {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"label": "x", "privilege": 10}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: create an API key ──
POST {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"label": "x", "privilege": 10}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: list groups ──
GET {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: list groups ──
GET {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: create a group ──
POST {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"name": "x"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: create a group ──
POST {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"name": "x"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: change a group's rules ──
PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/rules
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"rules": [{"table": "siti", "action": "read"}]}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: change a group's rules ──
PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/rules
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"rules": [{"table": "siti", "action": "read"}]}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: change a group's members ──
PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/members
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"user_ids": []}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: change a group's members ──
PUT {{base_url}}/{{app_name}}/api/group/{{group_id}}/members
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"user_ids": []}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: a user's groups ──
PUT {{base_url}}/{{app_name}}/api/user/{{admin_id}}/groups
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"group_ids": ["{{group_id}}"]}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: a user's groups ──
PUT {{base_url}}/{{app_name}}/api/user/{{admin_id}}/groups
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"group_ids": ["{{group_id}}"]}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: list locks ──
GET {{base_url}}/{{app_name}}/api/locks
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: list locks ──
GET {{base_url}}/{{app_name}}/api/locks
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: create a lock ──
POST {{base_url}}/{{app_name}}/api/locks
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"table": "crud_test", "filter": {"id": {"_gt": "0"}}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: create a lock ──
POST {{base_url}}/{{app_name}}/api/locks
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"table": "crud_test", "filter": {"id": {"_gt": "0"}}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: read the configuration ──
GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: read the configuration ──
GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: change the application settings ──
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"status": "off"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: change the application settings ──
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"status": "off"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: drop a table ──
DELETE {{base_url}}/{{app_name}}/api/config/table/siti
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: drop a table ──
DELETE {{base_url}}/{{app_name}}/api/config/table/siti
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: list backups ──
GET {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: list backups ──
GET {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: make a backup ──
POST {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: make a backup ──
POST {{base_url}}/{{app_name}}/api/backups
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: read the log ──
GET {{base_url}}/{{app_name}}/api/logs
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: read the log ──
GET {{base_url}}/{{app_name}}/api/logs
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: search & replace ──
POST {{base_url}}/{{app_name}}/api/search-replace
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"tb": "crud_test", "fld": "sigla", "search": "G", "replace": "X"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: search & replace ──
POST {{base_url}}/{{app_name}}/api/search-replace
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"tb": "crud_test", "fld": "sigla", "search": "G", "replace": "X"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: add a vocabulary entry ──
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"voc": "g46", "def": "x"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: add a vocabulary entry ──
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"voc": "g46", "def": "x"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: read vocabularies ──
GET {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: read vocabularies ──
GET {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: run SQL ──
POST {{base_url}}/{{app_name}}/api/free-sql/run
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"sql": "SELECT 1"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: run SQL ──
POST {{base_url}}/{{app_name}}/api/free-sql/run
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"sql": "SELECT 1"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: rewrite the welcome page ──
PUT {{base_url}}/{{app_name}}/api/welcome
Authorization: Bearer {{user_jwt}}
Content-Type: application/json
{"content": "defaced"}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: rewrite the welcome page ──
PUT {{base_url}}/{{app_name}}/api/welcome
Authorization: Bearer {{scoped_key}}
Content-Type: application/json
{"content": "defaced"}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── user: run a minor upgrade ──
POST {{base_url}}/{{app_name}}/api/upgrade/minor
Authorization: Bearer {{user_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── key: run a minor upgrade ──
POST {{base_url}}/{{app_name}}/api/upgrade/minor
Authorization: Bearer {{scoped_key}}
HTTP 403
[Asserts]
jsonpath "$.code" == "not_enough_privilege"



# ════════════════════════════════════════════════════════════════════
# 46e. Nothing was changed, and the admin is still there
# ════════════════════════════════════════════════════════════════════
GET {{base_url}}/{{app_name}}/api/user/{{admin_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.id"     == {{admin_id}}


GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


GET {{base_url}}/{{app_name}}/api/records/siti
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == {{siti_total}}


# ── 46f. Cleanup ─────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/api-key/{{scoped_key_id}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/group/{{group_id}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/user/{{g46_user_id}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_pub}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_hid}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/record/siti/{{rec_siti}}
Authorization: Bearer {{jwt}}
HTTP 200
