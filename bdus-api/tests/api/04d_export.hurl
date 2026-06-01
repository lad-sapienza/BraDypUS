# ════════════════════════════════════════════════════════════════════
# Phase 4d — Export (CSV, JSON, XLSX)
#
# Tests GET /api/records/{tb}/export for each format.
# At this point crud_test has 2 records (T001, T002).
# Checks response headers and body shape.
# ════════════════════════════════════════════════════════════════════


# ── 4d-a. Export CSV (default format) ────────────────────────────────
GET {{base_url}}/api/records/crud_test/export?format=csv
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type"        contains "text/csv"
header "Content-Disposition" contains "crud_test"
header "Content-Disposition" contains ".csv"
# Body is text — verify it contains the sigla column header
body contains "sigla"
body contains "T001"
body contains "T002"


# ── 4d-b. Export JSON ────────────────────────────────────────────────
GET {{base_url}}/api/records/crud_test/export?format=json
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type"        contains "application/json"
header "Content-Disposition" contains ".json"
# The JSON export is a raw array (not an API-envelope), so body assertions only
body contains "T001"
body contains "T002"


# ── 4d-c. Export XLSX ────────────────────────────────────────────────
GET {{base_url}}/api/records/crud_test/export?format=xlsx
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type"        contains "spreadsheetml"
header "Content-Disposition" contains ".xlsx"


# ── 4d-d. Export with filter — only T001 ─────────────────────────────
GET {{base_url}}/api/records/crud_test/export?format=csv&filter[sigla][_eq]=T001
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "text/csv"
body     contains "T001"
body not contains "T002"


# ── 4d-e. Export unknown format → default CSV (no error) ─────────────
GET {{base_url}}/api/records/crud_test/export?format=unknown
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "text/csv"


# ── 4d-neg. Export without auth → 401 ────────────────────────────────
GET {{base_url}}/api/records/crud_test/export?format=csv
HTTP 401
