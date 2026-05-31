# ════════════════════════════════════════════════════════════════════
# Phase 26 — Saved queries
#
# Routes exercised:
#   GET    /api/saved-queries              list queries visible to user
#   POST   /api/saved-queries              save a new query
#   POST   /api/saved-query/{id}/share     share with all users
#   POST   /api/saved-query/{id}/unshare   make private again
#   DELETE /api/saved-query/{id}           delete
# ════════════════════════════════════════════════════════════════════


# ── 26a. List — empty at start ───────────────────────────────────────
GET {{base_url}}/api/saved-queries
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.queries" isCollection
jsonpath "$.queries" isEmpty


# ── 26b. Save a query for table "us" ─────────────────────────────────
POST {{base_url}}/api/saved-queries
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":  "All US in periodo Classico",
  "tb":    "us",
  "query": { "search_type": "fast", "search": "Classico" }
}
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.code"      == "ok_saved_query"
jsonpath "$.query.id"  isInteger
[Captures]
sq_id: jsonpath "$.query.id"


# ── 26c. Query appears in list ───────────────────────────────────────
GET {{base_url}}/api/saved-queries
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.queries"          count == 1
jsonpath "$.queries[0].id"    == {{sq_id}}
jsonpath "$.queries[0].name"  == "All US in periodo Classico"
jsonpath "$.queries[0].tb"    isString


# ── 26d. Save a second query ─────────────────────────────────────────
POST {{base_url}}/api/saved-queries
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":  "Siti with coordinate",
  "tb":    "siti",
  "query": { "search_type": "fast", "search": "coordinate" }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
sq_id2: jsonpath "$.query.id"


# ── 26e. Share the first query ───────────────────────────────────────
POST {{base_url}}/api/saved-query/{{sq_id}}/share
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_sharing_query"


# ── 26f. Unshare the first query ─────────────────────────────────────
POST {{base_url}}/api/saved-query/{{sq_id}}/unshare
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_unsharing_query"


# ── 26g. Delete the first query ──────────────────────────────────────
DELETE {{base_url}}/api/saved-query/{{sq_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_erasing_query"


# ── 26h. Delete the second query ─────────────────────────────────────
DELETE {{base_url}}/api/saved-query/{{sq_id2}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_erasing_query"


# ── 26i. List empty again ────────────────────────────────────────────
GET {{base_url}}/api/saved-queries
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.queries" isEmpty


# ── 26j. Missing name → parameter_missing ───────────────────────────
POST {{base_url}}/api/saved-queries
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "query": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 26k. Missing tb → parameter_missing ─────────────────────────────
POST {{base_url}}/api/saved-queries
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "No table", "query": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"
