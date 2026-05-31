# ════════════════════════════════════════════════════════════════════
# Phase 16 — Welcome text + Search & Replace
#
# Routes:
#   GET  /api/welcome                          read welcome Markdown
#   PUT  /api/welcome                          save welcome Markdown (admin)
#   GET  /api/search-replace/tables            list tables (admin)
#   GET  /api/search-replace/{tb}/fields       list text fields for table (admin)
#   POST /api/search-replace                   bulk REPLACE() on a field (admin)
#
# Prerequisites:
#   - Table "us" exists (from phase 3) with text fields: sigla, interpretazione, periodo
#   - At least one "us" record exists (from phase 4: us_id_1, us_id_2)
# ════════════════════════════════════════════════════════════════════


# ── 16a. GET welcome — returns initial seeded text ───────────────────
GET {{base_url}}/api/welcome
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.content" isString


# ── 16b. PUT welcome — save new content ──────────────────────────────
PUT {{base_url}}/api/welcome
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "content": "# Test Welcome\n\nThis is the updated dashboard text for the automated test."
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_save"


# ── 16c. GET welcome — content must match what we just saved ─────────
GET {{base_url}}/api/welcome
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.content" == "# Test Welcome\n\nThis is the updated dashboard text for the automated test."


# ── 16d. PUT welcome — PHP tags must be stripped ─────────────────────
PUT {{base_url}}/api/welcome
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "content": "Safe text <?php echo 'evil'; ?> more text"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 16e. Verify PHP tags were stripped from stored content ────────────
GET {{base_url}}/api/welcome
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
# The response body should not contain PHP opening tags (raw string check)
jsonpath "$.content" not contains "<?php"
jsonpath "$.content" not contains "<?"


# ── 16f. GET search-replace tables ───────────────────────────────────
GET {{base_url}}/api/search-replace/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.tables"  isCollection
# The tables created in phase 3 must be listed
jsonpath "$.tables[*].name" contains "us"
jsonpath "$.tables[*].name" contains "siti"


# ── 16g. Table list row shape ─────────────────────────────────────────
GET {{base_url}}/api/search-replace/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[0].name"  isString
jsonpath "$.tables[0].label" isString


# ── 16h. GET fields for table "us" ────────────────────────────────────
GET {{base_url}}/api/search-replace/us/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.fields"  isCollection
# sigla and interpretazione are type=text; descrizione is type=textarea — all included
jsonpath "$.fields[*].name" contains "sigla"
jsonpath "$.fields[*].name" contains "interpretazione"
jsonpath "$.fields[*].name" contains "descrizione"


# ── 16i. Field list row shape ─────────────────────────────────────────
GET {{base_url}}/api/search-replace/us/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.fields[0].name"  isString
jsonpath "$.fields[0].label" isString


# ── 16j. GET fields for non-existent table → empty field list ─────────
# The controller does not error on unknown tables; it returns an empty list
# (same as a table with no text fields).
GET {{base_url}}/api/search-replace/no_such_table/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.fields" count == 0


# ── 16k. POST search-replace — bulk replace in us.sigla ──────────────
# Phase 4 created records with sigla values like "US001", "US002".
# Replace "US" → "UE" to test the endpoint.
POST {{base_url}}/api/search-replace
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb":      "us",
  "fld":     "sigla",
  "search":  "US",
  "replace": "UE"
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.code"     == "ok_search_replace"
jsonpath "$.affected" isInteger


# ── 16l-neg. POST search-replace — unknown table → error ─────────────
POST {{base_url}}/api/search-replace
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb":     "no_such_table",
  "fld":    "name",
  "search": "x"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 16m-neg. POST search-replace — unknown field → error ─────────────
POST {{base_url}}/api/search-replace
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb":     "us",
  "fld":    "injected_col; DROP TABLE us;--",
  "search": "x"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 16n-neg. POST search-replace — missing search → error ────────────
POST {{base_url}}/api/search-replace
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb":  "us",
  "fld": "sigla"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"
