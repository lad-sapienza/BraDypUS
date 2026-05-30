# ════════════════════════════════════════════════════════════════════
# Phase 19 — Demo seed
#
# Populates the test app with realistic archaeological data so the
# resulting app can be used for documentation screenshots.
#
# Requires: phases 1–18 have already run (tables, records 1-2 exist).
# The JWT is passed via --variable "jwt=..." by run.sh.
# ════════════════════════════════════════════════════════════════════

# ── 19a. Add periodi ─────────────────────────────────────────────────
POST {{base_url}}/api/record/periodi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": { "nome": "Tardo Antico", "data_inizio": "300", "data_fine": "600" },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
periodo_tardo_antico: jsonpath "$.id"


POST {{base_url}}/api/record/periodi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": { "nome": "Alto Medioevo", "data_inizio": "600", "data_fine": "1000" },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
periodo_alto_med: jsonpath "$.id"


POST {{base_url}}/api/record/periodi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": { "nome": "Età del Ferro", "data_inizio": "-800", "data_fine": "-100" },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


POST {{base_url}}/api/record/periodi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": { "nome": "Età del Bronzo", "data_inizio": "-2200", "data_fine": "-900" },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19b. Add US records ──────────────────────────────────────────────
POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "US003",
    "descrizione": "Strato di preparazione del piano pavimentale in cocciopesto",
    "interpretazione": "Piano di calpestio",
    "periodo": "Tardo Antico"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_3: jsonpath "$.id"


POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "US004",
    "descrizione": "Taglio di fossa a pianta irregolare con pareti verticali",
    "interpretazione": "Fossa di deposizione",
    "periodo": "Basso Medioevo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_4: jsonpath "$.id"


POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "US005",
    "descrizione": "Riempimento di fossa con terreno sciolto bruno con inclusi carboniosi",
    "interpretazione": "Riempimento secondario",
    "periodo": "Basso Medioevo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_5: jsonpath "$.id"


POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "US006",
    "descrizione": "Struttura muraria in laterizi legati con malta di calce",
    "interpretazione": "Muro di delimitazione vano",
    "periodo": "Alto Medioevo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_6: jsonpath "$.id"


POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "US007",
    "descrizione": "Crollo di elementi costruttivi con mattoni frantumati e tegole",
    "interpretazione": "Crollo da evento sismico",
    "periodo": "Alto Medioevo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_7: jsonpath "$.id"


POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "US008",
    "descrizione": "Piano di frequentazione con impronte di pali lignei",
    "interpretazione": "Pavimento capanna",
    "periodo": "Età del Ferro"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_8: jsonpath "$.id"


POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "US009",
    "descrizione": "Deposito alluvionale sterile di colore grigio-verde con ciottoli",
    "interpretazione": "Alluvione naturale",
    "periodo": "Età del Bronzo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_9: jsonpath "$.id"


POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "US010",
    "descrizione": "Buca di palo circolare diam. 0,20 m con riempimento carbonioso",
    "interpretazione": "Sostegno struttura lignea",
    "periodo": "Età del Ferro"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_10: jsonpath "$.id"


# ── 19c. RS relations — Harris Matrix chain ──────────────────────────
# US001 (Età contemporanea) copre US003 (Tardo Antico)
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 1, "id_second": {{us_id_3}}, "relation": 1 }
HTTP 200


# US002 (Basso Medioevo) copre US004 e US005
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 2, "id_second": {{us_id_4}}, "relation": 1 }
HTTP 200


POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 2, "id_second": {{us_id_5}}, "relation": 1 }
HTTP 200


# US003 (Tardo Antico) copre US006
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": {{us_id_3}}, "id_second": {{us_id_6}}, "relation": 1 }
HTTP 200


# US004 è tagliato da US005 (contemporanei)
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": {{us_id_4}}, "id_second": {{us_id_5}}, "relation": 3 }
HTTP 200


# US006 copre US007
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": {{us_id_6}}, "id_second": {{us_id_7}}, "relation": 1 }
HTTP 200


# US007 copre US008
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": {{us_id_7}}, "id_second": {{us_id_8}}, "relation": 1 }
HTTP 200


# US008 copre US009
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": {{us_id_8}}, "id_second": {{us_id_9}}, "relation": 1 }
HTTP 200


# US010 è uguale a US008 (buca di palo contemporanea al piano)
POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": {{us_id_8}}, "id_second": {{us_id_10}}, "relation": 9 }
HTTP 200


# ── 19d. A chart for the period distribution ─────────────────────────
POST {{base_url}}/api/charts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "US per periodo",
  "definition": {
    "tb": "us",
    "type": "pie",
    "x_field": "periodo",
    "y_field": "id",
    "y_function": "COUNT"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19e. Verify the Matrix has all nodes ─────────────────────────────
GET {{base_url}}/api/rs/matrix
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.nodes"  isCollection
