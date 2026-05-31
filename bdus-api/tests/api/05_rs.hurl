# ════════════════════════════════════════════════════════════════════
# Phase 5 — Stratigraphic Relations (Harris Matrix)
#
# Relation codes (see record_ctrl::rsRelations):
#   1 = is_covered_by   ↔  5 = covers
#   2 = is_cut_by       ↔  6 = cuts
#   3 = carries         ↔  7 = leans_against
#   4 = is_filled_by    ↔  8 = fills
#   9 = is_the_same_as  (symmetric)
#  10 = is_bound_to     (symmetric)
# ════════════════════════════════════════════════════════════════════

# ── 5a. Add relation: US001 is_covered_by US002 (relation = 1) ──────
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb":       "us",
  "first":    {{us_id_1}},
  "relation": 1,
  "second":   {{us_id_2}}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_relation_add"
jsonpath "$.id"     isInteger
[Captures]
rs_id_1: jsonpath "$.id"


# ── 5b. Duplicate must be rejected ──────────────────────────────────
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb":       "us",
  "first":    {{us_id_1}},
  "relation": 1,
  "second":   {{us_id_2}}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "relation_already_exist"


# ── 5c. Get RS matrix ───────────────────────────────────────────────
GET {{base_url}}/api/rs/matrix
Authorization: Bearer {{jwt}}
[QueryStringParams]
tb: us
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.nodes"     isCollection
jsonpath "$.relations" isCollection


# ── 5d. Delete the relation ──────────────────────────────────────────
DELETE {{base_url}}/api/rs/{{rs_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 5e. has_fuzzy_date=false when plugin not yet active ─────────────
GET {{base_url}}/api/rs/matrix
Authorization: Bearer {{jwt}}
[QueryStringParams]
tb: us
HTTP 200
[Asserts]
jsonpath "$.status"         == "success"
jsonpath "$.has_fuzzy_date" == false
