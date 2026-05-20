# ════════════════════════════════════════════════════════════════════
# Phase 3 — Configure tables and fields
#
# Creates two main tables (us, periodi) and one plugin table
# (attività), then adds realistic fields to each.
# ════════════════════════════════════════════════════════════════════

# ── 3a. List tables (empty at start) ────────────────────────────────
GET {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3b. Create table: us (Unità Stratigrafiche) ──────────────────────
POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "us",
  "label":     "Unità stratigrafiche",
  "is_plugin": "0"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_cfg_data_updated"
jsonpath "$.tb" == "us"


# ── 3b-neg. Duplicate table name → tb_already_available ─────────────
POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "us",
  "label":     "Duplicate — must fail",
  "is_plugin": "0"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "tb_already_available"


# ── 3c. Add fields to us ────────────────────────────────────────────
POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sigla", "label": "Sigla US", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 3c-neg. Duplicate field name → fld_already_available ────────────
POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sigla", "label": "Duplicate — must fail", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "fld_already_available"


POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "descrizione", "label": "Descrizione", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "interpretazione", "label": "Interpretazione", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "periodo", "label": "Periodo", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3d. Create table: periodi (Periodi storici) ──────────────────────
POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "periodi",
  "label":     "Periodi storici",
  "is_plugin": "0"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.tb" == "periodi"

POST {{base_url}}/api/config/table/periodi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nome", "label": "Nome periodo", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/periodi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "data_inizio", "label": "Data inizio", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/periodi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "data_fine", "label": "Data fine", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3e. Create plugin table: attività (linked to us) ────────────────
POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "attivita",
  "label":     "Attività",
  "is_plugin": "1"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.tb" == "attivita"

POST {{base_url}}/api/config/table/attivita/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tipo_attivita", "label": "Tipo attività", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/attivita/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "note", "label": "Note", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3f. Verify final table list ─────────────────────────────────────
GET {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.tables"          count >= 3
jsonpath "$.tables[*].name"  contains "us"
jsonpath "$.tables[*].name"  contains "periodi"
jsonpath "$.tables[*].name"  contains "attivita"


# ── 3g. Configure us table layout and rs field ───────────────────────
# Now that fields exist, set order/id_field/preview and rs (for Harris Matrix).
PUT {{base_url}}/api/config/table/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "us",
  "label":     "Unità stratigrafiche",
  "is_plugin": "0",
  "order":     "sigla",
  "id_field":  "id",
  "preview":   ["sigla"],
  "rs":        "sigla"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3h. Verify field list for us ────────────────────────────────────
GET {{base_url}}/api/config/table/us/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3i. Verify full table config ────────────────────────────────────
GET {{base_url}}/api/config/table/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
