# ════════════════════════════════════════════════════════════════════
# Phase 64 — Deleting a record: what happens to the files only it uses
#
# GET /api/record/{tb}/files-before-delete lists them; DELETE /api/record/{tb}/{id} takes `files`:
# omitted → KEEP (orphans), "delete", or a map { fileId: "delete" | "keep" }. A file shared with a
# record that stays is never listed nor deleted.
# ════════════════════════════════════════════════════════════════════


# ── 64a. Setup: two records; A has an own file and a shared one ──────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G641", "tipo": "g64" }, "plugins": {} }
HTTP 200
[Captures]
rec_a: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G642", "tipo": "g64" }, "plugins": {} }
HTTP 200
[Captures]
rec_b: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_a}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Captures]
file_own: jsonpath "$.file.id"
uuid_own: jsonpath "$.file.uuid"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_a}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Captures]
file_shared: jsonpath "$.file.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_b}}/link-file
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "fileId": {{file_shared}} }
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_file_linked"


# ── 64b. The question: only the own file, not the shared one ─────────
GET {{base_url}}/{{app_name}}/api/record/crud_test/files-before-delete?id={{rec_a}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.files" count == 1
jsonpath "$.files[0].id" == {{file_own}}
jsonpath "$.files[0].uuid" == "{{uuid_own}}"
jsonpath "$.files[0].is_image" == true
jsonpath "$.files[0].record_ids[0]" == {{rec_a}}


# ── 64c. No answer: the own file is KEPT, as an orphan ───────────────
DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_a}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "all_record_deleted"
jsonpath "$.orphaned_files" count == 1
jsonpath "$.orphaned_files[0]" == {{file_own}}
jsonpath "$.deleted_files" count == 0

# the orphan is still there and readable by an administrator; the shared one is still on B
GET {{base_url}}/{{app_name}}/api/file/{{uuid_own}}
Authorization: Bearer {{jwt}}
HTTP 200
Content-Type: image/png

GET {{base_url}}/{{app_name}}/api/files?orphans_only=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[?(@.id == {{file_own}})].uuid" == "{{uuid_own}}"

GET {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_b}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[0].id" == {{file_shared}}


# ── 64d. "delete": the file goes with its record (binary included) ───
POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_b}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Captures]
file_b_own: jsonpath "$.file.id"
uuid_b_own: jsonpath "$.file.uuid"

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_b}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "files": "delete" }
HTTP 200
[Asserts]
jsonpath "$.code" == "all_record_deleted"
jsonpath "$.deleted_files" count == 2
jsonpath "$.orphaned_files" count == 0

GET {{base_url}}/{{app_name}}/api/file/{{uuid_b_own}}
Authorization: Bearer {{jwt}}
HTTP 404


# ── 64e. A choice per file ───────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G643", "tipo": "g64" }, "plugins": {} }
HTTP 200
[Captures]
rec_c: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_c}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Captures]
file_c1: jsonpath "$.file.id"
uuid_c1: jsonpath "$.file.uuid"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_c}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Captures]
file_c2: jsonpath "$.file.id"
uuid_c2: jsonpath "$.file.uuid"

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_c}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "files": { "{{file_c1}}": "delete", "{{file_c2}}": "keep" } }
HTTP 200
[Asserts]
jsonpath "$.deleted_files[0]" == {{file_c1}}
jsonpath "$.orphaned_files[0]" == {{file_c2}}

GET {{base_url}}/{{app_name}}/api/file/{{uuid_c1}}
Authorization: Bearer {{jwt}}
HTTP 404

GET {{base_url}}/{{app_name}}/api/file/{{uuid_c2}}
Authorization: Bearer {{jwt}}
HTTP 200


# ── 64f. Cleanup: the kept orphans ───────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/file/{{file_own}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_file_deleted"

DELETE {{base_url}}/{{app_name}}/api/file/{{file_c2}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_file_deleted"
