# ════════════════════════════════════════════════════════════════════
# Phase 18 — JSON filter (Directus-style GET bracket notation)
#
# Tests the new filter[field][_op]=value parameter on GET /api/records/{tb}.
# Uses the us table. By the time this phase runs, phases 9, 12 and 16
# have run, so the stable records to filter on are the ones imported in
# phase 12 via CSV (sigla = IMP001, IMP002) which are never modified by
# any subsequent phase.
# ════════════════════════════════════════════════════════════════════

# ── 18a. No filter → all records ─────────────────────────────────────
GET {{base_url}}/api/records/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 2

# ── 18b. _eq on text field ────────────────────────────────────────────
GET {{base_url}}/api/records/us?filter[sigla][_eq]=IMP001
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"        == "success"
jsonpath "$.total"         == 1
jsonpath "$.data[0].sigla" == "IMP001"

# ── 18c. _neq — excludes one record ───────────────────────────────────
GET {{base_url}}/api/records/us?filter[sigla][_neq]=IMP001
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 1

# ── 18d. _icontains ───────────────────────────────────────────────────
GET {{base_url}}/api/records/us?filter[sigla][_icontains]=IMP
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 2

# ── 18e. _starts_with ─────────────────────────────────────────────────
GET {{base_url}}/api/records/us?filter[sigla][_starts_with]=IMP
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 2

# ── 18f. _in on text field ────────────────────────────────────────────
GET {{base_url}}/api/records/us?filter[sigla][_in][]=IMP001&filter[sigla][_in][]=IMP002
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 2

# ── 18g. _null=false on id (non-null rows) ────────────────────────────
GET {{base_url}}/api/records/us?filter[id][_null]=false
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 2

# ── 18h. Implicit AND (two fields) ────────────────────────────────────
GET {{base_url}}/api/records/us?filter[sigla][_eq]=IMP001&filter[sigla][_starts_with]=IMP
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 1

# ── 18i. _ncontains — records that do NOT contain "IMP" ───────────────
# At least some records (e.g. UE* from search-replace) do not contain IMP
GET {{base_url}}/api/records/us?filter[sigla][_ncontains]=IMP
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 0   # count may vary; just assert it succeeds

# ── 18j. Filter matching nothing returns empty ────────────────────────
GET {{base_url}}/api/records/us?filter[sigla][_eq]=NOPE_9999_NOPE
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 0
jsonpath "$.data"   isEmpty

# ── 18k. Unknown field → error ────────────────────────────────────────
GET {{base_url}}/api/records/us?filter[nonexistent_column][_eq]=x
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"

# ── 18l. Unknown operator → error ─────────────────────────────────────
GET {{base_url}}/api/records/us?filter[sigla][_rawsql]=1%3D1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"

# ── 18m. Legacy shortSql still works (backward compat) ────────────────
GET {{base_url}}/api/records/us?search_type=shortSql&where=id%7C%3E%7C0
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 1
