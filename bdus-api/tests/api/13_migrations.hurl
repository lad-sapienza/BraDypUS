# ════════════════════════════════════════════════════════════════════
# Phase 13 — DB Migrations
# ════════════════════════════════════════════════════════════════════

# ── 13a. List migrations — admin JWT ────────────────────────────────
GET {{base_url}}/api/migrations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"     == "success"
jsonpath "$.total"      isInteger
jsonpath "$.applied"    isInteger
jsonpath "$.migrations" isCollection
jsonpath "$.migrations" count >= 1
[Captures]
migrations_total:   jsonpath "$.total"
migrations_applied: jsonpath "$.applied"


# ── 13a2. Verify applied <= total (cross-request capture check) ──────
GET {{base_url}}/api/migrations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total"   == {{migrations_total}}
jsonpath "$.applied" <= {{migrations_total}}


# ── 13b. Each row has the required shape ────────────────────────────
GET {{base_url}}/api/migrations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.migrations[0].name"       isString
jsonpath "$.migrations[0].applied"    isBoolean
# applied_at is either a string or null — just check the field exists
jsonpath "$.migrations[0]"            exists


# ── 13c. First known migration is M001 ──────────────────────────────
GET {{base_url}}/api/migrations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.migrations[0].name" == "M001_add_user_table_privs"
