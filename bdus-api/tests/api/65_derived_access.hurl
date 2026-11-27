# ════════════════════════════════════════════════════════════════════
# Phase 65 — Who is sent a derived image: preset access, the public flag, disabled presets
#
# GET /{app}/api/file/{preset}/{uuid}.{ext} answers WITHOUT credentials only when the preset is
# `public` AND the file is marked public. Every other case needs someone who may read the original
# (token, key or the session cookie); a disabled preset is served to nobody. All refusals are a plain 404.
# ════════════════════════════════════════════════════════════════════


# ── 65a. Presets: one public, one that follows the original, one switched off ──
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "imagePresets": [
    { "name": "g65pub",  "width": 120, "access": "public" },
    { "name": "g65priv", "width": 120, "access": "inherit" },
    { "name": "g65off",  "width": 120, "access": "public", "enabled": false }
] }
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_cfg_data_updated"

# the administration screen lists every preset, the built-in thumb first, with access and state
GET {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.main.imagePresets[0].name" == "thumb"
jsonpath "$.main.imagePresets[0].access" == "inherit"
jsonpath "$.main.imagePresets[0].enabled" == true
jsonpath "$.main.imagePresets[?(@.name == 'g65pub')].access" == "public"
jsonpath "$.main.imagePresets[?(@.name == 'g65off')].enabled" == false

# an unknown access is refused
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "imagePresets": [ { "name": "x", "width": 100, "access": "everyone" } ] }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_image_presets"

# the built-in thumb cannot be switched off
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "imagePresets": [
    { "name": "thumb",  "width": 100, "height": 100, "enabled": false },
    { "name": "g65pub",  "width": 120, "access": "public" },
    { "name": "g65priv", "width": 120, "access": "inherit" },
    { "name": "g65off",  "width": 120, "access": "public", "enabled": false }
] }
HTTP 200

GET {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.main.imagePresets[?(@.name == 'thumb')].enabled" == true


# ── 65b. A file, attached to a record, NOT public yet ────────────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G651", "tipo": "g65" }, "plugins": {} }
HTTP 200
[Captures]
rec_65: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_65}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
file_65: jsonpath "$.file.id"
uuid_65: jsonpath "$.file.uuid"

# a new file starts private
GET {{base_url}}/{{app_name}}/api/files?public=0
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[?(@.id == {{file_65}})].uuid" == "{{uuid_65}}"

# anonymous: nothing, whatever the preset (the preset alone publishes nothing)
GET {{base_url}}/{{app_name}}/api/file/g65pub/{{uuid_65}}.webp
HTTP 404
[Asserts]
body == "Not found"

GET {{base_url}}/{{app_name}}/api/file/g65priv/{{uuid_65}}.webp
HTTP 404

GET {{base_url}}/{{app_name}}/api/file/thumb/{{uuid_65}}.webp
HTTP 404

# signed in: the preset follows the original, so every enabled one is served
GET {{base_url}}/{{app_name}}/api/file/g65pub/{{uuid_65}}.webp
Authorization: Bearer {{jwt}}
HTTP 200
Content-Type: image/webp
[Asserts]
header "Cache-Control" == "private, no-cache"

GET {{base_url}}/{{app_name}}/api/file/g65priv/{{uuid_65}}.webp
Authorization: Bearer {{jwt}}
HTTP 200

# a disabled preset is served to nobody, not even an administrator
GET {{base_url}}/{{app_name}}/api/file/g65off/{{uuid_65}}.webp
Authorization: Bearer {{jwt}}
HTTP 404


# ── 65c. Marked public: the PUBLIC preset now goes to anyone, the others still do not ──
PATCH {{base_url}}/{{app_name}}/api/file/{{file_65}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "public": true }
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_file_updated"

GET {{base_url}}/{{app_name}}/api/file/g65pub/{{uuid_65}}.webp
HTTP 200
Content-Type: image/webp
[Asserts]
header "Cache-Control" == "public, no-cache"
bytes startsWith hex,52494646;

GET {{base_url}}/{{app_name}}/api/file/g65priv/{{uuid_65}}.webp
HTTP 404

GET {{base_url}}/{{app_name}}/api/file/thumb/{{uuid_65}}.webp
HTTP 404

GET {{base_url}}/{{app_name}}/api/file/g65off/{{uuid_65}}.webp
HTTP 404

# the original is never anonymous, public or not
GET {{base_url}}/{{app_name}}/api/file/{{uuid_65}}
HTTP 401


# ── 65d. Switching a preset on/off keeps its cache; a scoped person ───
POST {{base_url}}/{{app_name}}/api/config/image-presets/g65pub/purge
Authorization: Bearer {{jwt}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/groups
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g65 scoped" }
HTTP 200
[Captures]
group_65: jsonpath "$.id"

PUT {{base_url}}/{{app_name}}/api/group/{{group_65}}/rules
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "rules": [ { "table": "crud_test", "action": "read", "filter": { "tipo": { "_eq": "g65_nothing" } } } ] }
HTTP 200

POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "G65 Scoped", "email": "g65scoped@bdus.test", "password": "Scoped_2025!", "privilege": 39 }
HTTP 200
[Captures]
user_65: jsonpath "$.id"

PUT {{base_url}}/{{app_name}}/api/group/{{group_65}}/members
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_ids": [ {{user_65}} ] }
HTTP 200

POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
{ "email": "g65scoped@bdus.test", "password": "Scoped_2025!", "app": "{{app_name}}" }
HTTP 200
[Captures]
jwt_65: jsonpath "$.token"

# this person may NOT read the record, so not the file: the inherit preset is refused,
# the public one still goes (the file is public: the same as for anyone)
GET {{base_url}}/{{app_name}}/api/file/g65priv/{{uuid_65}}.webp
Authorization: Bearer {{jwt_65}}
HTTP 404

GET {{base_url}}/{{app_name}}/api/file/g65pub/{{uuid_65}}.webp
Authorization: Bearer {{jwt_65}}
HTTP 200

# the same through the session cookie alone (set at the login above)
GET {{base_url}}/{{app_name}}/api/file/g65priv/{{uuid_65}}.webp
HTTP 404

GET {{base_url}}/{{app_name}}/api/file/g65pub/{{uuid_65}}.webp
HTTP 200

# a revoked session on a public route is just anonymous: still the public image, never an error
POST {{base_url}}/{{app_name}}/api/user/{{user_65}}/revoke
Authorization: Bearer {{jwt}}
HTTP 200

GET {{base_url}}/{{app_name}}/api/file/g65pub/{{uuid_65}}.webp
HTTP 200
Content-Type: image/webp

GET {{base_url}}/{{app_name}}/api/file/g65priv/{{uuid_65}}.webp
HTTP 404


# ── 65e. Cleanup ─────────────────────────────────────────────────────
PUT {{base_url}}/{{app_name}}/api/config/app
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "imagePresets": [] }
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/file/{{file_65}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_65}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/user/{{user_65}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/group/{{group_65}}
Authorization: Bearer {{jwt}}
HTTP 200
