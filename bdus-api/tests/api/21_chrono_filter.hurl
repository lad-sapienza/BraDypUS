# ════════════════════════════════════════════════════════════════════
# Phase 21 — Chrono filter (_chrono_overlap operator)
#
# Depends on: phase 20 (fuzzy-date plugin activated on us table,
#             US099 normal -325/-301 probable, US100 ante quem null/-300,
#             US101 post quem -400/null are already present).
#
# Adds 3 more records (outside/undated/ante-early), then verifies:
#   - overlap window [-350,-320] returns exactly the 3 phase-20 records
#   - undated, outside-range, ante-quem-before-window are excluded
#   - chrono_certainty _eq filter works
#   - wrong field for _chrono_overlap → error
#   - chrono_from unavailable on table without plugin → error
# ════════════════════════════════════════════════════════════════════


# ── 21a. Seed: outside window (-200 to -100) ──────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": null,
  "core": { "sigla": "US200", "chrono_from": -200, "chrono_to": -100, "chrono_certainty": "certain" },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 21b. Seed: undated (both null) ────────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": null,
  "core": { "sigla": "US201", "chrono_from": null, "chrono_to": null },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 21c. Seed: ante quem before window (null to -450) ─────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": null,
  "core": { "sigla": "US202", "chrono_from": null, "chrono_to": -450, "chrono_certainty": "certain" },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 21d. Overlap query window [-350,-320] → exactly 3 hits ───────────
# Matches: US099 (normal -325/-301), US100 (ante quem null/-300 ≥ -350),
#          US101 (post quem -400/null, -400 ≤ -320)
# Excludes: US200 (-200/-100), US201 (null/null), US202 (null/-450 < -350)
#           us_id_1, us_id_2 (no chrono — both null → undated)
GET {{base_url}}/api/records/crud_test?search_type=filter&filter=%7B%22chrono_from%22%3A%7B%22_chrono_overlap%22%3A%5B-350%2C-320%5D%7D%7D
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 3


# ── 21e. Wide window covers all non-undated records ───────────────────
# Total records: 2 (phase 04) + 3 (phase 20) + 3 (phase 21) = 8
# Undated: us_id_1 (null/null), us_id_2 (null/null), US201 (null/null) → 3 excluded
# Non-undated: US099, US100, US101, US200, US202 + the 2 phase-04 have no chrono →
# Actually phase-04 records have null/null → excluded
# Non-undated with any chrono: US099, US100, US101, US200, US202 = 5
GET {{base_url}}/api/records/crud_test?search_type=filter&filter=%7B%22chrono_from%22%3A%7B%22_chrono_overlap%22%3A%5B-10000%2C10000%5D%7D%7D
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 5


# ── 21f. Filter by chrono_certainty = probable ────────────────────────
# US100 (ante quem, probable) is the only probable record
GET {{base_url}}/api/records/crud_test?search_type=filter&filter=%7B%22chrono_certainty%22%3A%7B%22_eq%22%3A%22probable%22%7D%7D
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 1


# ── 21g-neg. _chrono_overlap on chrono_to → error ─────────────────────
GET {{base_url}}/api/records/crud_test?search_type=filter&filter=%7B%22chrono_to%22%3A%7B%22_chrono_overlap%22%3A%5B-400%2C-300%5D%7D%7D
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"


# ── 21h-neg. chrono_from on table without plugin → error ──────────────
GET {{base_url}}/api/records/periodi?search_type=filter&filter=%7B%22chrono_from%22%3A%7B%22_null%22%3Atrue%7D%7D
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
