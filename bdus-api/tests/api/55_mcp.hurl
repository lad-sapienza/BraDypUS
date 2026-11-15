# ════════════════════════════════════════════════════════════════════
# Phase 55 — MCP endpoint
#
# POST /{app}/api/mcp   one JSON-RPC message per request, JSON answer
#   - needs authentication (401) and the application's MCP switch (404)
#   - GET is not allowed (405)
#   - clients that open with `initialize` and clients of revision
#     2026-07-28 (per-request _meta + mirrored headers) are both served
#   - answers use HTTP status codes and JSON-RPC bodies, not the
#     status/code envelope of the rest of the API
# Settings: PUT /api/config/app { "mcp": { enabled, write, delete, guide } }
# ════════════════════════════════════════════════════════════════════

# ── no credentials ───────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 1, "method": "tools/list" }
HTTP 401

# ── MCP is off until an administrator switches it on ─────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 1, "method": "tools/list" }
HTTP 404
[Asserts]
jsonpath "$.error.code" == -32601

# ── switch it on (sent alone: the other settings are untouched) ──────
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "mcp": { "enabled": true, "guide": "Phase 55 notes" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.main.mcp.enabled" == true
jsonpath "$.main.mcp.write"   == false
jsonpath "$.main.mcp.delete"  == false
jsonpath "$.main.mcp.guide"   == "Phase 55 notes"
jsonpath "$.main.status"      == "on"

# ── GET is not part of the protocol ──────────────────────────────────
GET {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
HTTP 405

# ── handshake clients ────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 1, "method": "initialize",
  "params": { "protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": { "name": "hurl", "version": "1" } } }
HTTP 200
[Asserts]
header "Content-Type" contains "application/json"
header "Mcp-Session-Id" not exists
jsonpath "$.result.protocolVersion" == "2025-06-18"
jsonpath "$.result.serverInfo.name" == "bradypus"
jsonpath "$.result.capabilities.tools" exists

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "method": "notifications/initialized" }
HTTP 202

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 2, "method": "tools/list" }
HTTP 200
[Asserts]
jsonpath "$.result.tools" count == 5
jsonpath "$.result.tools[0].name" == "guide"
jsonpath "$.result.tools[3].name" == "records_read"
jsonpath "$.result.tools[3].inputSchema.type" == "object"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 3, "method": "tools/call", "params": { "name": "guide", "arguments": {} } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false
jsonpath "$.result.structuredContent.administrator_notes" == "Phase 55 notes"
jsonpath "$.result.structuredContent.may.write" == false

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 4, "method": "tools/call", "params": { "name": "schema", "arguments": {} } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false
jsonpath "$.result.structuredContent.tables[*].table" contains "crud_test"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 5, "method": "tools/call", "params": { "name": "schema", "arguments": { "table": "crud_test" } } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false
jsonpath "$.result.structuredContent.table" == "crud_test"
jsonpath "$.result.structuredContent.fields[*].name" contains "sigla"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 6, "method": "tools/call",
  "params": { "name": "records_read", "arguments": { "table": "crud_test", "limit": 1, "fields": ["sigla"] } } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == false
jsonpath "$.result.structuredContent.returned" <= 1
jsonpath "$.result.structuredContent.total" >= 0

# A refusal is a tool result the model can read, not an HTTP error
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 7, "method": "tools/call",
  "params": { "name": "records_read", "arguments": { "table": "bdus_users" } } }
HTTP 200
[Asserts]
jsonpath "$.result.isError" == true
jsonpath "$.result.structuredContent.code" == "unknown_table"

# ── revision 2026-07-28: no handshake, headers mirror the body ───────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
MCP-Protocol-Version: 2026-07-28
Mcp-Method: server/discover
{ "jsonrpc": "2.0", "id": "d", "method": "server/discover",
  "params": { "_meta": { "io.modelcontextprotocol/protocolVersion": "2026-07-28",
                         "io.modelcontextprotocol/clientCapabilities": {} } } }
HTTP 200
[Asserts]
jsonpath "$.result.resultType" == "complete"
jsonpath "$.result.supportedVersions[0]" == "2026-07-28"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
MCP-Protocol-Version: 2026-07-28
Mcp-Method: tools/call
Mcp-Name: schema
{ "jsonrpc": "2.0", "id": "c", "method": "tools/call",
  "params": { "name": "schema", "arguments": {},
              "_meta": { "io.modelcontextprotocol/protocolVersion": "2026-07-28",
                         "io.modelcontextprotocol/clientCapabilities": {} } } }
HTTP 200
[Asserts]
jsonpath "$.result.resultType" == "complete"
jsonpath "$.result.isError" == false

# header and body disagree
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
MCP-Protocol-Version: 2026-07-28
Mcp-Method: tools/list
Mcp-Name: schema
{ "jsonrpc": "2.0", "id": "c", "method": "tools/call",
  "params": { "name": "schema", "arguments": {},
              "_meta": { "io.modelcontextprotocol/protocolVersion": "2026-07-28",
                         "io.modelcontextprotocol/clientCapabilities": {} } } }
HTTP 400
[Asserts]
jsonpath "$.error.code" == -32020

# ── a browser page from elsewhere is refused ─────────────────────────
POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
Origin: https://evil.example
{ "jsonrpc": "2.0", "id": 8, "method": "tools/list" }
HTTP 403

# ── switch it off again ──────────────────────────────────────────────
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "mcp": { "enabled": false, "guide": "" } }
HTTP 200

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 9, "method": "tools/list" }
HTTP 404
