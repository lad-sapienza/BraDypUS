# ════════════════════════════════════════════════════════════════════
# Phase 44 — ids_only (Previous/Next navigation id lists)
#
# GET /api/records/{tb}?ids_only=1 and GET /api/files?ids_only=1 return the
# ordered ids of the WHOLE result set instead of one page. Detailed ordering
# parity with the paginated list is covered by IdsOnlyTest (PHPUnit); here we
# check the HTTP contract end to end.
# ════════════════════════════════════════════════════════════════════


# ── 44a. Records: id list, not a page of data ────────────────────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?ids_only=1&page=2&per_page=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.total"     >= 1
jsonpath "$.ids"       isCollection
jsonpath "$.ids"       count >= 1
jsonpath "$.ids[0]"    isInteger
jsonpath "$.truncated" == false
jsonpath "$.data"      not exists


# ── 44b. Records: still honours the filter (matches nothing) ─────────
GET {{base_url}}/{{app_name}}/api/records/crud_test?ids_only=1&filter[id][_eq]=-1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  == 0
jsonpath "$.ids"    count == 0


# ── 44c. Files: minimal descriptors of every match ───────────────────
# (an own record and file, so the descriptor shape can be asserted whatever ran before)
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G441", "tipo": "g44" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_44: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_44}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
file_44: jsonpath "$.file.id"
file_44_uuid: jsonpath "$.file.uuid"

GET {{base_url}}/{{app_name}}/api/files?ids_only=1&per_page=5
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.total"     >= 0
jsonpath "$.files"     isCollection
jsonpath "$.truncated" == false
jsonpath "$.page"      not exists
jsonpath "$.files[0].id"   isInteger
jsonpath "$.files[0].uuid" isString
jsonpath "$.files[?(@.id == {{file_44}})].uuid" == "{{file_44_uuid}}"

DELETE {{base_url}}/{{app_name}}/api/file/{{file_44}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_44}}
Authorization: Bearer {{jwt}}
HTTP 200


# ── 44d. Without ids_only the list is unchanged (still a page) ───────
GET {{base_url}}/{{app_name}}/api/records/crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data"      isCollection
jsonpath "$.ids"       not exists
jsonpath "$.truncated" not exists
