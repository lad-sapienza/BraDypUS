# ════════════════════════════════════════════════════════════════════
# Phase 63 — The files table is configured like any table: custom fields
#
# An administrator adds a field to `bdus_files`; it travels through the list, the update (validated and
# versioned), the search and the generic record endpoint. The structure stays fixed: the table and its
# system fields cannot be removed, and a request never writes the columns that belong to the binary.
# ════════════════════════════════════════════════════════════════════


# ── 63a. The files table shows up in the configuration as a system table ──
GET {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.tables[?(@.name == 'bdus_files')].system" == true
jsonpath "$.tables[?(@.name == 'bdus_users')]" not exists

GET {{base_url}}/{{app_name}}/api/config/table/bdus_files
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.table.system" == true
jsonpath "$.table.fields.uuid.system" == true

GET {{base_url}}/{{app_name}}/api/config/table/bdus_users
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_found"


# ── 63b. Its structure is fixed ──────────────────────────────────────
PATCH {{base_url}}/{{app_name}}/api/config/table/bdus_files
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "new_name": "my_files" }
HTTP 200
[Asserts]
jsonpath "$.code" == "not_found"

DELETE {{base_url}}/{{app_name}}/api/config/table/bdus_files
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "not_found"

DELETE {{base_url}}/{{app_name}}/api/config/table/bdus_files/field/uuid
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "system_field_not_deletable"

DELETE {{base_url}}/{{app_name}}/api/config/table/bdus_files/field/ext
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "system_field_not_deletable"

PATCH {{base_url}}/{{app_name}}/api/config/table/bdus_files/field/filename
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "new_name": "title" }
HTTP 200
[Asserts]
jsonpath "$.code" == "system_field_not_renamable"


# ── 63c. A custom field ──────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/config/table/bdus_files/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "g63_licence", "label": "Licence", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_cfg_data_updated"

# the list keys of the files endpoint cannot be taken
POST {{base_url}}/{{app_name}}/api/config/table/bdus_files/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "links", "label": "x", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_db_name"


# ── 63d. A file, its custom field, the list and the search ───────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "G631", "tipo": "g63" }, "plugins": {} }
HTTP 200
[Captures]
rec_63: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_63}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/derived_source.png;image/png
HTTP 200
[Captures]
file_63: jsonpath "$.file.id"
uuid_63: jsonpath "$.file.uuid"

PATCH {{base_url}}/{{app_name}}/api/file/{{file_63}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "g63_licence": "CC BY-SA 4.0", "description": "with a licence", "public": true }
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_file_updated"

GET {{base_url}}/{{app_name}}/api/files?search=BY-SA
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[?(@.id == {{file_63}})].g63_licence" == "CC BY-SA 4.0"
jsonpath "$.files[?(@.id == {{file_63}})].public" == true
jsonpath "$.files[?(@.id == {{file_63}})].can_update" == true
jsonpath "$.files[?(@.id == {{file_63}})].uuid" == "{{uuid_63}}"


# ── 63d2. The list describes its fields, filters on public and sorts ──
GET {{base_url}}/{{app_name}}/api/files?public=1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.fields[?(@.name == 'g63_licence')].label" == "Licence"
jsonpath "$.fields[?(@.name == 'g63_licence')].system" == false
jsonpath "$.fields[?(@.name == 'uuid')].editable" == false
jsonpath "$.fields[?(@.name == 'description')].system" == true
jsonpath "$.files[?(@.id == {{file_63}})].uuid" == "{{uuid_63}}"

GET {{base_url}}/{{app_name}}/api/files?public=0
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[?(@.id == {{file_63}})].uuid" not exists

GET {{base_url}}/{{app_name}}/api/files?sort_field=g63_licence&sort_dir=asc
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# a field that is not configured is ignored, never reaches SQL
GET {{base_url}}/{{app_name}}/api/files?sort_field=id%3B%20DROP%20TABLE%20bdus_files
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.files[?(@.id == {{file_63}})].uuid" == "{{uuid_63}}"


# ── 63e. What a request can never write ──────────────────────────────
PATCH {{base_url}}/{{app_name}}/api/file/{{file_63}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "uuid": "11111111-1111-4111-8111-111111111111", "ext": "html", "description": "still fine" }
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_file_updated"

GET {{base_url}}/{{app_name}}/api/files?search=still%20fine
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.files[?(@.id == {{file_63}})].uuid" == "{{uuid_63}}"
jsonpath "$.files[?(@.id == {{file_63}})].ext" == "png"

# the binary is still sent under its own uuid, as an image
GET {{base_url}}/{{app_name}}/api/file/{{uuid_63}}
Authorization: Bearer {{jwt}}
HTTP 200
Content-Type: image/png


# ── 63f. The generic record endpoint: same rules, files are not rows ──
GET {{base_url}}/{{app_name}}/api/record/bdus_files/{{file_63}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/bdus_files
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "filename": "made up", "ext": "png" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.code" == "files_are_uploaded"

DELETE {{base_url}}/{{app_name}}/api/record/bdus_files/{{file_63}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "files_are_deleted_as_files"


# ── 63g. Cleanup ─────────────────────────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/file/{{file_63}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{rec_63}}
Authorization: Bearer {{jwt}}
HTTP 200

DELETE {{base_url}}/{{app_name}}/api/config/table/bdus_files/field/g63_licence
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.code" == "ok_cfg_column_delete"
