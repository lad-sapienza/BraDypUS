# ════════════════════════════════════════════════════════════════════
# Phase 60 — MCP: Zotero bibliography links
#
# The `bibliography` section of records_validate / records_write, the
# `bibliography` option of records_read and the tool bibliography_search,
# on every database engine. Citations are not fetched (no network in CI):
# links are stored without a cache, as in phase 17.
# Table: crud_test (has Zotero, phase 03).
# ════════════════════════════════════════════════════════════════════

PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "mcp": { "enabled": true, "write": true, "delete": false } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── a library to link to ─────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/zotero/lib
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "type": "group", "zotero_id": "7770060", "name": "Phase 60 library", "citation_style": "chicago-author-date" }
HTTP 200
[Captures]
lib60: jsonpath "$.id"

# ── bibliography_search without a query lists the libraries ──────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 1, "method": "tools/call",
  "params": { "name": "bibliography_search", "arguments": {} } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false
jsonpath "$.result.structuredContent.libraries[0].name" == "Phase 60 library"
jsonpath "$.result.structuredContent.libraries[0].api_key" not exists

# ── dry run of a new record with a link: nothing written ─────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 2, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test",
    "records": [ { "core": { "sigla": "P6001", "tipo": "f60" },
      "bibliography": { "add": [ { "lib_id": {{lib60}}, "zotero_key": "PH60KEY1", "pages": "3-4" } ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == true
jsonpath "$.result.structuredContent.results[0].bibliography.add[0].zotero_key" == "PH60KEY1"
[Captures]
vtoken60: jsonpath "$.result.structuredContent.validation_token"

GET {{base_url}}/{{app_name}}/api/records/crud_test?filter[sigla][_eq]=P6001
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == 0

# ── write: the record and its link together ──────────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 3, "method": "tools/call",
  "params": { "name": "records_write", "arguments": { "table": "crud_test",
    "records": [ { "core": { "sigla": "P6001", "tipo": "f60" },
      "bibliography": { "add": [ { "lib_id": {{lib60}}, "zotero_key": "PH60KEY1", "pages": "3-4" } ] } } ],
    "validation_token": "{{vtoken60}}" } } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false
jsonpath "$.result.structuredContent.written" == 1
[Captures]
rec60: jsonpath "$.result.structuredContent.results[0].id"

# ── read it back through MCP and through the REST API ────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 4, "method": "tools/call",
  "params": { "name": "records_read", "arguments": { "table": "crud_test",
    "filter": { "id": { "_eq": {{rec60}} } }, "bibliography": true } } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false
jsonpath "$.result.structuredContent.rows[0].bibliography" count == 1
jsonpath "$.result.structuredContent.rows[0].bibliography[0].zotero_key" == "PH60KEY1"
jsonpath "$.result.structuredContent.rows[0].bibliography[0].pages" == "3-4"
jsonpath "$.result.structuredContent.rows[0].bibliography[0].lib_name" == "Phase 60 library"
[Captures]
link60: jsonpath "$.result.structuredContent.rows[0].bibliography[0].id"

GET {{base_url}}/{{app_name}}/api/zotero/links/crud_test/{{rec60}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.links" count == 1
jsonpath "$.links[0].zotero_key" == "PH60KEY1"

# ── edit the link and add a second one ───────────────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 5, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test",
    "records": [ { "id": {{rec60}}, "core": {},
      "bibliography": { "edit": [ { "id": {{link60}}, "pages": "9", "notes": "edited" } ],
                        "add": [ { "lib_id": {{lib60}}, "zotero_key": "PH60KEY2", "sort": 2 } ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == true
[Captures]
vtoken60b: jsonpath "$.result.structuredContent.validation_token"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 6, "method": "tools/call",
  "params": { "name": "records_write", "arguments": { "table": "crud_test",
    "records": [ { "id": {{rec60}}, "core": {},
      "bibliography": { "edit": [ { "id": {{link60}}, "pages": "9", "notes": "edited" } ],
                        "add": [ { "lib_id": {{lib60}}, "zotero_key": "PH60KEY2", "sort": 2 } ] } } ],
    "validation_token": "{{vtoken60b}}" } } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false

GET {{base_url}}/{{app_name}}/api/zotero/links/crud_test/{{rec60}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.links" count == 2
jsonpath "$.links[?(@.zotero_key=='PH60KEY1')].pages" == "9"
jsonpath "$.links[?(@.zotero_key=='PH60KEY1')].notes" == "edited"

# ── a link that is not this record's is refused, nothing changes ─────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 7, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test",
    "records": [ { "id": {{rec60}}, "core": {}, "bibliography": { "remove": [ 99999999 ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == false
jsonpath "$.result.structuredContent.results[0].code" == "link_not_found"

# ── remove a link ─────────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 8, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test",
    "records": [ { "id": {{rec60}}, "core": {}, "bibliography": { "remove": [ {{link60}} ] } } ] } } }
HTTP 200
[Captures]
vtoken60c: jsonpath "$.result.structuredContent.validation_token"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 9, "method": "tools/call",
  "params": { "name": "records_write", "arguments": { "table": "crud_test",
    "records": [ { "id": {{rec60}}, "core": {}, "bibliography": { "remove": [ {{link60}} ] } } ],
    "validation_token": "{{vtoken60c}}" } } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false

GET {{base_url}}/{{app_name}}/api/zotero/links/crud_test/{{rec60}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.links" count == 1
jsonpath "$.links[0].zotero_key" == "PH60KEY2"

# ── clean up: the library (its links go with it) and the MCP switches ─
DELETE {{base_url}}/{{app_name}}/api/zotero/lib/{{lib60}}
Authorization: Bearer {{jwt}}
HTTP 200

PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "mcp": { "enabled": false, "write": false, "delete": false } }
HTTP 200
