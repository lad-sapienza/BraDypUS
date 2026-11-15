# ════════════════════════════════════════════════════════════════════
# Phase 56 — MCP prompts (M054)
#
# Super-admin endpoints: GET|POST /api/mcp-prompts, PUT /api/mcp-prompts/order,
# PUT|DELETE /api/mcp-prompt/{id}. What the prompts look like to an MCP
# client: prompts/list and prompts/get on POST /api/mcp.
# ════════════════════════════════════════════════════════════════════

# MCP on, so that the endpoint can be read as a client reads it
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "mcp": { "enabled": true } }
HTTP 200

# ── create ───────────────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/mcp-prompts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "p56_report", "title": "End-of-season report", "description": "Summarises a season",
  "arguments": [ { "name": "site", "description": "Site name", "required": true },
                 { "name": "year", "required": false } ],
  "template": "Write the \u007b\u007byear}} report for \u007b\u007bsite}}." }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code" == "ok_mcp_prompt_saved"
jsonpath "$.prompt.name" == "p56_report"
jsonpath "$.prompt.arguments[0].required" == true
[Captures]
p1: jsonpath "$.prompt.id"

POST {{base_url}}/{{app_name}}/api/mcp-prompts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "p56_second", "template": "Just text" }
HTTP 200
[Captures]
p2: jsonpath "$.prompt.id"

# refused: duplicate name, undeclared placeholder, bad name, empty template
POST {{base_url}}/{{app_name}}/api/mcp-prompts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "p56_report", "template": "x" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code" == "prompt_name_taken"

POST {{base_url}}/{{app_name}}/api/mcp-prompts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "p56_bad", "template": "Hello \u007b\u007bwho}}" }
HTTP 200
[Asserts]
jsonpath "$.code" == "undeclared_prompt_placeholder"

POST {{base_url}}/{{app_name}}/api/mcp-prompts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "Bad Name", "template": "x" }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_prompt_name"

POST {{base_url}}/{{app_name}}/api/mcp-prompts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "p56_empty", "template": "   " }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_prompt_template"

# ── list: administrator and MCP client see the same prompts ──────────
GET {{base_url}}/{{app_name}}/api/mcp-prompts
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.prompts[*].name" contains "p56_report"
jsonpath "$.prompts[*].name" contains "p56_second"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 1, "method": "prompts/list" }
HTTP 200
[Asserts]
jsonpath "$.result.prompts[*].name" contains "p56_report"
jsonpath "$.result.prompts[?(@.name=='p56_report')].arguments[0].name" == "site"

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 2, "method": "prompts/get",
  "params": { "name": "p56_report", "arguments": { "site": "Castro", "year": "2026" } } }
HTTP 200
[Asserts]
jsonpath "$.result.messages[0].role" == "user"
jsonpath "$.result.messages[0].content.text" == "Write the 2026 report for Castro."

POST {{base_url}}/{{app_name}}/api/mcp
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "jsonrpc": "2.0", "id": 3, "method": "prompts/get", "params": { "name": "p56_report" } }
HTTP 200
[Asserts]
jsonpath "$.error.code" == -32602

# ── update, reorder ──────────────────────────────────────────────────
PUT {{base_url}}/{{app_name}}/api/mcp-prompt/{{p1}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "p56_report", "title": "Season report", "arguments": [ { "name": "site", "required": true } ],
  "template": "Report on \u007b\u007bsite}}." }
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_mcp_prompt_saved"
jsonpath "$.prompt.title" == "Season report"

PUT {{base_url}}/{{app_name}}/api/mcp-prompt/999999
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nothing", "template": "x" }
HTTP 200
[Asserts]
jsonpath "$.code" == "prompt_not_found"

PUT {{base_url}}/{{app_name}}/api/mcp-prompts/order
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "ids": [ {{p2}}, {{p1}} ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_mcp_prompts_reordered"

PUT {{base_url}}/{{app_name}}/api/mcp-prompts/order
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "ids": "nope" }
HTTP 200
[Asserts]
jsonpath "$.code" == "parameter_missing"

GET {{base_url}}/{{app_name}}/api/mcp-prompts
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.prompts[0].name" == "p56_second"
jsonpath "$.prompts[1].name" == "p56_report"

# ── delete, then leave nothing behind ────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/mcp-prompt/{{p1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_mcp_prompt_deleted"

DELETE {{base_url}}/{{app_name}}/api/mcp-prompt/{{p1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "prompt_not_found"

DELETE {{base_url}}/{{app_name}}/api/mcp-prompt/{{p2}}
Authorization: Bearer {{jwt}}
HTTP 200

GET {{base_url}}/{{app_name}}/api/mcp-prompts
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.prompts" count == 0

# ── no credentials ───────────────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/mcp-prompts
HTTP 401

# MCP off again, as it was
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "mcp": { "enabled": false } }
HTTP 200
