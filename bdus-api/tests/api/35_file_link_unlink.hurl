# ════════════════════════════════════════════════════════════════════
# Phase 35 — File link / unlink
#             POST /api/record/{tb}/{id}/link-file
#             DELETE /api/file-link/{linkId}
#
# Lifecycle:
#   a. Upload a file to crud_test record → capture file_id_35
#   b. POST link-file — link the same file to the same record again
#      (creates a second bdus_file_links row) → capture link_id_35
#   c. DELETE /api/file-link/{link_id_35} — unlink (removes only the
#      junction row, file binary preserved)
#   d. Negative: DELETE non-existent link_id → record_not_found
#   e. Negative: POST link-file with non-existent fileId → record_not_found
#   f. DELETE file to clean up
# ════════════════════════════════════════════════════════════════════


# ── 35a. Upload a file so we have a real file_id to work with ────────
POST {{base_url}}/api/record/crud_test/{{us_id_1}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status"        == "success"
jsonpath "$.code"          == "ok_file_uploaded"
jsonpath "$.file.id"       isInteger
jsonpath "$.file.is_image" == true
[Captures]
file_id_35: jsonpath "$.file.id"


# ── 35b. POST link-file — link the existing file to the same record ──
POST {{base_url}}/api/record/crud_test/{{us_id_1}}/link-file
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "fileId": {{file_id_35}} }
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.code"            == "ok_file_linked"
jsonpath "$.file.id"         == {{file_id_35}}
jsonpath "$.file.link_id"    isInteger
jsonpath "$.file.link_id"    > 0
jsonpath "$.file.ext"        isString
jsonpath "$.file.is_image"   == true
[Captures]
link_id_35: jsonpath "$.file.link_id"


# ── 35c. DELETE /api/file-link/{linkId} — unlink (junction row only) ─
DELETE {{base_url}}/api/file-link/{{link_id_35}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_file_unlinked"


# ── 35d. Negative: unlink non-existent link_id ───────────────────────
DELETE {{base_url}}/api/file-link/9999999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "record_not_found"


# ── 35e. Negative: link non-existent file ────────────────────────────
POST {{base_url}}/api/record/crud_test/{{us_id_1}}/link-file
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "fileId": 9999999 }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "record_not_found"


# ── 35f. Clean up: delete the uploaded file ───────────────────────────
DELETE {{base_url}}/api/file/{{file_id_35}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_file_deleted"
