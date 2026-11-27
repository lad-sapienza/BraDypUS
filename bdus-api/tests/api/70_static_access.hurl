# ════════════════════════════════════════════════════════════════════
# Phase 70 — Nothing under projects/ is served as a file, except geodata/
#
# The SQLite database (db/), backups/, export/, templates/, migrations/, the derived-image cache/ and the
# configuration must never be reachable by address without logging in. A path that is not served
# answers 404, as if it did not exist. Uploaded files are read only through the API (GET /api/file/{uuid}); the map layers (geodata/) still go through.
# ════════════════════════════════════════════════════════════════════

GET {{base_url}}/projects/{{app_name}}/db/bdus.sqlite
HTTP 404

# (already denied by the project's own .htaccess: 403 or 404, never the content)
GET {{base_url}}/projects/{{app_name}}/config.json
HTTP *
[Asserts]
status >= 400
body not contains "jwt"

# (already denied by the project's own .htaccess: 403 or 404, never the content)
GET {{base_url}}/projects/{{app_name}}/.jwt_secret
HTTP *
[Asserts]
status >= 400
body not contains "jwt"

GET {{base_url}}/projects/{{app_name}}/backups/
HTTP 404

GET {{base_url}}/projects/{{app_name}}/export/
HTTP 404

GET {{base_url}}/projects/{{app_name}}/cache/thumb/1.webp
HTTP 404

GET {{base_url}}/cache/
HTTP 404

# uploaded files are no longer reachable by address, only through the API with an access check
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G701", "tipo": "g70" }, "plugins": {} }
HTTP 200
[Captures]
rec_70: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_70}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Captures]
file_70: jsonpath "$.file.id"
uuid_70: jsonpath "$.file.uuid"

GET {{base_url}}/projects/{{app_name}}/files/{{uuid_70}}.png
HTTP 404

GET {{base_url}}/projects/{{app_name}}/files/
HTTP 404

# ...while the authenticated address still sends the original
GET {{base_url}}/{{app_name}}/api/file/{{uuid_70}}
Authorization: Bearer {{jwt}}
HTTP 200
Content-Type: image/png

# and anonymous callers do not get it either
GET {{base_url}}/{{app_name}}/api/file/{{uuid_70}}
HTTP 401

# the API still works, and so does anything else outside projects/
GET {{base_url}}/{{app_name}}/api/files
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/file/{{file_70}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_70}}
Authorization: Bearer {{jwt}}
HTTP 200
