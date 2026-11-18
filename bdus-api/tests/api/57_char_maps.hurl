# ════════════════════════════════════════════════════════════════════
# Phase 57 — Char map presets (strip of special characters)
#
# GET /{app}/api/config/char-maps   the built-in presets (as buttons) and the
#                                   application's own (as written); super admin
# PUT /{app}/api/config/char-maps   { "custom": { name: text | list | map } }
#   - replaces the application's own presets; a bad one refuses the whole save
#   - names: 2-40 characters, lowercase letters, digits, hyphens; not those
#     of a built-in preset
# The `char_palette` of a long_text / md field is the name of one of them.
# ════════════════════════════════════════════════════════════════════

# ── no credentials ───────────────────────────────────────────────────
GET {{base_url}}/{{app_name}}/api/config/char-maps
HTTP 401

PUT {{base_url}}/{{app_name}}/api/config/char-maps
Content-Type: application/json
{ "custom": {} }
HTTP 401

# ── built-in presets are there, none of the application's own ────────
GET {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.system['greek-polytonic']" count > 80
jsonpath "$.system['greek-polytonic'][0].label" exists
jsonpath "$.system['greek-polytonic'][0].value" exists
jsonpath "$.custom.epigrafia" not exists

# ── save one of each form ────────────────────────────────────────────
PUT {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "custom": {
    "epigrafia": "Α Β Γ",
    "segni": { "a=": "ᾱ", "h~": "ῆ" },
    "lista-1": ["α", { "label": "eta", "value": "η" }]
} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.custom.epigrafia" == "Α Β Γ"
jsonpath "$.custom.segni['a=']" == "ᾱ"
jsonpath "$.custom['lista-1']" count == 2

# ── a bad map refuses the whole save and keeps what was there ────────
PUT {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "custom": { "ok-map": "β", "Bad Name": "γ" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code" == "invalid_char_maps"

PUT {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "custom": { "greek-polytonic": "α" } }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_char_maps"

PUT {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "custom": { "vuota": "   " } }
HTTP 200
[Asserts]
jsonpath "$.code" == "invalid_char_maps"

PUT {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "nothing": true }
HTTP 200
[Asserts]
jsonpath "$.code" == "parameter_invalid"

GET {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.custom.epigrafia" == "Α Β Γ"
jsonpath "$.custom['ok-map']" not exists

# ── the field form offers the names of both kinds ────────────────────
GET {{base_url}}/{{app_name}}/api/config/field-structure
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.structure.char_palette.values" includes "greek-polytonic"
jsonpath "$.structure.char_palette.values" includes "epigrafia"
jsonpath "$.structure.char_palette.values" includes "segni"

# ── clean up: no custom map left ─────────────────────────────────────
PUT {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "custom": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/config/char-maps
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.custom.epigrafia" not exists
