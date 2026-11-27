# ════════════════════════════════════════════════════════════════════
# Phase 62 — File access: GET /{app}/api/file/{uuid}, per-file gates, session cookie
#
# A file is readable through the records it is attached to (or through a rule on the files
# themselves). A scoped person and a scoped API key, both allowed to read only the crud_test
# rows with tipo g62_pub, must get the file of that record and nothing else: the file of
# another record answers 404 exactly like an address that does not exist.
#
# The session cookie set at login lets a browser tag load a file, and ONLY that: it is
# accepted for GET on the file download and for nothing else, and it dies with the token.
# ════════════════════════════════════════════════════════════════════


# ── 62a. Setup (admin): two records, a file on each ──────────────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G621", "tipo": "g62_pub" }, "plugins": {} }
HTTP 200
[Captures]
rec_62_pub: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G622", "tipo": "g62_hid" }, "plugins": {} }
HTTP 200
[Captures]
rec_62_hid: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_62_pub}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
file_62_pub: jsonpath "$.file.id"
uuid_62_pub: jsonpath "$.file.uuid"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_62_hid}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
file_62_hid: jsonpath "$.file.id"
uuid_62_hid: jsonpath "$.file.uuid"


# ── 62b. The original is sent by PHP, to an authenticated principal ──
GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_pub}}
Authorization: Bearer {{jwt}}
HTTP 200
Content-Type: image/png
[Asserts]
header "X-Content-Type-Options" == "nosniff"
header "Content-Disposition" startsWith "inline;"
header "Cache-Control" == "private, no-cache"
header "Content-Security-Policy" contains "sandbox"
header "ETag" exists
bytes startsWith hex,89504e47;

# Anonymous: refused before anything is looked up
GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_pub}}
HTTP 401

# The integer id is not an address; neither is an unknown or malformed one
GET {{base_url}}/{{app_name}}/api/file/{{file_62_pub}}
Authorization: Bearer {{jwt}}
HTTP 404

GET {{base_url}}/{{app_name}}/api/file/00000000-0000-4000-8000-000000000000
Authorization: Bearer {{jwt}}
HTTP 404

# Revalidation
GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_pub}}
Authorization: Bearer {{jwt}}
HTTP 200
[Captures]
etag_62: header "ETag"

GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_pub}}
Authorization: Bearer {{jwt}}
If-None-Match: {{etag_62}}
HTTP 304


# ── 62c. A scoped group, a person and a key ──────────────────────────
POST {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g62 scoped" }
HTTP 200
[Captures]
group_62: jsonpath "$.id"

PUT {{base_url}}/{{app_name}}/api/group/{{group_62}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [ { "table": "crud_test", "action": "read", "filter": { "tipo": { "_eq": "g62_pub" } } } ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "rules_saved"

POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "G62 Scoped", "email": "g62scoped@bdus.test", "password": "Scoped_2025!", "privilege": 39 }
HTTP 200
[Captures]
user_62: jsonpath "$.id"

PUT {{base_url}}/{{app_name}}/api/group/{{group_62}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [ {{user_62}} ] }
HTTP 200

POST {{base_url}}/{{app_name}}/api/api-keys
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "label": "g62 key", "privilege": 39, "group_id": {{group_62}} }
HTTP 200
[Captures]
key_62: jsonpath "$.key"
key_62_id: jsonpath "$.id"


# ── 62d. The key: its file yes, the other record's file no ───────────
GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_pub}}
Authorization: Bearer {{key_62}}
HTTP 200
Content-Type: image/png

GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_hid}}
Authorization: Bearer {{key_62}}
HTTP 404
[Asserts]
body == "Not found"

# an unknown address answers the same: no way to tell them apart
GET {{base_url}}/{{app_name}}/api/file/00000000-0000-4000-8000-000000000000
Authorization: Bearer {{key_62}}
HTTP 404
[Asserts]
body == "Not found"


# ── 62e. The person: login sets the session cookie ───────────────────
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g62scoped@bdus.test", "password": "Scoped_2025!", "app": "{{app_name}}" }
HTTP 200
[Asserts]
header "Set-Cookie" contains "bdus_s_{{app_name}}="
header "Set-Cookie" contains "HttpOnly"
header "Set-Cookie" contains "SameSite=Lax"
[Captures]
user_jwt_62: jsonpath "$.token"

# the list: only readable files
GET {{base_url}}/{{app_name}}/api/files
Authorization: Bearer {{user_jwt_62}}
HTTP 200
[Asserts]
jsonpath "$.files[?(@.id == {{file_62_pub}})].uuid" == "{{uuid_62_pub}}"
jsonpath "$.files[?(@.id == {{file_62_hid}})].uuid" not exists

# the file by header
GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_pub}}
Authorization: Bearer {{user_jwt_62}}
HTTP 200

GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_hid}}
Authorization: Bearer {{user_jwt_62}}
HTTP 404

# changes: refused (the person cannot change the record the file belongs to)
PATCH {{base_url}}/{{app_name}}/api/file/{{file_62_pub}}
Authorization: Bearer {{user_jwt_62}}
Content-Type: application/json
{ "description": "no" }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"

DELETE {{base_url}}/{{app_name}}/api/file/{{file_62_pub}}
Authorization: Bearer {{user_jwt_62}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_enough_privilege"


# ── 62f. The cookie alone (no Authorization header) ──────────────────
# the browser would do exactly this for an <img src>
GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_pub}}
HTTP 200
Content-Type: image/png
[Asserts]
bytes startsWith hex,89504e47;

# the same rules apply: the other record's file is not there
GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_hid}}
HTTP 404

# the cookie opens NOTHING else: not a read, not a write
GET {{base_url}}/{{app_name}}/api/files
HTTP 401

PATCH {{base_url}}/{{app_name}}/api/file/{{file_62_pub}}
Content-Type: application/json
{ "description": "forged" }
HTTP 401

DELETE {{base_url}}/{{app_name}}/api/file/{{file_62_pub}}
HTTP 401

POST {{base_url}}/{{app_name}}/api/record/crud_test
Content-Type: application/json
{ "core": { "sigla": "G62X", "tipo": "g62_pub" }, "plugins": {} }
HTTP 401


# ── 62g. The cookie dies with the token ──────────────────────────────
POST {{base_url}}/{{app_name}}/api/user/{{user_62}}/revoke
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_pub}}
HTTP 401
[Asserts]
jsonpath "$.code" == "token_invalidated"


# ── 62h. Logout drops the cookie ─────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "{{admin_email}}", "password": "{{admin_password}}", "app": "{{app_name}}" }
HTTP 200
[Captures]
admin_jwt_62: jsonpath "$.token"

GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_hid}}
HTTP 200

GET {{base_url}}/{{app_name}}/api/auth/logout
Authorization: Bearer {{admin_jwt_62}}
HTTP 200
[Asserts]
header "Set-Cookie" contains "Max-Age=0"

GET {{base_url}}/{{app_name}}/api/file/{{uuid_62_hid}}
HTTP 401


# ── 62i. Cleanup ─────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/file/{{file_62_pub}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/file/{{file_62_hid}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_62_pub}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_62_hid}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/api-key/{{key_62_id}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/user/{{user_62}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/group/{{group_62}}
Authorization: Bearer {{jwt}}
HTTP 200
