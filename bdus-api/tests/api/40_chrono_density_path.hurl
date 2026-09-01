# ════════════════════════════════════════════════════════════════════
# Phase 40 — Configurable chrono_density_path (multi-hop chronological
# distribution)
#
# Routes exercised:
#   GET  /api/config/table/{tb}               — fuzzy_date_tables list
#   PUT  /api/config/table/{tb}                — save chrono_density_path
#   GET  /api/chrono/related/{tb}/{id}          — multi-hop + filter shape
#
# Uses the real siti -> us -> reperti FK chain already wired by phase 03
# (us.sito_id -> siti.id, reperti.us_provenienza_id -> us.id). Self
# contained: activates fuzzy_date on reperti and creates its own records
# here rather than depending on the phase 19 seed (which — like phase 37 —
# does not run before this phase in the standard --setup --tests flow).
# ════════════════════════════════════════════════════════════════════


# ── 40a. Activate fuzzy_date on reperti (leaf of the chain) ──────────
POST {{base_url}}/api/config/table/reperti/fuzzy-date
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "fuzzy_date_activated"


# ── 40b. fuzzy_date_tables now lists reperti ─────────────────────────
GET {{base_url}}/api/config/table/siti
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"           == "success"
jsonpath "$.fuzzy_date_tables" contains "reperti"


# ── 40c-neg. Save rejects a hop with no FK relation to the root ─────
# 'reperti' has no direct relation to 'siti' (only to 'us').
PUT {{base_url}}/api/config/table/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "siti", "label": "Siti Archeologici", "is_plugin": "0",
  "order": "nome", "id_field": "nome", "preview": ["nome"],
  "chrono_density_path": ["reperti"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_chrono_density_path"


# ── 40d-neg. Save rejects a chain ending on a table without fuzzy_date ─
# 'complessi' is a valid direct child of 'siti' but has no fuzzy_date.
PUT {{base_url}}/api/config/table/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "siti", "label": "Siti Archeologici", "is_plugin": "0",
  "order": "nome", "id_field": "nome", "preview": ["nome"],
  "chrono_density_path": ["complessi"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_chrono_density_path"


# ── 40e. Save a valid 2-hop path: siti -> us -> reperti ──────────────
PUT {{base_url}}/api/config/table/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "siti", "label": "Siti Archeologici", "is_plugin": "0",
  "order": "nome", "id_field": "nome", "preview": ["nome"],
  "chrono_density_path": ["us", "reperti"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 40f. Seed a dedicated site + 2 us + 3 reperti ────────────────────
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Sito Density Test" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
density_sito_id: jsonpath "$.id"

POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Sito Density Empty" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
density_sito_empty_id: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "USD900", "sito_id": {{density_sito_id}} }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
density_us_id_1: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "USD901", "sito_id": {{density_sito_id}} }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
density_us_id_2: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "inventario": "REP-DENSITY-1",
    "us_provenienza_id": {{density_us_id_1}},
    "chrono_from": -100, "chrono_to": 100, "chrono_certainty": 1
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
density_rep_id_1: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "inventario": "REP-DENSITY-2",
    "us_provenienza_id": {{density_us_id_2}},
    "chrono_from": 200, "chrono_to": 300, "chrono_certainty": 2
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
density_rep_id_2: jsonpath "$.id"

# No chrono data — must count toward the click-through filter but not
# appear in the density "records" list.
POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP-DENSITY-3", "us_provenienza_id": {{density_us_id_1}} }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
density_rep_id_3: jsonpath "$.id"


# ── 40g. Multi-hop related(): only reperti, only the 2 dated records ─
GET {{base_url}}/api/chrono/related/siti/{{density_sito_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"                     == "success"
jsonpath "$.sources"                    count == 1
jsonpath "$.sources[0].tb_id"           == "reperti"
jsonpath "$.sources[0].records"         count == 2
jsonpath "$.sources[0].records[*].id"   contains {{density_rep_id_1}}
jsonpath "$.sources[0].records[*].id"   contains {{density_rep_id_2}}


# ── 40h. filter.id._in includes ALL 3 structurally-related reperti ──
# (the undated REP-DENSITY-3 too — click-through shows every related
# record, not just the dated ones, matching the automatic 1-hop shape)
GET {{base_url}}/api/chrono/related/siti/{{density_sito_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.sources[0].filter.id._in" count == 3
jsonpath "$.sources[0].filter.id._in" contains {{density_rep_id_1}}
jsonpath "$.sources[0].filter.id._in" contains {{density_rep_id_2}}
jsonpath "$.sources[0].filter.id._in" contains {{density_rep_id_3}}


# ── 40i. Site with no us children at all → empty sources ────────────
GET {{base_url}}/api/chrono/related/siti/{{density_sito_empty_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.sources" count == 0


# ── 40j. Clearing the path restores the automatic 1-hop default ─────
PUT {{base_url}}/api/config/table/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "siti", "label": "Siti Archeologici", "is_plugin": "0",
  "order": "nome", "id_field": "nome", "preview": ["nome"],
  "chrono_density_path": []
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/api/chrono/related/siti/{{density_sito_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.sources" count == 0
