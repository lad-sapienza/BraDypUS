# ════════════════════════════════════════════════════════════════════
# Phase 34 — File management (GET /api/files, PATCH /api/file/{id},
#             POST /api/file/{id}/replace)
#
# Lifecycle:
#   a. Upload a file to crud_test record → capture file_id_34
#   b. GET /api/files — list includes the file
#   c. PATCH /api/file/{id} — update description & keywords
#   d. GET /api/files — verify metadata persisted
#   e. GET /api/files?orphans_only=1 — file is linked, not returned
#   f. POST /api/file/{id}/replace — swap binary, get new ext/filename
#   g. Negative: PATCH non-existent file → record_not_found
#   h. DELETE file to clean up
# ════════════════════════════════════════════════════════════════════


# ── 34a. Upload a test file to a crud_test record ────────────────────
POST {{base_url}}/api/record/crud_test/{{us_id_1}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status"        == "success"
jsonpath "$.code"          == "ok_file_uploaded"
jsonpath "$.file.id"       isInteger
jsonpath "$.file.ext"      == "png"
jsonpath "$.file.is_image" == true
[Captures]
file_id_34: jsonpath "$.file.id"


# ── 34b. GET /api/files — our file appears in the list ──────────────
GET {{base_url}}/api/files
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.total"    isInteger
jsonpath "$.total"    > 0
jsonpath "$.page"     == 1
jsonpath "$.per_page" isInteger
jsonpath "$.files"    isCollection


# ── 34c. PATCH /api/file/{id} — update description and keywords ──────
PATCH {{base_url}}/api/file/{{file_id_34}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "description": "Test description for phase 34", "keywords": "hurl test automation" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_file_updated"


# ── 34d. GET /api/files — verify metadata persisted ─────────────────
GET {{base_url}}/api/files
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.files"  isCollection


# ── 34e. GET /api/files?orphans_only=1 — linked file not returned ────
GET {{base_url}}/api/files?orphans_only=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.files"  isCollection


# ── 34f. POST /api/file/{id}/replace — swap binary ───────────────────
POST {{base_url}}/api/file/{{file_id_34}}/replace
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.code"     == "ok_file_replaced"
jsonpath "$.ext"      isString
jsonpath "$.filename" isString
jsonpath "$.is_image" == true


# ── 34g. Negative: PATCH non-existent file → record_not_found ────────
PATCH {{base_url}}/api/file/9999999
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "description": "should fail" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "record_not_found"


# ── 34h. DELETE file to clean up ─────────────────────────────────────
DELETE {{base_url}}/api/file/{{file_id_34}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_file_deleted"
