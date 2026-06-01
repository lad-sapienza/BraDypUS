# ════════════════════════════════════════════════════════════════════
# Phase 4c — File upload, attach, sort, delete
#
# Tests the full file lifecycle on a crud_test record (us_id_1):
#   upload → verify in getRecord → sort → delete → verify gone
#
# File: fixtures/test_image.png (1×1 red pixel, 70 bytes)
# ════════════════════════════════════════════════════════════════════


# ── 4c-a. No files on record at start ────────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files" isCollection


# ── 4c-b. Upload file to record (upload + attach in one step) ────────
POST {{base_url}}/api/record/crud_test/{{us_id_1}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.code"            == "ok_file_uploaded"
jsonpath "$.file.id"         isInteger
jsonpath "$.file.ext"        == "png"
jsonpath "$.file.is_image"   == true
[Captures]
file_id: jsonpath "$.file.id"


# ── 4c-c. Upload a second file to test sort ───────────────────────────
POST {{base_url}}/api/record/crud_test/{{us_id_1}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
file_id_2: jsonpath "$.file.id"


# ── 4c-d. Read record — both files in gallery ────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files"        isCollection
jsonpath "$.files" count  == 2
jsonpath "$.files[0].ext" == "png"
[Captures]
link_id_1: jsonpath "$.files[0].link_id"
link_id_2: jsonpath "$.files[1].link_id"


# ── 4c-e. Sort files (reverse order) ─────────────────────────────────
POST {{base_url}}/api/files/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "order": [{{link_id_2}}, {{link_id_1}}] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_file_sorting_update"


# ── 4c-f. Verify sort applied ────────────────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[0].link_id" == {{link_id_2}}
jsonpath "$.files[1].link_id" == {{link_id_1}}


# ── 4c-g. Delete first file ───────────────────────────────────────────
DELETE {{base_url}}/api/file/{{file_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_file_deleted"


# ── 4c-h. Delete second file ──────────────────────────────────────────
DELETE {{base_url}}/api/file/{{file_id_2}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_file_deleted"


# ── 4c-i. Verify no files remain ──────────────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files" count == 0


# ── 4c-neg. Upload without file → error ──────────────────────────────
POST {{base_url}}/api/record/crud_test/{{us_id_1}}/file
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 4c-neg. Delete non-existent file → idempotent or not_found ────────
DELETE {{base_url}}/api/file/9999999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
