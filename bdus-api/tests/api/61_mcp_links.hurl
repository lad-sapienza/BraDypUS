# ════════════════════════════════════════════════════════════════════
# Phase 61 — MCP: manual links between records
#
# The `links` section of records_validate / records_write and the `links`
# option of records_read, on every database engine, cross-checked with the
# REST endpoints POST /api/manual-link and DELETE /api/manual-link/{id}
# (which share Record\ManualLinks). Table: crud_test (phase 03).
# ════════════════════════════════════════════════════════════════════

PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "mcp": { "enabled": true, "write": true, "delete": false } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── two records to link ──────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 1, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test", "records": [ { "core": { "sigla": "P6101", "tipo": "f61" } }, { "core": { "sigla": "P6102", "tipo": "f61" } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == true
[Captures]
vtoken61a: jsonpath "$.result.structuredContent.validation_token"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 2, "method": "tools/call",
  "params": { "name": "records_write", "arguments": { "table": "crud_test", "records": [ { "core": { "sigla": "P6101", "tipo": "f61" } }, { "core": { "sigla": "P6102", "tipo": "f61" } } ], "validation_token": "{{vtoken61a}}" } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.written" == 2
[Captures]
recA61: jsonpath "$.result.structuredContent.results[0].id"
recB61: jsonpath "$.result.structuredContent.results[1].id"

# ── dry run: a link from A to B, nothing written ──────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 3, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test", "records": [ { "id": {{recA61}}, "core": {}, "links": { "add": [ { "table": "crud_test", "id": {{recB61}}, "label": "phase 61" } ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == true
jsonpath "$.result.structuredContent.results[0].links.add[0].label" == "phase 61"
[Captures]
vtoken61b: jsonpath "$.result.structuredContent.validation_token"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 4, "method": "tools/call",
  "params": { "name": "records_read", "arguments": { "table": "crud_test", "filter": { "id": { "_eq": {{recA61}} } }, "links": true } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.rows[0].links" count == 0

# ── write, then read the link from both records ───────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 5, "method": "tools/call",
  "params": { "name": "records_write", "arguments": { "table": "crud_test", "records": [ { "id": {{recA61}}, "core": {}, "links": { "add": [ { "table": "crud_test", "id": {{recB61}}, "label": "phase 61" } ] } } ], "validation_token": "{{vtoken61b}}" } } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 6, "method": "tools/call",
  "params": { "name": "records_read", "arguments": { "table": "crud_test", "filter": { "id": { "_eq": {{recA61}} } }, "links": true } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.rows[0].links" count == 1
jsonpath "$.result.structuredContent.rows[0].links[0].table" == "crud_test"
jsonpath "$.result.structuredContent.rows[0].links[0].record_id" == {{recB61}}
jsonpath "$.result.structuredContent.rows[0].links[0].label" == "phase 61"
[Captures]
link61: jsonpath "$.result.structuredContent.rows[0].links[0].id"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 7, "method": "tools/call",
  "params": { "name": "records_read", "arguments": { "table": "crud_test", "filter": { "id": { "_eq": {{recB61}} } }, "links": true } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.rows[0].links" count == 1
jsonpath "$.result.structuredContent.rows[0].links[0].record_id" == {{recA61}}

# ── refusals carry a code ─────────────────────────────────────────────
# the same pair from the other record
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 8, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test", "records": [ { "id": {{recB61}}, "core": {}, "links": { "add": [ { "table": "crud_test", "id": {{recA61}} } ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == false
jsonpath "$.result.structuredContent.results[0].code" == "link_already_exists"

# a record that does not exist
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 9, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test", "records": [ { "id": {{recA61}}, "core": {}, "links": { "add": [ { "table": "crud_test", "id": 99999999 } ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == false
jsonpath "$.result.structuredContent.results[0].code" == "record_not_found"

# a record with itself
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 10, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test", "records": [ { "id": {{recA61}}, "core": {}, "links": { "add": [ { "table": "crud_test", "id": {{recA61}} } ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == false
jsonpath "$.result.structuredContent.results[0].code" == "link_with_itself"

# a link that is not this record's
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 11, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test", "records": [ { "id": {{recA61}}, "core": {}, "links": { "remove": [ 99999999 ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == false
jsonpath "$.result.structuredContent.results[0].code" == "link_not_found"

# an unknown table is refused with its own code
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 12, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test", "records": [ { "id": {{recA61}}, "core": {}, "links": { "add": [ { "table": "no_such_table", "id": 1 } ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == false
jsonpath "$.result.structuredContent.results[0].code" == "unknown_table"

# ── REST sees the link: adding it again is a duplicate ───────────────
POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "crud_test", "id_one": {{recB61}}, "tb_two": "crud_test", "id_two": {{recA61}} }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code" == "link_already_exists"

# ── remove through MCP ────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 13, "method": "tools/call",
  "params": { "name": "records_validate", "arguments": { "table": "crud_test", "records": [ { "id": {{recA61}}, "core": {}, "links": { "remove": [ {{link61}} ] } } ] } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.valid" == true
[Captures]
vtoken61c: jsonpath "$.result.structuredContent.validation_token"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 14, "method": "tools/call",
  "params": { "name": "records_write", "arguments": { "table": "crud_test", "records": [ { "id": {{recA61}}, "core": {}, "links": { "remove": [ {{link61}} ] } } ], "validation_token": "{{vtoken61c}}" } } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 15, "method": "tools/call",
  "params": { "name": "records_read", "arguments": { "table": "crud_test", "filter": { "id": { "_eq": {{recB61}} } }, "links": true } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.rows[0].links" count == 0

# ── a link made through REST is read through MCP, then deleted ───────
POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "crud_test", "id_one": {{recA61}}, "tb_two": "crud_test", "id_two": {{recB61}}, "label": "via rest" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
restlink61: jsonpath "$.link.key"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 16, "method": "tools/call",
  "params": { "name": "records_read", "arguments": { "table": "crud_test", "filter": { "id": { "_eq": {{recB61}} } }, "links": true } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.rows[0].links[0].id" == {{restlink61}}
jsonpath "$.result.structuredContent.rows[0].links[0].label" == "via rest"

DELETE {{base_url}}/{{app_name}}/api/manual-link/{{restlink61}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 17, "method": "tools/call",
  "params": { "name": "records_read", "arguments": { "table": "crud_test", "filter": { "id": { "_eq": {{recA61}} } }, "links": true } } }
HTTP 200
[Asserts]
jsonpath "$.result.structuredContent.rows[0].links" count == 0

# ── switch MCP writing off again ──────────────────────────────────────
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "mcp": { "enabled": false, "write": false, "delete": false } }
HTTP 200
