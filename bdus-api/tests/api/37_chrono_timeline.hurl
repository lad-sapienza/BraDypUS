# ════════════════════════════════════════════════════════════════════
# Phase 37 — Chrono timeline endpoint
#
# Routes exercised:
#   GET /api/chrono/timeline          — all fuzzy_date tables, no filter
#   GET /api/chrono/timeline?from=&to= — year-range filter
#   GET /api/chrono/timeline?tb[]=…   — per-table filter
#
# Requires: phase 19 seed (siti/us/reperti with chrono data)
# ════════════════════════════════════════════════════════════════════


# ── 37a. No filters — returns siti, us, reperti groups ──────────────
GET {{base_url}}/api/chrono/timeline
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"                    == "success"
jsonpath "$.tables"                    isCollection
# siti has 18 records all with chrono data
jsonpath "$.tables[?(@.tb_id == 'siti')].records" isCollection
# us has 7 records with chrono data seeded in 19i-bis
jsonpath "$.tables[?(@.tb_id == 'us')].records"   isCollection
# reperti has 9 records with chrono data seeded in 19i-bis
jsonpath "$.tables[?(@.tb_id == 'reperti')].records" isCollection
# Each record has the expected fields
jsonpath "$.tables[0].records[0].id"           isInteger
jsonpath "$.tables[0].records[0].from"         isInteger
jsonpath "$.tables[0].records[0].certainty"    isInteger


# ── 37b. Year-range filter: only records overlapping 0–500 CE ───────
GET {{base_url}}/api/chrono/timeline?from=0&to=500
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.tables"  isCollection
# Siti with range overlapping 0–500 should be present (sito_urbano -600/1800, sito_rurale -100/400)
jsonpath "$.tables[?(@.tb_id == 'siti')].records" isCollection


# ── 37c. Table filter — only reperti ────────────────────────────────
GET {{base_url}}/api/chrono/timeline?tb[]=reperti
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"      == "success"
jsonpath "$.tables"      isCollection
# Only reperti group returned
jsonpath "$.tables"      count == 1
jsonpath "$.tables[0].tb_id" == "reperti"


# ── 37d. Empty range — no matching records ───────────────────────────
GET {{base_url}}/api/chrono/timeline?from=5000&to=6000
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.tables"  count == 0


# ── 37e. Unauthenticated request returns error ───────────────────────
GET {{base_url}}/api/chrono/timeline
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"
