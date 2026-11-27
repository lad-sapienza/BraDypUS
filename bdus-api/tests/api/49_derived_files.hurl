# ════════════════════════════════════════════════════════════════════
# Phase 49 — Derived files: GET /{app}/api/file/{preset}/{uuid}.{ext}
#
# Public route (no Authorization header), like the originals served from
# files/. The address is the file's random uuid, never its integer id.
# A version is created on first request and only for a defined
# preset; every failure is a plain 404 with no fallback to the original.
# Generation, purge and edge cases are covered by ImageDerivativeTest.
# ════════════════════════════════════════════════════════════════════


# ── 49a. Setup (admin): a record and an uploaded image ───────────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G491", "tipo": "g49" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rec_49: jsonpath "$.id"


POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_49}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.file.id" isInteger
jsonpath "$.file.uuid" matches /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
[Captures]
file_49: jsonpath "$.file.id"
file_49_uuid: jsonpath "$.file.uuid"


# ── 49b. System preset `thumb`, anonymous: WebP created on demand ────
GET {{base_url}}/{{app_name}}/api/file/thumb/{{file_49_uuid}}.webp
HTTP 200
Content-Type: image/webp
[Captures]
etag_49: header "ETag"
[Asserts]
header "Cache-Control" contains "no-cache"
bytes startsWith hex,52494646;


# ── 49c. Revalidation: same ETag → 304 ───────────────────────────────
GET {{base_url}}/{{app_name}}/api/file/thumb/{{file_49_uuid}}.webp
If-None-Match: {{etag_49}}
HTTP 304


# ── 49d. Unknown preset → 404, nothing created ───────────────────────
GET {{base_url}}/{{app_name}}/api/file/nopreset/{{file_49_uuid}}.webp
HTTP 404


# ── 49e. Extension not matching the preset format → 404 ──────────────
GET {{base_url}}/{{app_name}}/api/file/thumb/{{file_49_uuid}}.jpg
HTTP 404


# ── 49f. Unknown file → 404 ───────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/file/thumb/00000000-0000-4000-8000-000000000000.webp
HTTP 404


# ── 49f1. The integer id is not an address (derived versions never by id) ─
GET {{base_url}}/{{app_name}}/api/file/thumb/{{file_49}}.webp
HTTP 404

GET {{base_url}}/{{app_name}}/api/file/thumb/99999999.webp
HTTP 404


# ── 49f2. Metadata change: version rebuilt, still served ─────────────
PATCH {{base_url}}/{{app_name}}/api/file/{{file_49}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "attribution": "(c) phase 49" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


GET {{base_url}}/{{app_name}}/api/file/thumb/{{file_49_uuid}}.webp
HTTP 200
Content-Type: image/webp


# ── 49f3. Replace the binary: the version follows (different shape → different size)
POST {{base_url}}/{{app_name}}/api/file/{{file_49}}/replace
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_wide.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


GET {{base_url}}/{{app_name}}/api/file/thumb/{{file_49_uuid}}.webp
HTTP 200
[Asserts]
header "ETag" != "{{etag_49}}"


# ── 49f4. Guard: a file that is not an image is a 404, never served ──
POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_49}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/import_test.csv;text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
file_49_csv: jsonpath "$.file.id"
file_49_csv_uuid: jsonpath "$.file.uuid"


GET {{base_url}}/{{app_name}}/api/file/thumb/{{file_49_csv_uuid}}.webp
HTTP 404


# ── 49f5. Guard: an image extension whose bytes are not decodable → 404
# (test_image.png is a 70-byte file GD cannot decode)
POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_49}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
file_49_bad: jsonpath "$.file.id"
file_49_bad_uuid: jsonpath "$.file.uuid"


GET {{base_url}}/{{app_name}}/api/file/thumb/{{file_49_bad_uuid}}.webp
HTTP 404


# ── 49f6. Deleted file: its version is gone too (404) ────────────────
DELETE {{base_url}}/{{app_name}}/api/file/{{file_49_csv}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/file/{{file_49_bad}}
Authorization: Bearer {{jwt}}
HTTP 200


# ── 49f7. Clear the cache of one preset (super admin) ────────────────
POST {{base_url}}/{{app_name}}/api/config/image-presets/thumb/purge
HTTP 401

POST {{base_url}}/{{app_name}}/api/config/image-presets/thumb/purge
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.removed" >= 1

# a second call finds nothing left
POST {{base_url}}/{{app_name}}/api/config/image-presets/thumb/purge
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.removed" == 0

# the version is rebuilt on the next request
GET {{base_url}}/{{app_name}}/api/file/thumb/{{file_49_uuid}}.webp
HTTP 200
Content-Type: image/webp

# not a valid preset name: nothing is touched
POST {{base_url}}/{{app_name}}/api/config/image-presets/BAD_Name/purge
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_invalid"


# ── 49g. Cleanup ─────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/file/{{file_49}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_49}}
Authorization: Bearer {{jwt}}
HTTP 200
