# ════════════════════════════════════════════════════════════════════
# Phase 30 — File sort (POST /api/files/sort)
#
# sortFiles() updates bdus_file_links.sort for an ordered array of link ids. Reordering a gallery
# changes the record it belongs to, so every link must exist and belong to a record the caller may
# change: an unknown link is refused (record_not_found), not silently ignored.
# ════════════════════════════════════════════════════════════════════


# ── 30a. A real reorder: a record with two files, reversed ───────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G301", "tipo": "g30" }, "plugins": {} }
HTTP 200
[Captures]
rec_30: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_30}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Captures]
file_30_a: jsonpath "$.file.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_30}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Captures]
file_30_b: jsonpath "$.file.id"

GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_30}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[0].id" == {{file_30_a}}
jsonpath "$.files[1].id" == {{file_30_b}}
[Captures]
link_30_a: jsonpath "$.files[0].link_id"
link_30_b: jsonpath "$.files[1].link_id"

POST {{base_url}}/{{app_name}}/api/files/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "order": [ {{link_30_b}}, {{link_30_a}} ] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_file_sorting_update"

GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_30}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[0].id" == {{file_30_b}}
jsonpath "$.files[1].id" == {{file_30_a}}


# ── 30a2. Links that do not exist are refused, nothing is changed ────
POST {{base_url}}/{{app_name}}/api/files/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "order": [ 9991, 9992, 9993 ] }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "record_not_found"

POST {{base_url}}/{{app_name}}/api/files/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "order": [ {{link_30_a}}, 9991 ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "record_not_found"

GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_30}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[0].id" == {{file_30_b}}


# ── 30b. Sort with empty order array → parameter_missing ─────────────
POST {{base_url}}/{{app_name}}/api/files/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "order": [] }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 30c. Missing order key entirely → parameter_missing ──────────────
POST {{base_url}}/{{app_name}}/api/files/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 30d. Cleanup ─────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/file/{{file_30_a}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/file/{{file_30_b}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_30}}
Authorization: Bearer {{jwt}}
HTTP 200
