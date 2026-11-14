# ════════════════════════════════════════════════════════════════════
# Phase 50 — Group variables and server-enforced values (#51)
#
# Runs against crud_test. One group with an attribute (`area` = g50_north)
# and ONE set of parametrised rules for it:
#   read/update  tipo = $GROUP.area
#   create       enforce { tipo: $GROUP.area }
# A person in the group (tier 39) can only see, create and keep records
# with that `tipo`, whatever the client sends. The finer semantics
# (several groups, lists, unknown attributes) are covered by PHPUnit.
# ════════════════════════════════════════════════════════════════════


# ── 50a. A record in the group's area and one outside ───────────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G501", "tipo": "g50_north" }, "plugins": {} }
HTTP 200
[Captures]
rec_in: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G502", "tipo": "g50_south" }, "plugins": {} }
HTTP 200
[Captures]
rec_out: jsonpath "$.id"


# ── 50b. The group, with its attribute and parametrised rules ───────
POST {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g50 North", "attrs": { "area": "g50_north" } }
HTTP 200
[Asserts]
jsonpath "$.code" == "group_created"
[Captures]
g50_group: jsonpath "$.id"


GET {{base_url}}/{{app_name}}/api/group/{{g50_group}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.group.attrs.area" == "g50_north"


# Invalid attributes and invalid enforce maps are refused
PUT {{base_url}}/{{app_name}}/api/group/{{g50_group}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g50 North", "attrs": { "bad key": "x" } }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_attrs"


PUT {{base_url}}/{{app_name}}/api/group/{{g50_group}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [ { "table": "crud_test", "action": "create", "enforce": { "no_such_field": "x" } } ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_enforce"


PUT {{base_url}}/{{app_name}}/api/group/{{g50_group}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [
    { "table": "crud_test", "action": "read",   "filter": { "tipo": { "_eq": "$GROUP.area" } } },
    { "table": "crud_test", "action": "update", "filter": { "tipo": { "_eq": "$GROUP.area" } }, "enforce": { "tipo": "$GROUP.area" } },
    { "table": "crud_test", "action": "create", "enforce": { "tipo": "$GROUP.area" } }
] }
HTTP 200
[Asserts]
jsonpath "$.code" == "rules_saved"


GET {{base_url}}/{{app_name}}/api/group/{{g50_group}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.group.rules" count == 3
jsonpath "$.group.rules[?(@.action=='create')].enforce.tipo" == "$GROUP.area"


# ── 50c. A member of the group ──────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "G50 Member", "email": "g50member@bdus.test", "password": "Member_2025!", "privilege": 39 }
HTTP 200
[Captures]
g50_user: jsonpath "$.id"


PUT {{base_url}}/{{app_name}}/api/group/{{g50_group}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [ {{g50_user}} ] }
HTTP 200


POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g50member@bdus.test", "password": "Member_2025!", "app": "{{app_name}}" }
HTTP 200
[Captures]
g50_jwt: jsonpath "$.token"


# Reads follow the attribute
GET {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{g50_jwt}}
HTTP 200
[Asserts]
jsonpath "$.total"      == 1
jsonpath "$.data[0].id" == {{rec_in}}


# ── 50d. Create: forged value refused, empty value filled in ────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g50_jwt}}
Content-Type: application/json
{ "core": { "sigla": "G503", "tipo": "g50_south" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "value_not_allowed"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g50_jwt}}
Content-Type: application/json
{ "core": { "sigla": "G504", "tipo": "g50_north" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_made: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g50_jwt}}
Content-Type: application/json
{ "core": { "sigla": "G505" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_filled: jsonpath "$.id"


GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_filled}}
Authorization: Bearer {{g50_jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.tipo.val" == "g50_north"


# ── 50e. Update: stays in the area; moving out is refused ───────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g50_jwt}}
Content-Type: application/json
{ "id": {{rec_in}}, "core": { "tipo": "g50_south" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "value_not_allowed"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g50_jwt}}
Content-Type: application/json
{ "id": {{rec_in}}, "core": { "descrizione": "edited by member" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# A record outside the area is not theirs to change
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g50_jwt}}
Content-Type: application/json
{ "id": {{rec_out}}, "core": { "descrizione": "nope" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 50e-bis. Server stamps: the key that created a record, and when (#73) ──
POST {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g50 Stamps" }
HTTP 200
[Captures]
g50_stamp_group: jsonpath "$.id"


# Stamps are for new records only
PUT {{base_url}}/{{app_name}}/api/group/{{g50_stamp_group}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [ { "table": "crud_test", "action": "update", "enforce": { "tipo": "$KEY_ID" } } ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_enforce"


PUT {{base_url}}/{{app_name}}/api/group/{{g50_stamp_group}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [ { "table": "crud_test", "action": "create", "enforce": { "tipo": "$KEY_ID", "periodo": "$TODAY", "descrizione": "$NOW" } } ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "rules_saved"


POST {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "label": "g50 stamp key", "privilege": 39, "group_id": {{g50_stamp_group}} }
HTTP 200
[Captures]
g50_stamp_key: jsonpath "$.key"
g50_stamp_key_id: jsonpath "$.id"


# The client may not choose a stamped value
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g50_stamp_key}}
Content-Type: application/json
{ "core": { "sigla": "K5001", "descrizione": "1999-01-01 00:00:00" } }
HTTP 200
[Asserts]
jsonpath "$.code" == "value_not_allowed"


POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{g50_stamp_key}}
Content-Type: application/json
{ "core": { "sigla": "K5002" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_stamped: jsonpath "$.id"


GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_stamped}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.tipo.val" == "{{g50_stamp_key_id}}"
jsonpath "$.core.descrizione.val" matches /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/
jsonpath "$.core.periodo.val" matches /^\d{4}-\d{2}-\d{2}$/


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_stamped}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/group/{{g50_stamp_group}}
Authorization: Bearer {{jwt}}
HTTP 200


# ── 50f. Cleanup ────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/group/{{g50_group}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/user/{{g50_user}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_in}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_out}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_made}}
Authorization: Bearer {{jwt}}
HTTP 200


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_filled}}
Authorization: Bearer {{jwt}}
HTTP 200
