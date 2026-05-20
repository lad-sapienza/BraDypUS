# ════════════════════════════════════════════════════════════════════
# Phase 6 — Search
# ════════════════════════════════════════════════════════════════════

# ── 6a. Get search UI config for us ─────────────────────────────────
GET {{base_url}}/api/search/us/config
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.fields"    isCollection
jsonpath "$.operators" isCollection


# ── 6b. Get distinct values used in a field ─────────────────────────
GET {{base_url}}/api/search/us/values
Authorization: Bearer {{jwt}}
[QueryStringParams]
field: periodo
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.values" isCollection


# ── 6c. Search by field value (GET, simple) ─────────────────────────
GET {{base_url}}/api/records/us
Authorization: Bearer {{jwt}}
[QueryStringParams]
q_sigla: US001
HTTP 200
[Asserts]
jsonpath "$.status"        == "success"
jsonpath "$.total"         == 1
jsonpath "$.data[0].sigla" == "US001"


# ── 6d. Search via POST with ShortSQL body ───────────────────────────
POST {{base_url}}/api/records/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "search": [
    { "field": "periodo", "operator": "=", "value": "Basso Medioevo" }
  ]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 1


# ── 6e. Search with no results ──────────────────────────────────────
GET {{base_url}}/api/records/us
Authorization: Bearer {{jwt}}
[QueryStringParams]
q_sigla: NONEXISTENT
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 0
