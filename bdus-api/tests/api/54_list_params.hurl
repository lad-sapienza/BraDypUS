# ════════════════════════════════════════════════════════════════════
# Phase 54 — Directus-style list parameters
#
# GET /api/records/{tb}?limit=&offset=&sort=a,-b&search=&meta=
#   limit=-1  everything, up to the cap (BRADYPUS_API_MAX_ROWS, default 5000)
#   sort      several fields, `-` = descending; sort_field/sort_dir still win
#   search    alone = fast search
#   meta      total_count / filter_count, only when asked
# Existing parameters (page, per_page, sort_field) keep working. Checked on
# three crud_test records (tipo f54).
# ════════════════════════════════════════════════════════════════════

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "P5401", "tipo": "f54" }, "plugins": {} }
HTTP 200
[Captures]
f54_1: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "P5402", "tipo": "f54" }, "plugins": {} }
HTTP 200
[Captures]
f54_2: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "P5403", "tipo": "f54" }, "plugins": {} }
HTTP 200
[Captures]
f54_3: jsonpath "$.id"

# ── limit + sort ─────────────────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[tipo][_eq]=f54&limit=2&sort=-sigla
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total" == 3
jsonpath "$.data" count == 2
jsonpath "$.data[0].sigla" == "P5403"
jsonpath "$.data[1].sigla" == "P5402"
jsonpath "$.truncated" not exists

# ── limit=-1: everything, and the response says nothing was cut ───────
GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[tipo][_eq]=f54&limit=-1&sort=sigla
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data" count == 3
jsonpath "$.data[0].sigla" == "P5401"
jsonpath "$.truncated" == false

# ── offset ───────────────────────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[tipo][_eq]=f54&limit=2&offset=2&sort=sigla
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data" count == 1
jsonpath "$.data[0].sigla" == "P5403"

# ── several sort fields through POST ─────────────────────────────────
POST {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "filter": { "tipo": { "_eq": "f54" } }, "sort": ["tipo", "-sigla"], "limit": -1 }
HTTP 200
[Asserts]
jsonpath "$.data[0].sigla" == "P5403"
jsonpath "$.data[2].sigla" == "P5401"

# ── search alone is a fast search (siti: no fuzzy-date columns) ──────
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Sito F54 Zeta" }, "plugins": {} }
HTTP 200
[Captures]
f54_site: jsonpath "$.id"

GET {{base_url}}/{{app_name}}/api/records/siti?search=F54+Zeta
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total" == 1
jsonpath "$.data[0].nome" == "Sito F54 Zeta"

# ── meta ─────────────────────────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[tipo][_eq]=f54&meta=total_count,filter_count&limit=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.meta.filter_count" == 3
jsonpath "$.meta.total_count" >= 3
jsonpath "$.total" == 3

GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[tipo][_eq]=f54
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.meta" not exists

# ── the older parameters are untouched ───────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[tipo][_eq]=f54&per_page=2&page=2&sort_field=sigla&sort_dir=asc
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data" count == 1
jsonpath "$.data[0].sigla" == "P5403"

# ── cleanup ──────────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{f54_1}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{f54_2}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{f54_3}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/siti/{{f54_site}}
Authorization: Bearer {{jwt}}
HTTP 200
