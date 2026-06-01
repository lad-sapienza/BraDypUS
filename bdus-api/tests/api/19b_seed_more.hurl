# ════════════════════════════════════════════════════════════════════
# Phase 19b — Extended demo seed
#
# Requires: phases 1–19 completed (app with vocabularies + 3 siti).
# Run with --seed-more flag (see run.sh).
#
# ID assumptions (fresh demo after phase 19):
#   siti already in DB:     3  → new siti       IDs  4-18  (sito 1 = Monte Bibele = 4)
#   complessi already:      8  → new complessi   IDs  9-11  (3 for sito 1)
#   saggi already:         12  → new saggi       IDs 13-17  (saggio 1 = Saggio X = 13)
#   us already:            30  → new US          IDs 31-45  (u1=31..u15=45)
#   reperti already:       20  → new reperti     IDs 21-395
#
# Tables seeded:
#   siti (15 via CSV)
#   complessi (3 via CSV, sito 1 only)
#   saggi (5 via CSV, sito 1 only)
#   US (15 via CSV, saggio 1 only) + Harris Matrix RS (16 relations)
#   reperti (375 via CSV, 25 per US)
#   geodata: 1 point per sito (15) + 1 polygon per complesso (3)
# ════════════════════════════════════════════════════════════════════


# ── Upload + import siti ──────────────────────────────────────────────

POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_siti.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
siti_tid: jsonpath "$.temp_id"


POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{siti_tid}}",
  "type":      "csv",
  "tb":        "siti",
  "key_field": "nome",
  "mapping": {
    "nome":        "nome",
    "localita":    "localita",
    "tipologia":   "tipologia",
    "descrizione": "descrizione"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.inserted" == 15


# ── Upload + import complessi (sito 1) ────────────────────────────────

POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_complessi.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_tid: jsonpath "$.temp_id"


POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{compl_tid}}",
  "type":      "csv",
  "tb":        "complessi",
  "key_field": "nome",
  "mapping": {
    "nome":        "nome",
    "tipo":        "tipo",
    "sito_id":     "sito_id",
    "cronologia":  "cronologia",
    "descrizione": "descrizione"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.inserted" == 3


# ── Upload + import saggi (sito 1) ────────────────────────────────────

POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_saggi.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
saggi_tid: jsonpath "$.temp_id"


POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{saggi_tid}}",
  "type":      "csv",
  "tb":        "saggi",
  "key_field": "nome",
  "mapping": {
    "nome":         "nome",
    "anno":         "anno",
    "sito_id":      "sito_id",
    "complesso_id": "complesso_id",
    "descrizione":  "descrizione"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.inserted" == 5


# ── Upload + import US (saggio 1, 15 US) ─────────────────────────────

POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_us.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_tid: jsonpath "$.temp_id"


POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{us_tid}}",
  "type":      "csv",
  "tb":        "us",
  "key_field": "sigla",
  "mapping": {
    "sigla":           "sigla",
    "tipo":            "tipo",
    "sito_id":         "sito_id",
    "saggio_id":       "saggio_id",
    "periodo":         "periodo",
    "affidabilita":    "affidabilita",
    "anno_scavo":      "anno_scavo",
    "descrizione":     "descrizione",
    "interpretazione": "interpretazione"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.inserted" == 15


# ── Harris Matrix RS (16 relazioni, us IDs 31-45) ─────────────────────
# Struttura a 5 strati:
#  L1: us31, us32  |  L2: us33,34,35  |  L3: us36,37,38  |  L4: us39,40,41  |  L5: us42,43,44,45

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 31, "id_second": 33, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 31, "id_second": 34, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 32, "id_second": 34, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 32, "id_second": 35, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 33, "id_second": 36, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 34, "id_second": 37, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 35, "id_second": 37, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 35, "id_second": 38, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 36, "id_second": 39, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 37, "id_second": 40, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 38, "id_second": 40, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 38, "id_second": 41, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 39, "id_second": 42, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 40, "id_second": 43, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 40, "id_second": 44, "relation": 1 }
HTTP 200

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 41, "id_second": 45, "relation": 1 }
HTTP 200


# ── Upload + import reperti (375, 25 per US) ──────────────────────────

POST {{base_url}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_reperti.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rep_tid: jsonpath "$.temp_id"


POST {{base_url}}/api/import/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "temp_id":   "{{rep_tid}}",
  "type":      "csv",
  "tb":        "reperti",
  "key_field": "inventario",
  "mapping": {
    "inventario":       "inventario",
    "sito_id":          "sito_id",
    "saggio_id":        "saggio_id",
    "us_provenienza_id":"us_provenienza_id",
    "categoria":        "categoria",
    "classe":           "classe",
    "stato_cons":       "stato_cons",
    "descrizione":      "descrizione"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.inserted" == 375


# ── Geodata: 1 punto per sito (IDs 4-18) ─────────────────────────────
# Coordinate approximate di siti reali o plausibili (WGS84)

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":4,"geometry":{"type":"Point","coordinates":[11.3447,44.3211]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":5,"geometry":{"type":"Point","coordinates":[14.0520,40.8468]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":6,"geometry":{"type":"Point","coordinates":[10.5000,43.0000]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":7,"geometry":{"type":"Point","coordinates":[16.2500,38.2333]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":8,"geometry":{"type":"Point","coordinates":[16.8250,40.3750]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":9,"geometry":{"type":"Point","coordinates":[13.5860,37.2960]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":10,"geometry":{"type":"Point","coordinates":[15.1000,41.0167]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":11,"geometry":{"type":"Point","coordinates":[11.9869,42.0000]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":12,"geometry":{"type":"Point","coordinates":[11.7500,42.2500]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":13,"geometry":{"type":"Point","coordinates":[14.0850,40.7600]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":14,"geometry":{"type":"Point","coordinates":[12.5020,41.0560]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":15,"geometry":{"type":"Point","coordinates":[14.5330,40.7530]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":16,"geometry":{"type":"Point","coordinates":[14.4520,40.7570]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":17,"geometry":{"type":"Point","coordinates":[12.7900,41.9600]}}
HTTP 200

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":18,"geometry":{"type":"Point","coordinates":[12.5420,42.1440]}}
HTTP 200


# ── Geodata: 1 poligono per complesso di sito 1 (IDs 9-11) ───────────
# Piccoli poligoni centrati su Monte Bibele (11.34, 44.32)

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"complessi","id":9,"geometry":{"type":"Polygon","coordinates":[[[11.3440,44.3205],[11.3455,44.3205],[11.3455,44.3215],[11.3440,44.3215],[11.3440,44.3205]]]}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"complessi","id":10,"geometry":{"type":"Polygon","coordinates":[[[11.3456,44.3205],[11.3470,44.3205],[11.3470,44.3218],[11.3456,44.3218],[11.3456,44.3205]]]}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"complessi","id":11,"geometry":{"type":"Polygon","coordinates":[[[11.3435,44.3218],[11.3460,44.3218],[11.3460,44.3230],[11.3435,44.3230],[11.3435,44.3218]]]}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
