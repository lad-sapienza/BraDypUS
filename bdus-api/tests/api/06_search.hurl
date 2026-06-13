# ════════════════════════════════════════════════════════════════════
# Phase 6 — Search
# ════════════════════════════════════════════════════════════════════

# ── 6a. Get search UI config for us ─────────────────────────────────
GET {{base_url}}/api/search/crud_test/config
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.fields"    isCollection
jsonpath "$.operators" isCollection
# XOR dropped in v5: only AND/OR are valid JsonFilter connectors
jsonpath "$.connectors" count == 2
jsonpath "$.connectors" includes "AND"
jsonpath "$.connectors" includes "OR"


# ── 6b. Get distinct values used in a field ─────────────────────────
GET {{base_url}}/api/search/crud_test/values
Authorization: Bearer {{jwt}}
[QueryStringParams]
fld: periodo
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.values" isCollection


# ── 6c. Search by field value (GET, simple) ─────────────────────────
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{jwt}}
[QueryStringParams]
q_sigla: T001
HTTP 200
[Asserts]
jsonpath "$.status"        == "success"
jsonpath "$.total"         == 1
jsonpath "$.data[0].sigla" == "T001"


# ── 6d. Search via POST with Directus-style filter ───────────────────
POST {{base_url}}/api/records/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "filter": {
    "periodo": { "_eq": "Basso Medioevo" }
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 1


# ── 6e. Search with no results ──────────────────────────────────────
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{jwt}}
[QueryStringParams]
q_sigla: NONEXISTENT
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 0


# ── 6f. Lookup traversal (id_from_tb): parent record's field ─────────
# parent_id stores the id of the referenced record; the nested filter
# resolves it via subquery: parent_id IN (SELECT id FROM crud_test
# WHERE sigla = 'T001'). Record T002 (created in 4c) points to T001.
POST {{base_url}}/api/records/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "filter": {
    "parent_id": { "sigla": { "_eq": "T001" } }
  }
}
HTTP 200
[Asserts]
jsonpath "$.status"        == "success"
jsonpath "$.total"         == 1
jsonpath "$.data[0].sigla" == "T002"
