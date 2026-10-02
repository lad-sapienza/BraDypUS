# ════════════════════════════════════════════════════════════════════
# Phase 4d — Export (CSV, JSON, XLSX)
#
# Tests GET /api/records/{tb}/export for each format.
# At this point crud_test has 2 records (T001, T002).
# Checks response headers and body shape.
# ════════════════════════════════════════════════════════════════════


# ── 4d-a. Export CSV (default format) ────────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv
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
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=json
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type"        contains "application/json"
header "Content-Disposition" contains ".json"
# The JSON export is a raw array (not an API-envelope), so body assertions only
body contains "T001"
body contains "T002"


# ── 4d-c. Export XLSX ────────────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=xlsx
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type"        contains "spreadsheetml"
header "Content-Disposition" contains ".xlsx"


# ── 4d-d. Export with filter — only T001 ─────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv&filter[sigla][_eq]=T001
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "text/csv"
body     contains "T001"
body not contains "T002"


# ── 4d-d2. Export with qt=filter&q=JSON (advanced-search URL persistence) ──
# DataView persists the advanced search as qt=filter with the JSON filter in q;
# the export must honour it instead of dumping the whole table.
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv&qt=filter&q=%7B%22sigla%22%3A%7B%22_eq%22%3A%22T002%22%7D%7D
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "text/csv"
body     contains "T002"
body not contains "T001"


# ── 4d-d3. Export with qt=advanced (the query builder's URL state) ───────
# DataView persists the builder as qt=advanced&q={tree, filter}; the ready-made
# JsonFilter is under "filter". Before this was honoured the export silently
# dumped the whole table (#75).
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv&qt=advanced&q=%7B%22tree%22%3A%7B%22t%22%3A%22g%22%2C%22op%22%3A%22AND%22%2C%22c%22%3A%5B%7B%22t%22%3A%22c%22%2C%22fld%22%3A%22crud_test%3Asigla%22%2C%22operator%22%3A%22_eq%22%2C%22value%22%3A%22T002%22%7D%5D%7D%2C%22filter%22%3A%7B%22sigla%22%3A%7B%22_eq%22%3A%22T002%22%7D%7D%7D
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "text/csv"
body     contains "T002"
body not contains "T001"


# ── 4d-d4. Same, with the previous {rows, filter} payload (old bookmarks) ──
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv&qt=advanced&q=%7B%22rows%22%3A%5B%7B%22connector%22%3A%22AND%22%2C%22fld%22%3A%22crud_test%3Asigla%22%2C%22operator%22%3A%22_eq%22%2C%22value%22%3A%22T002%22%7D%5D%2C%22filter%22%3A%7B%22sigla%22%3A%7B%22_eq%22%3A%22T002%22%7D%7D%7D
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "text/csv"
body     contains "T002"
body not contains "T001"


# ── 4d-d5. A search parameter that cannot be read is an error, not "everything" ──
# (#75: silently exporting the whole table is far more data than the list shows)
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv&qt=advanced&q=not-json
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "application/json"
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_parameters"
jsonpath "$.detail" contains "qt=advanced"


GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv&qt=filter&q=not-json
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_parameters"


GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv&qt=bogus&q=x
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_parameters"
jsonpath "$.detail" contains "qt: unknown value"


GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv&qt=fast
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_parameters"
jsonpath "$.detail" contains "q is required"


GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv&filter=not-json
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_parameters"


# ── 4d-e. Export unknown format → error, no silent CSV fallback ──────
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=unknown
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "application/json"
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_parameters"


# ── 4d-neg. Export without auth → 401 ────────────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test/export?format=csv
HTTP 401
