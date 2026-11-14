# ════════════════════════════════════════════════════════════════════
# Phase 53 — `fields`: related rows nested in a list (Record\Expand)
#
# GET|POST /api/records/{tb}?fields=col,rel.*,alias:rel.col
#   plugin table      → list of rows under the key
#   id_from_tb field  → the referenced row replaces the id
# Without `fields` the list is exactly as before. Checked on crud_test
# (plugin crud_test_plg, self lookup parent_id).
# ════════════════════════════════════════════════════════════════════

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": { "sigla": "F5301", "tipo": "f53" },
  "plugins": {
    "crud_test_plg": [
      { "id": null, "_isNew": true, "_delete": false, "fields": { "tipo_misura": "Peso", "valore": 10, "note_plg": "a" } },
      { "id": null, "_isNew": true, "_delete": false, "fields": { "tipo_misura": "Altezza", "valore": 20, "note_plg": "b" } }
    ]
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
f53_parent: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "F5302", "tipo": "f53", "parent_id": {{f53_parent}} }, "plugins": {} }
HTTP 200
[Captures]
f53_child: jsonpath "$.id"

# ── plugin rows nested, only the columns asked for ───────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[sigla][_eq]=F5301&fields=sigla,crud_test_plg.tipo_misura
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total" == 1
jsonpath "$.data[0].sigla" == "F5301"
jsonpath "$.data[0].crud_test_plg" count == 2
jsonpath "$.data[0].crud_test_plg[0].tipo_misura" == "Peso"
jsonpath "$.data[0].crud_test_plg[0].valore" not exists
jsonpath "$.data[0].tipo" not exists

# ── alias, and the same through POST with an array ───────────────────
POST {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "filter": { "sigla": { "_eq": "F5301" } }, "fields": ["sigla", "misure:crud_test_plg.*"] }
HTTP 200
[Asserts]
jsonpath "$.data[0].misure" count == 2
jsonpath "$.data[0].misure[1].note_plg" == "b"
jsonpath "$.data[0].crud_test_plg" not exists

# ── lookup replaced by the referenced row ────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[sigla][_eq]=F5302&fields=sigla,parent_id.sigla
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[0].parent_id.sigla" == "F5301"
jsonpath "$.data[0].parent_id.id" == {{f53_parent}}

# ── without `fields` the list is as before ───────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[sigla][_eq]=F5301
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[0].crud_test_plg" not exists

# ── bad requests are refused, not guessed ────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?fields=nope.*
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code" == "invalid_parameters"

GET {{base_url}}/{{app_name}}/api/records/crud_test?fields=parent_id.parent_id.sigla
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_parameters"

# ── cleanup ───────────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{f53_child}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{f53_parent}}
Authorization: Bearer {{jwt}}
HTTP 200
