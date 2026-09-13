# ════════════════════════════════════════════════════════════════════
# Phase 19 — Demo seed (full, includes former phase 19b)
#
# Populates the app with realistic archaeological data.
# Self-contained: configures table-level plugins and adds chrono
# fields via the system field API before inserting data, so it works
# both after --setup and standalone (--no-docker --seed).
#
# Tables seeded:
#   vocabularies (periodi, sito_tipologia, complesso_tipo, us_tipo,
#                 affidabilita, reperto_categoria, reperto_classe,
#                 stato_conservazione, tipo_misura, unita_misura,
#                 materiale_classe, forma_tipo)
#   siti (3 direct + 15 via CSV = 18 total, all with fuzzy-date data)
#   complessi (9 direct + 3 via CSV = 12 total)
#   saggi (16 direct + 5 via CSV = 21 total)
#   us (88 direct + 15 via CSV = 103 total)
#   reperti (93 direct + 375 via CSV = 468 total)
#   misure (~62), m_reperti_in_us (~41), m_reperti_in_complessi (~15)
#   RS relations (~56), geodata, userlinks (21 labeled), chart
#   sepolture (3 direct, osteology plugin active, multi-individual demo)
#
# Richest-record convention (see 19z below): for each of the 3 "direct"
# siti (Colle Oppio, Casale Selva Rotonda, Porto Nerone) — >=3 complessi;
# the 1st complesso has >=3 saggi; the 1st saggio has 20 us; the 1st us
# has 25 reperti. Every field type (incl. md, added in 19z) and every
# cross-cutting feature (RS, geodata, chrono, radiocarbon, zotero, files,
# misure) has at least one example within this subtree on all 3 sites.
# ════════════════════════════════════════════════════════════════════


# ── 19a. Table-level plugin configuration ───────────────────────────
# Must run before data insertion so RS, geodata and fuzzy_date columns
# (added via add_new_fld) are in place before records are created.

PUT {{base_url}}/{{app_name}}/api/config/table/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "siti", "label": "Siti Archeologici", "is_plugin": "0",
  "order": "nome", "id_field": "nome", "preview": ["nome", "localita"],
  "geodata": 1, "zotero": 1, "fuzzy_date": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# Add the five chrono_* fields to siti via the system field API.
# This creates both the DB column and the config entry (label, type, …)
# which is the correct path — it exercises add_new_fld and makes the
# fields fully visible to the rest of the system (search, export, etc.).
POST {{base_url}}/{{app_name}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_from", "label": "Dal (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_to", "label": "Al (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_label", "label": "Etichetta cronologica", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_certainty", "label": "Certezza", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_period", "label": "Periodo", "type": "select", "db_type": "TEXT", "vocabulary_set": "periodi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "complessi", "label": "Complessi", "is_plugin": "0",
  "order": "nome", "id_field": "nome", "preview": ["nome", "tipo"],
  "geodata": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "saggi", "label": "Saggi di scavo", "is_plugin": "0",
  "order": "nome", "id_field": "nome", "preview": ["nome", "anno"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "us", "label": "Unità stratigrafiche", "is_plugin": "0",
  "order": "sigla", "id_field": "sigla", "preview": ["sigla"],
  "rs": "sigla", "geodata": 1, "zotero": 1, "fuzzy_date": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# Add the five chrono_* fields to us (same pattern as siti above)
POST {{base_url}}/{{app_name}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_from", "label": "Dal (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_to", "label": "Al (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_label", "label": "Etichetta cronologica", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_certainty", "label": "Certezza", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_period", "label": "Periodo", "type": "select", "db_type": "TEXT", "vocabulary_set": "periodi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# Radiocarbon dating plugin — creates the us_radiocarbon plugin table
POST {{base_url}}/{{app_name}}/api/config/table/us/radiocarbon
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "reperti", "label": "Reperti mobili", "is_plugin": "0",
  "order": "inventario", "id_field": "inventario", "preview": ["inventario"],
  "geodata": 1, "zotero": 1, "fuzzy_date": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# Add the five chrono_* fields to reperti (same pattern as siti and us above)
POST {{base_url}}/{{app_name}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_from", "label": "Dal (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_to", "label": "Al (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_label", "label": "Etichetta cronologica", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_certainty", "label": "Certezza", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_period", "label": "Periodo", "type": "select", "db_type": "TEXT", "vocabulary_set": "periodi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19b. Vocabularies ────────────────────────────────────────────────

# periodi
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Preistorico" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Bronzo" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Ferro" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Greco-Ellenistico" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Romano" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Tardo Antico" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Alto Medioevo" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Basso Medioevo" }
HTTP 200

# sito_tipologia
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Abitato" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Necropoli" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Santuario" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Villa" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Insediamento rurale" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Sito subacqueo" }
HTTP 200

# complesso_tipo
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Edificio" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Vano" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Area esterna" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Struttura" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Ambiente" }
HTTP 200

# us_tipo
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Strato" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Taglio" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Struttura" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Riempimento" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Interfaccia" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Crollo" }
HTTP 200

# affidabilita
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "affidabilita", "def": "Certa" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "affidabilita", "def": "Probabile" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "affidabilita", "def": "Incerta" }
HTTP 200

# reperto_categoria
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Ceramica" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Metallo" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Litico" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Organico" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Vetro" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Laterizio" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Osso" }
HTTP 200

# reperto_classe
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Terra sigillata" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Ceramica comune" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Ceramica depurata" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Anfora" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Bronzo" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Ferro" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Selce" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Ossidiana" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Osso lavorato" }
HTTP 200

# stato_conservazione
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "stato_conservazione", "def": "Integro" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "stato_conservazione", "def": "Frammentario" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "stato_conservazione", "def": "Lacunoso" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "stato_conservazione", "def": "Frammentato" }
HTTP 200

# tipo_misura
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Lunghezza" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Larghezza" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Altezza" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Spessore" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Diametro" }
HTTP 200

# unita_misura
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "unita_misura", "def": "mm" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "unita_misura", "def": "cm" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "unita_misura", "def": "m" }
HTTP 200

# materiale_classe
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Ceramica da mensa" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Ceramica da cucina" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Ceramica da trasporto" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Ceramica fine" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Metallo ferroso" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Metallo non ferroso" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Vetro" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Laterizio" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Litico" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Osso lavorato" }
HTTP 200

# forma_tipo
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Coppa" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Piatto" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Brocca" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Anfora" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Olla" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Tegola" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Mattone" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Fibula" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Chiodo" }
HTTP 200


# ── 19c. Siti ────────────────────────────────────────────────────────

POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Colle Oppio",
    "localita": "Roma, Rione Esquilino",
    "tipologia": "Abitato",
    "contatto_email": "colle.oppio@dig.it",
    "descrizione": "Sito urbano romano con stratificazione ininterrotta dal VI sec. a.C. all'età moderna."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
sito_urbano: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Casale Selva Rotonda",
    "localita": "Velletri (RM)",
    "tipologia": "Villa",
    "contatto_email": "casale.selva@dig.it",
    "descrizione": "Villa rustica tardorepubblicana con impianto produttivo vinicolo."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
sito_rurale: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Porto Nerone",
    "localita": "Anzio (RM)",
    "tipologia": "Sito subacqueo",
    "contatto_email": "porto.nerone@dig.it",
    "descrizione": "Fondali con resti di strutture portuali e carichi anforari di età imperiale."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
sito_subacqueo: jsonpath "$.id"

# ── 19c-bis. Chrono fuzzy-date per i 3 siti ─────────────────────────
# I campi chrono_* sono stati aggiunti via add_new_fld in 19a;
# ora popoliamo i valori con update (id catturato sopra).

POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{sito_urbano}}, "core": { "chrono_from": -600, "chrono_to": 1800, "chrono_label": "-600/1800", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{sito_rurale}}, "core": { "chrono_from": -100, "chrono_to": 400, "chrono_label": "-100/400", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{sito_subacqueo}}, "core": { "chrono_from": 1, "chrono_to": 300, "chrono_label": "1/300", "chrono_certainty": 3, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19d. Complessi ───────────────────────────────────────────────────

# Colle Oppio: 3 complessi
POST {{base_url}}/{{app_name}}/api/record/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Edificio A",
    "tipo": "Edificio",
    "sito_id": {{sito_urbano}},
    "cronologia": "Romano,Tardo Antico",
    "descrizione": "Edificio pubblico con pavimento in opus sectile."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_edif_a: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Vano B1",
    "tipo": "Vano",
    "sito_id": {{sito_urbano}},
    "cronologia": "Basso Medioevo",
    "descrizione": "Vano con fosse di scarico medievali."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_vano_b1: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Area esterna N",
    "tipo": "Area esterna",
    "sito_id": {{sito_urbano}},
    "cronologia": "Romano",
    "descrizione": "Spazio aperto con tracce di lastricato."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_area_n: jsonpath "$.id"

# Casale Selva Rotonda: 3 complessi
POST {{base_url}}/{{app_name}}/api/record/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Pars urbana",
    "tipo": "Edificio",
    "sito_id": {{sito_rurale}},
    "cronologia": "Romano",
    "descrizione": "Settore residenziale con terme."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_pars_urb: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Pars rustica",
    "tipo": "Struttura",
    "sito_id": {{sito_rurale}},
    "cronologia": "Romano",
    "descrizione": "Settore produttivo: torcularium e dolia."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_pars_rust: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Necropoli di fondazione",
    "tipo": "Area esterna",
    "sito_id": {{sito_rurale}},
    "cronologia": "Ferro,Greco-Ellenistico",
    "descrizione": "Sepolture a fossa e a cassone della prima età del Ferro."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_necr: jsonpath "$.id"

# Porto Nerone: 2 complessi
POST {{base_url}}/{{app_name}}/api/record/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Molo ovest",
    "tipo": "Struttura",
    "sito_id": {{sito_subacqueo}},
    "cronologia": "Romano",
    "descrizione": "Struttura in opus pilarum sommersa a -4 m."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_molo: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome": "Deposito anforario",
    "tipo": "Ambiente",
    "sito_id": {{sito_subacqueo}},
    "cronologia": "Romano",
    "descrizione": "Concentrazione di anfore Dressel 2-4 e Dressel 20."
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_dep_anf: jsonpath "$.id"


# ── 19e. Saggi ───────────────────────────────────────────────────────

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio I",   "anno": 2018, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},    "descrizione": "Trincea est-ovest 5x2 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_01: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio II",  "anno": 2019, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},    "descrizione": "Ampliamento nord 3x3 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_02: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio III", "anno": 2020, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}},   "descrizione": "Saggio esplorativo 2x4 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_03: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio IV",  "anno": 2021, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}},   "descrizione": "Estensione saggio III." }, "plugins": {} }
HTTP 200
[Captures]
sag_04: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio V",   "anno": 2022, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},    "descrizione": "Pulizia lastricato." }, "plugins": {} }
HTTP 200
[Captures]
sag_05: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio A",   "anno": 2019, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}},  "descrizione": "Trincea terme 4x2 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_06: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio B",   "anno": 2020, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}},  "descrizione": "Ampliamento nord." }, "plugins": {} }
HTTP 200
[Captures]
sag_07: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio C",   "anno": 2021, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "descrizione": "Torcularium 3x3 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_08: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio D",   "anno": 2022, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},      "descrizione": "Sepolture 1-10." }, "plugins": {} }
HTTP 200
[Captures]
sag_09: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio D2",  "anno": 2023, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},      "descrizione": "Sepolture 11-20." }, "plugins": {} }
HTTP 200
[Captures]
sag_10: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio Sub-1", "anno": 2022, "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}},     "descrizione": "Quadrato 4x4 m -3/-5 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_11: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio Sub-2", "anno": 2023, "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_dep_anf}}, "descrizione": "Rilievo concentrazione anforica." }, "plugins": {} }
HTTP 200
[Captures]
sag_12: jsonpath "$.id"


# ── 19f. US records ──────────────────────────────────────────────────

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US001", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Strato di humus superficiale con materiale moderno.",     "interpretazione": "Accumulo recente",          "affidabilita": "Certa",    "periodo": "Basso Medioevo", "anno_scavo": 2018, "nr_cassette": 2, "profondita_cm": 15, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_1: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US002", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Strato argilloso grigio-marrone compatto.",              "interpretazione": "Livello di abbandono",      "affidabilita": "Certa",    "periodo": "Basso Medioevo", "anno_scavo": 2018, "nr_cassette": 3, "profondita_cm": 30, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_2: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US003", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Preparazione del piano in cocciopesto.",                 "interpretazione": "Piano di calpestio",        "affidabilita": "Certa",    "periodo": "Tardo Antico",   "anno_scavo": 2018, "nr_cassette": 1, "profondita_cm": 45, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_3: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US004", "tipo": "Taglio",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Taglio irregolare con pareti verticali.",               "interpretazione": "Fossa di deposizione",      "affidabilita": "Probabile","periodo": "Basso Medioevo", "anno_scavo": 2018, "profondita_cm": 60 }, "plugins": {} }
HTTP 200
[Captures]
us_id_4: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US005", "tipo": "Riempimento","sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Riempimento con terreno sciolto e inclusi carboniosi.", "interpretazione": "Riempimento secondario",    "affidabilita": "Certa",    "periodo": "Basso Medioevo", "anno_scavo": 2018, "nr_cassette": 2, "profondita_cm": 55 }, "plugins": {} }
HTTP 200
[Captures]
us_id_5: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US006", "tipo": "Struttura",  "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "descrizione": "Muro in laterizi con malta di calce.",                  "interpretazione": "Muro di delimitazione",     "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2019, "profondita_cm": 80, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_6: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US007", "tipo": "Crollo",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "descrizione": "Crollo con mattoni frantumati e tegole.",               "interpretazione": "Evento sismico",            "affidabilita": "Probabile","periodo": "Tardo Antico",   "anno_scavo": 2019, "nr_cassette": 4, "profondita_cm": 90 }, "plugins": {} }
HTTP 200
[Captures]
us_id_7: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US008", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "descrizione": "Pavimento in opus sectile lacunoso.",                   "interpretazione": "Piano di calpestio romano", "affidabilita": "Certa",    "periodo": "Tardo Antico",   "anno_scavo": 2019, "nr_cassette": 1, "profondita_cm": 100, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_8: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US009", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "descrizione": "Strato argilloso sterile grigio-verde.",               "interpretazione": "Alluvione naturale",        "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2020, "profondita_cm": 140 }, "plugins": {} }
HTTP 200
[Captures]
us_id_9: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US010", "tipo": "Taglio",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "descrizione": "Buca di palo circolare diam. 0,20 m.",                 "interpretazione": "Sostegno struttura lignea", "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2020, "profondita_cm": 135 }, "plugins": {} }
HTTP 200
[Captures]
us_id_10: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US011", "tipo": "Riempimento","sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "descrizione": "Riempimento buca di palo con terreno carbonioso.",     "interpretazione": "Disfacimento palo",        "affidabilita": "Probabile","periodo": "Ferro",          "anno_scavo": 2020 }, "plugins": {} }
HTTP 200
[Captures]
us_id_11: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US012", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_04}}, "descrizione": "Piano di frequentazione con impronte di pali.",        "interpretazione": "Pavimento capanna",        "affidabilita": "Certa",    "periodo": "Tardo Antico",   "anno_scavo": 2021, "nr_cassette": 3, "profondita_cm": 120, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_12: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US013", "tipo": "Interfaccia","sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},  "saggio_id": {{sag_05}}, "descrizione": "Superficie di frequentazione con lastricato.",         "interpretazione": "Piano stradale",           "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2022, "profondita_cm": 50, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_13: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US014", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},  "saggio_id": {{sag_05}}, "descrizione": "Sottofondo in ghiaia e frammenti ceramici.",           "interpretazione": "Preparazione lastricato",  "affidabilita": "Probabile","periodo": "Romano",         "anno_scavo": 2022, "nr_cassette": 2, "profondita_cm": 65 }, "plugins": {} }
HTTP 200
[Captures]
us_id_14: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US015", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Livello di crollo con tegole.",                       "interpretazione": "Abbandono terme",          "affidabilita": "Certa",    "periodo": "Tardo Antico",   "anno_scavo": 2019, "nr_cassette": 5, "profondita_cm": 35 }, "plugins": {} }
HTTP 200
[Captures]
us_id_15: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US016", "tipo": "Struttura",  "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Suspensurae in bipedales.",                           "interpretazione": "Pavimento riscaldato",     "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2019, "profondita_cm": 70, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_16: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US017", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_07}}, "descrizione": "Strato limoso di colmata.",                           "interpretazione": "Alluvione post-abbandono", "affidabilita": "Probabile","periodo": "Alto Medioevo",  "anno_scavo": 2020, "profondita_cm": 20 }, "plugins": {} }
HTTP 200
[Captures]
us_id_17: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US018", "tipo": "Struttura",  "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "saggio_id": {{sag_08}}, "descrizione": "Contrappeso del torchio in calcare.",                "interpretazione": "Impianto vinicolo",        "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2021, "profondita_cm": 55, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_18: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US019", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "saggio_id": {{sag_08}}, "descrizione": "Strato di lavorazione con vinaccioli carbonizzati.", "interpretazione": "Pavimento cantina",       "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2021, "nr_cassette": 2, "profondita_cm": 60 }, "plugins": {} }
HTTP 200
[Captures]
us_id_19: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US020", "tipo": "Taglio",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "descrizione": "Fossa sepolcrale T.1 a pianta rettangolare.",        "interpretazione": "Deposizione primaria",    "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2022, "profondita_cm": 85 }, "plugins": {} }
HTTP 200
[Captures]
us_id_20: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US021", "tipo": "Riempimento","sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "descrizione": "Riempimento fossa T.1 con scheletro e corredo.",     "interpretazione": "Deposizione inumato",     "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2022, "nr_cassette": 6, "profondita_cm": 80, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_21: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US022", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "descrizione": "Copertura della fossa con lastrine di tufo.",        "interpretazione": "Chiusura sepoltura",      "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2022, "profondita_cm": 75 }, "plugins": {} }
HTTP 200
[Captures]
us_id_22: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US023", "tipo": "Taglio",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "descrizione": "Fossa sepolcrale T.2 a pianta ovale.",              "interpretazione": "Deposizione primaria",    "affidabilita": "Probabile","periodo": "Ferro",          "anno_scavo": 2023, "profondita_cm": 90 }, "plugins": {} }
HTTP 200
[Captures]
us_id_23: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US024", "tipo": "Riempimento","sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "descrizione": "Riempimento T.2 con ossa e ceramica miniaturistica.","interpretazione": "Corredo funerario",        "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2023, "nr_cassette": 4, "profondita_cm": 85, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_24: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US025", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "descrizione": "Strato di terreno sterile preistoricogiallo.",      "interpretazione": "Substrato geologico",     "affidabilita": "Certa",    "periodo": "Preistorico",    "anno_scavo": 2023, "profondita_cm": 200 }, "plugins": {} }
HTTP 200
[Captures]
us_id_25: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US026", "tipo": "Struttura",  "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}},     "saggio_id": {{sag_11}}, "descrizione": "Filare di pilae in opus incertum sommerso.",    "interpretazione": "Fondazione molo",         "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2022, "profondita_cm": 300, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_26: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US027", "tipo": "Strato",     "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}},     "saggio_id": {{sag_11}}, "descrizione": "Sabbia di posidonia con frammenti ceramici.",   "interpretazione": "Sedimento marino",        "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2022, "profondita_cm": 400 }, "plugins": {} }
HTTP 200
[Captures]
us_id_27: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US028", "tipo": "Strato",     "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_dep_anf}},  "saggio_id": {{sag_12}}, "descrizione": "Concentrazione anfore Dressel 2-4 in giacitura primaria.", "interpretazione": "Carico nave naufragata", "affidabilita": "Probabile","periodo": "Romano",         "anno_scavo": 2023, "nr_cassette": 8, "profondita_cm": 500, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_28: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US029", "tipo": "Strato",     "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_dep_anf}},  "saggio_id": {{sag_12}}, "descrizione": "Strato di sabbia grossolana sotto le anfore.",   "interpretazione": "Fondale preesistente",    "affidabilita": "Incerta",  "periodo": "Romano",         "anno_scavo": 2023, "profondita_cm": 490 }, "plugins": {} }
HTTP 200
[Captures]
us_id_29: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US030", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Terreno di riporto moderno con ciottoli fluviali.",  "interpretazione": "Livellamento contemporaneo","affidabilita": "Certa",   "periodo": "Basso Medioevo", "anno_scavo": 2018, "profondita_cm": 10 }, "plugins": {} }
HTTP 200
[Captures]
us_id_30: jsonpath "$.id"


# ── 19g. RS relations — Harris Matrix (us) ──────────────────────────
# Chain: US030→US001→US002→US003→US006→US007→US008→US009→US025
#         ↓           ↓           ↓
#        US004       US005      US012
#         ↓
#        US011→US010
# Necropoli: US022→US021→US020 / US025 (substrato comune)
# Subacqueo: US027→US026, US029→US028

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_30}}, "second": {{us_id_1}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_1}},  "second": {{us_id_2}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_2}},  "second": {{us_id_3}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_2}},  "second": {{us_id_4}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_4}},  "second": {{us_id_5}},  "relation": 3 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_3}},  "second": {{us_id_6}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_6}},  "second": {{us_id_7}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_7}},  "second": {{us_id_8}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_8}},  "second": {{us_id_9}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_9}},  "second": {{us_id_12}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_9}},  "second": {{us_id_10}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_10}}, "second": {{us_id_11}}, "relation": 3 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_9}},  "second": {{us_id_25}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_22}}, "second": {{us_id_21}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_21}}, "second": {{us_id_20}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_22}}, "second": {{us_id_23}}, "relation": 5 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_23}}, "second": {{us_id_24}}, "relation": 3 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_20}}, "second": {{us_id_25}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_27}}, "second": {{us_id_26}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_29}}, "second": {{us_id_28}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_13}}, "second": {{us_id_14}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_15}}, "second": {{us_id_16}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_17}}, "second": {{us_id_15}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_18}}, "second": {{us_id_19}}, "relation": 9 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_16}}, "second": {{us_id_18}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19h. Geodata US — POST /api/geoface/feature con GeoJSON ──────────
POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_1}}, "geometry": {"type":"Point","coordinates":[12.4964,41.9028]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_6}}, "geometry": {"type":"Polygon","coordinates":[[[12.4960,41.9025],[12.4968,41.9025],[12.4968,41.9031],[12.4960,41.9031],[12.4960,41.9025]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_13}}, "geometry": {"type":"Point","coordinates":[12.4970,41.9030]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_18}}, "geometry": {"type":"Point","coordinates":[12.7320,41.6890]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_26}}, "geometry": {"type":"Polygon","coordinates":[[[12.6280,41.4510],[12.6290,41.4510],[12.6290,41.4520],[12.6280,41.4520],[12.6280,41.4510]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "siti", "id": {{sito_urbano}}, "geometry": {"type":"Polygon","coordinates":[[[12.4955,41.9020],[12.4975,41.9020],[12.4975,41.9035],[12.4955,41.9035],[12.4955,41.9020]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "siti", "id": {{sito_rurale}}, "geometry": {"type":"Polygon","coordinates":[[[12.7310,41.6880],[12.7340,41.6880],[12.7340,41.6900],[12.7310,41.6900],[12.7310,41.6880]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "siti", "id": {{sito_subacqueo}}, "geometry": {"type":"Polygon","coordinates":[[[12.6270,41.4500],[12.6300,41.4500],[12.6300,41.4525],[12.6270,41.4525],[12.6270,41.4500]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19i. Reperti ────────────────────────────────────────────────────

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP001", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_2}},  "categoria": "Ceramica", "classe": "Terra sigillata",    "stato_cons": "Frammentario", "descrizione": "Coppa in sigillata italica con bollo plantare Ateius.",   "data_ritrovamento": "2018-06-15", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_1: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP002", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_2}},  "categoria": "Ceramica", "classe": "Anfora",             "stato_cons": "Frammentato",   "descrizione": "Orlo di anfora Dressel 2-4 con titulus pictus.",          "data_ritrovamento": "2018-06-16", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_2: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP003", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_3}},  "categoria": "Metallo",  "classe": "Bronzo",             "stato_cons": "Integro",       "descrizione": "Fibula ad arco in bronzo tipo Aucissa.",                  "data_ritrovamento": "2018-07-02", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_3: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP004", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_5}},  "categoria": "Ceramica", "classe": "Ceramica comune",    "stato_cons": "Lacunoso",      "descrizione": "Olla con orlo a mandorla e decorazione a pettine.",       "data_ritrovamento": "2018-07-10" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_4: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP005", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "us_provenienza_id": {{us_id_7}},  "categoria": "Laterizio","classe": "Ceramica depurata",   "stato_cons": "Frammentario",  "descrizione": "Frammento di tegola con bollo laterizio officina Domizia.","data_ritrovamento": "2019-05-20" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_5: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP006", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "us_provenienza_id": {{us_id_8}},  "categoria": "Ceramica", "classe": "Terra sigillata",    "stato_cons": "Lacunoso",      "descrizione": "Piatto in sigillata africana tipo Hayes 50A.",            "data_ritrovamento": "2019-05-22", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_6: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP007", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "us_provenienza_id": {{us_id_12}}, "categoria": "Ceramica", "classe": "Ceramica depurata",  "stato_cons": "Frammentato",   "descrizione": "Coppa carenata con orlo estroflesso.",                   "data_ritrovamento": "2020-06-01" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_7: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP008", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "us_provenienza_id": {{us_id_12}}, "categoria": "Litico",   "classe": "Selce",              "stato_cons": "Integro",       "descrizione": "Lamella in selce ritoccata su entrambi i bordi.",        "data_ritrovamento": "2020-06-03", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_8: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP009", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},  "saggio_id": {{sag_05}}, "us_provenienza_id": {{us_id_13}}, "categoria": "Metallo",  "classe": "Ferro",              "stato_cons": "Lacunoso",      "descrizione": "Chiodo in ferro a sezione quadrata, lunghezza 8 cm.",    "data_ritrovamento": "2022-05-10" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_9: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP010", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},  "saggio_id": {{sag_05}}, "us_provenienza_id": {{us_id_14}}, "categoria": "Vetro",    "classe": "Ceramica comune",    "stato_cons": "Frammentario",  "descrizione": "Fondo di balsamario in vetro azzurrino soffiato.",        "data_ritrovamento": "2022-05-12", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_10: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP011", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Ceramica", "classe": "Terra sigillata",   "stato_cons": "Frammentario",  "descrizione": "Scodella in sigillata africana tipo Hayes 91.",           "data_ritrovamento": "2019-07-08", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_11: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP012", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Metallo",  "classe": "Bronzo",            "stato_cons": "Integro",       "descrizione": "Ago crinale in bronzo con punta integra.",               "data_ritrovamento": "2019-07-09" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_12: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP013", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "saggio_id": {{sag_08}}, "us_provenienza_id": {{us_id_19}}, "categoria": "Ceramica", "classe": "Anfora",            "stato_cons": "Lacunoso",      "descrizione": "Dolio con orlo ingrossato tipo Gauloise 4.",             "data_ritrovamento": "2021-06-14" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_13: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP014", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "saggio_id": {{sag_08}}, "us_provenienza_id": {{us_id_18}}, "categoria": "Litico",   "classe": "Selce",             "stato_cons": "Integro",       "descrizione": "Macinello in basalto vesuviano.",                        "data_ritrovamento": "2021-06-15", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_14: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP015", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "us_provenienza_id": {{us_id_21}}, "categoria": "Ceramica", "classe": "Ceramica depurata", "stato_cons": "Integro",       "descrizione": "Skyphos biansato miniaturistico tipo Corinto.",          "data_ritrovamento": "2022-07-20", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_15: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP016", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "us_provenienza_id": {{us_id_21}}, "categoria": "Metallo",  "classe": "Bronzo",            "stato_cons": "Integro",       "descrizione": "Fibula a sanguisuga in bronzo con arco foliato.",        "data_ritrovamento": "2022-07-21", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_16: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP017", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "us_provenienza_id": {{us_id_21}}, "categoria": "Osso",     "classe": "Osso lavorato",     "stato_cons": "Lacunoso",      "descrizione": "Spillone in osso con testa a bulbo.",                   "data_ritrovamento": "2022-07-22" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_17: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP018", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "us_provenienza_id": {{us_id_24}}, "categoria": "Ceramica", "classe": "Ceramica comune",   "stato_cons": "Integro",       "descrizione": "Tazza monoansata con decorazione incisa a bande.",       "data_ritrovamento": "2023-06-11", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_18: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP019", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "us_provenienza_id": {{us_id_24}}, "categoria": "Litico",   "classe": "Ossidiana",         "stato_cons": "Integro",       "descrizione": "Grattatoio in ossidiana di Palmarola.",                 "data_ritrovamento": "2023-06-12", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_19: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP020", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_dep_anf}},  "saggio_id": {{sag_12}}, "us_provenienza_id": {{us_id_28}}, "categoria": "Ceramica", "classe": "Anfora",            "stato_cons": "Integro",       "descrizione": "Anfora Dressel 2-4 integra con pece residua interna.",  "data_ritrovamento": "2023-08-05", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_20: jsonpath "$.id"


# ── 19i-bis. Chrono fuzzy-date per US e reperti ─────────────────────
# US records: 7 US datati con certezza o probabilità
POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_3}}, "core": { "chrono_from": 300, "chrono_to": 450, "chrono_label": "300/450", "chrono_certainty": 1, "chrono_period": "Tardo Antico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_8}}, "core": { "chrono_from": 340, "chrono_to": 460, "chrono_label": "340/460", "chrono_certainty": 1, "chrono_period": "Tardo Antico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_12}}, "core": { "chrono_from": 400, "chrono_to": 520, "chrono_label": "400/520", "chrono_certainty": 2, "chrono_period": "Tardo Antico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_21}}, "core": { "chrono_from": -650, "chrono_to": -450, "chrono_label": "-650/-450", "chrono_certainty": 1, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_24}}, "core": { "chrono_from": -700, "chrono_to": -500, "chrono_label": "-700/-500", "chrono_certainty": 2, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_26}}, "core": { "chrono_from": -50, "chrono_to": 200, "chrono_label": "-50/200", "chrono_certainty": 1, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_28}}, "core": { "chrono_from": -50, "chrono_to": 100, "chrono_label": "-50/100", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# Reperti: 9 reperti diagnostici datati
POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_1}}, "core": { "chrono_from": -30, "chrono_to": 20, "chrono_label": "-30/20", "chrono_certainty": 1, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_2}}, "core": { "chrono_from": -50, "chrono_to": 150, "chrono_label": "-50/150", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_3}}, "core": { "chrono_from": -15, "chrono_to": 70, "chrono_label": "-15/70", "chrono_certainty": 1, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_6}}, "core": { "chrono_from": 250, "chrono_to": 325, "chrono_label": "250/325", "chrono_certainty": 1, "chrono_period": "Tardo Antico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_11}}, "core": { "chrono_from": 350, "chrono_to": 450, "chrono_label": "350/450", "chrono_certainty": 1, "chrono_period": "Tardo Antico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_15}}, "core": { "chrono_from": -600, "chrono_to": -480, "chrono_label": "-600/-480", "chrono_certainty": 2, "chrono_period": "Greco-Ellenistico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_16}}, "core": { "chrono_from": -700, "chrono_to": -400, "chrono_label": "-700/-400", "chrono_certainty": 2, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_18}}, "core": { "chrono_from": -800, "chrono_to": -600, "chrono_label": "-800/-600", "chrono_certainty": 2, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_20}}, "core": { "chrono_from": -50, "chrono_to": 150, "chrono_label": "-50/150", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19i-ter. Datazioni al radiocarbonio (plugin us_radiocarbon) ──────
# BP/error chosen so the server-computed calibration lands close to each
# US's chrono_period above (see 19i-bis) — cal_1s/cal_2s are NOT sent by
# the client, they are always recomputed server-side (Record::saveRecord).

# US006 — "Romano": 1900±30 BP calibrates to ~88-203 calAD (1s)
POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_6}}, "core": {}, "plugins": { "us_radiocarbon": [
  { "id": null, "_isNew": true, "fields": { "lab_code": "Beta-412001", "bp": 1900, "bp_error": 30, "material": "charcoal" } }
] } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# US003 — "Tardo Antico" (chrono 300/450): 1700±30 BP calibrates to ~263-406 calAD (1s)
POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_3}}, "core": {}, "plugins": { "us_radiocarbon": [
  { "id": null, "_isNew": true, "fields": { "lab_code": "Beta-412002", "bp": 1700, "bp_error": 30, "material": "bone collagen" } }
] } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# US021 — "Ferro" (chrono -650/-450): 2450±40 BP, Hallstatt-plateau region —
# deliberately a wide 2-sigma range, realistic for Iron Age C14 dates.
POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_21}}, "core": {}, "plugins": { "us_radiocarbon": [
  { "id": null, "_isNew": true, "fields": { "lab_code": "Beta-412003", "bp": 2450, "bp_error": 40, "material": "charcoal" } }
] } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19j. Misure (plugin → reperti) ───────────────────────────────────

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_1}}, "tipo_misura": "Diametro",  "valore": "18.5", "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_1}}, "tipo_misura": "Altezza",   "valore": "5.2",  "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_3}}, "tipo_misura": "Lunghezza", "valore": "42",   "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_3}}, "tipo_misura": "Larghezza", "valore": "12",   "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_6}}, "tipo_misura": "Diametro",  "valore": "22",   "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_8}}, "tipo_misura": "Lunghezza", "valore": "35",   "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_8}}, "tipo_misura": "Larghezza", "valore": "8",    "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_15}}, "tipo_misura": "Altezza",  "valore": "7.8",  "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_15}}, "tipo_misura": "Diametro", "valore": "9.2",  "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_16}}, "tipo_misura": "Lunghezza","valore": "58",   "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_20}}, "tipo_misura": "Altezza",  "valore": "52",   "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_20}}, "tipo_misura": "Diametro", "valore": "14",   "unita": "cm" }, "plugins": {} }
HTTP 200


# ── 19k. m_reperti_in_us (plugin → us) ──────────────────────────────

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{us_id_2}},  "materiale_classe": "Ceramica da mensa",    "forma_tipo": "Coppa",  "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 12, "nr_totale_fondi": 8,  "nr_totale_pareti": 145, "nr_totale_anse": 4,  "mni": 5, "peso_totale": "1240 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{us_id_2}},  "materiale_classe": "Ceramica da trasporto", "forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 6,  "nr_totale_fondi": 3,  "nr_totale_pareti": 220, "nr_totale_anse": 12, "mni": 3, "peso_totale": "3680 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{us_id_5}},  "materiale_classe": "Ceramica da cucina",    "forma_tipo": "Olla",   "forma_completa": 0, "datazione_generica": "Basso Medioevo","nr_totale_orli": 8,  "nr_totale_fondi": 6,  "nr_totale_pareti": 98,  "nr_totale_anse": 0,  "mni": 4, "peso_totale": "980 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{us_id_9}},  "materiale_classe": "Ceramica depurata",     "forma_tipo": "Coppa",  "forma_completa": 1, "datazione_generica": "Ferro",        "nr_totale_orli": 3,  "nr_totale_fondi": 2,  "nr_totale_pareti": 41,  "nr_totale_anse": 0,  "mni": 2, "peso_totale": "320 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{us_id_12}}, "materiale_classe": "Ceramica depurata",     "forma_tipo": "Piatto", "forma_completa": 0, "datazione_generica": "Ferro",        "nr_totale_orli": 5,  "nr_totale_fondi": 4,  "nr_totale_pareti": 67,  "nr_totale_anse": 0,  "mni": 3, "peso_totale": "560 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{us_id_19}}, "materiale_classe": "Ceramica da trasporto", "forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 24, "nr_totale_fondi": 15, "nr_totale_pareti": 530, "nr_totale_anse": 48, "mni": 12, "peso_totale": "18500 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{us_id_21}}, "materiale_classe": "Ceramica da mensa",    "forma_tipo": "Coppa",  "forma_completa": 1, "datazione_generica": "Ferro",        "nr_totale_orli": 2,  "nr_totale_fondi": 2,  "nr_totale_pareti": 18,  "nr_totale_anse": 2,  "mni": 1, "peso_totale": "145 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{us_id_28}}, "materiale_classe": "Ceramica da trasporto", "forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 42, "nr_totale_fondi": 38, "nr_totale_pareti": 820, "nr_totale_anse": 84, "mni": 21, "peso_totale": "65000 g" }, "plugins": {} }
HTTP 200


# ── 19l. m_reperti_in_complessi (plugin → complessi) ─────────────────

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{compl_edif_a}},   "materiale_classe": "Ceramica da mensa",    "forma_tipo": "Coppa",  "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 45, "nr_totale_fondi": 32, "nr_totale_pareti": 680, "nr_totale_anse": 12, "mni": 18, "peso_totale": "8400 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{compl_edif_a}},   "materiale_classe": "Ceramica da trasporto","forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 18, "nr_totale_fondi": 12, "nr_totale_pareti": 340, "nr_totale_anse": 36, "mni": 9, "peso_totale": "22000 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{compl_pars_rust}}, "materiale_classe": "Ceramica da trasporto","forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 86, "nr_totale_fondi": 71, "nr_totale_pareti": 1840,"nr_totale_anse": 172,"mni": 43, "peso_totale": "145000 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{compl_necr}},      "materiale_classe": "Ceramica da mensa",    "forma_tipo": "Coppa",  "forma_completa": 1, "datazione_generica": "Ferro",        "nr_totale_orli": 12, "nr_totale_fondi": 10, "nr_totale_pareti": 89,  "nr_totale_anse": 8,  "mni": 6, "peso_totale": "1250 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{compl_dep_anf}},   "materiale_classe": "Ceramica da trasporto","forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 156,"nr_totale_fondi": 148,"nr_totale_pareti": 3200,"nr_totale_anse": 312,"mni": 78, "peso_totale": "380000 g" }, "plugins": {} }
HTTP 200


# ── 19m. Geodata reperti ────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "reperti", "id": {{rep_id_1}}, "geometry": {"type":"Point","coordinates":[12.4963,41.9027]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "reperti", "id": {{rep_id_3}}, "geometry": {"type":"Point","coordinates":[12.4965,41.9029]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "reperti", "id": {{rep_id_15}}, "geometry": {"type":"Point","coordinates":[12.7322,41.6891]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "reperti", "id": {{rep_id_20}}, "geometry": {"type":"Point","coordinates":[12.6283,41.4513]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19n. Userlinks — POST /api/manual-link ──────────────────────────
# Manual links with labels: 21 total
#   10 us ↔ reperti  (interpretative/diagnostic)
#    6 us ↔ us       (non-stratigraphic — phase, event, function)
#    5 reperti ↔ reperti  (typological associations)

# ── us ↔ reperti ────────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_3}}, "tb_two": "reperti", "id_two": {{rep_id_6}}, "label": "ceramica diagnostica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_8}}, "tb_two": "reperti", "id_two": {{rep_id_1}}, "label": "elemento datante" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_9}}, "tb_two": "reperti", "id_two": {{rep_id_7}}, "label": "rinvenuto in" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_9}}, "tb_two": "reperti", "id_two": {{rep_id_8}}, "label": "rinvenuto in" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_21}}, "tb_two": "reperti", "id_two": {{rep_id_15}}, "label": "corredo funerario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_21}}, "tb_two": "reperti", "id_two": {{rep_id_16}}, "label": "corredo funerario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_21}}, "tb_two": "reperti", "id_two": {{rep_id_17}}, "label": "corredo funerario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_24}}, "tb_two": "reperti", "id_two": {{rep_id_18}}, "label": "corredo funerario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_24}}, "tb_two": "reperti", "id_two": {{rep_id_19}}, "label": "elemento datante" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_28}}, "tb_two": "reperti", "id_two": {{rep_id_20}}, "label": "carico originario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── us ↔ us (non-stratigraphic interpretative links) ─────────────────
POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_3}}, "tb_two": "us", "id_two": {{us_id_8}}, "label": "sequenza pavimentale" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_6}}, "tb_two": "us", "id_two": {{us_id_7}}, "label": "crollo del muro" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_7}}, "tb_two": "us", "id_two": {{us_id_15}}, "label": "evento coevo" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_18}}, "tb_two": "us", "id_two": {{us_id_19}}, "label": "impianto produttivo" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_20}}, "tb_two": "us", "id_two": {{us_id_23}}, "label": "sepolture coeve" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_21}}, "tb_two": "us", "id_two": {{us_id_24}}, "label": "corredi coevi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── reperti ↔ reperti (typological associations) ─────────────────────
POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_1}}, "tb_two": "reperti", "id_two": {{rep_id_6}}, "label": "stessa classe ceramica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_3}}, "tb_two": "reperti", "id_two": {{rep_id_16}}, "label": "stesso tipo funzionale" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_7}}, "tb_two": "reperti", "id_two": {{rep_id_15}}, "label": "forma analoga" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_15}}, "tb_two": "reperti", "id_two": {{rep_id_18}}, "label": "ceramica miniaturistica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_13}}, "tb_two": "reperti", "id_two": {{rep_id_20}}, "label": "ceramica da trasporto" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19o. Chart ──────────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/charts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "US per tipo",
  "definition": {
    "tb": "us",
    "type": "pie",
    "x_field": "tipo",
    "y_field": "id",
    "y_function": "COUNT"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/charts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "Reperti per categoria",
  "definition": {
    "tb": "reperti",
    "type": "bar",
    "x_field": "categoria",
    "y_field": "id",
    "y_function": "COUNT"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19p. Verify the Matrix has nodes ────────────────────────────────
GET {{base_url}}/{{app_name}}/api/rs/matrix?tb=us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.nodes"  isCollection
jsonpath "$.nodes"  count > 0


# ════════════════════════════════════════════════════════════════════
# Phase 19b — Extended seed (merged into phase 19)
#
# ID assumptions (fresh demo after the first 3 siti above):
#   siti already in DB:     3  → new siti       IDs  4-18
#   complessi already:      8  → new complessi   IDs  9-11
#   saggi already:         12  → new saggi       IDs 13-17
#   us already:            30  → new US          IDs 31-45
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

POST {{base_url}}/{{app_name}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_siti.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
siti_tid: jsonpath "$.temp_id"


POST {{base_url}}/{{app_name}}/api/import/data
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


# ── Fuzzy date (chrono) per i 15 siti importati via CSV (IDs 4-18) ────
# ID 4 — Monte Bibele: sito celtico del Ferro IV–II sec. a.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 4, "core": { "chrono_from": -400, "chrono_to": -200, "chrono_label": "-400/-200", "chrono_certainty": 2, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 5 — Cuma: colonia greca dall'VIII sec. a.C. al V sec. d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 5, "core": { "chrono_from": -750, "chrono_to": 500, "chrono_label": "-750/500", "chrono_certainty": 2, "chrono_period": "Greco-Ellenistico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 6 — Populonia: città etrusca VII sec. a.C. – I sec. d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 6, "core": { "chrono_from": -700, "chrono_to": 100, "chrono_label": "-700/100", "chrono_certainty": 2, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 7 — Locri Epizefiri: colonia locrese VII sec. a.C. – III sec. d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 7, "core": { "chrono_from": -680, "chrono_to": 300, "chrono_label": "-680/300", "chrono_certainty": 1, "chrono_period": "Greco-Ellenistico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 8 — Metaponto: santuario arcaico di Hera VII–III sec. a.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 8, "core": { "chrono_from": -700, "chrono_to": -200, "chrono_label": "-700/-200", "chrono_certainty": 2, "chrono_period": "Greco-Ellenistico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 9 — Valle dei Templi: area sacra ellenistica VI–I sec. a.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 9, "core": { "chrono_from": -580, "chrono_to": -100, "chrono_label": "-580/-100", "chrono_certainty": 1, "chrono_period": "Greco-Ellenistico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 10 — Valle d'Ansanto: santuario italico IV sec. a.C. – I sec. d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 10, "core": { "chrono_from": -400, "chrono_to": 100, "chrono_label": "-400/100", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 11 — Cerveteri: necropoli etrusca della Banditaccia VIII–I sec. a.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 11, "core": { "chrono_from": -800, "chrono_to": -100, "chrono_label": "-800/-100", "chrono_certainty": 1, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 12 — Tarquinia: necropoli Monterozzi con pitture parietali VII–I sec. a.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 12, "core": { "chrono_from": -700, "chrono_to": -100, "chrono_label": "-700/-100", "chrono_certainty": 1, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 13 — Baia: parco sommerso con ville e terme romane I sec. a.C. – IV sec. d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 13, "core": { "chrono_from": -50, "chrono_to": 400, "chrono_label": "-50/400", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 14 — Porto di Caligola: porto di età giulio-claudia I–II sec. d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 14, "core": { "chrono_from": 37, "chrono_to": 200, "chrono_label": "37/200", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 15 — Gragnano: villa rustica vinicola I sec. a.C. – IV sec. d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 15, "core": { "chrono_from": -100, "chrono_to": 400, "chrono_label": "-100/400", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 16 — Oplontis B: edificio commerciale distrutto dal 79 d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 16, "core": { "chrono_from": -50, "chrono_to": 79, "chrono_label": "-50/79", "chrono_certainty": 1, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 17 — Quintiliolo: villa tardorepubblicana I sec. a.C. – I sec. d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 17, "core": { "chrono_from": -100, "chrono_to": 100, "chrono_label": "-100/100", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 18 — Lucus Feroniae: forum e colonia romana IV sec. a.C. – IV sec. d.C.
POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 18, "core": { "chrono_from": -300, "chrono_to": 400, "chrono_label": "-300/400", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── Upload + import complessi (sito 1) ────────────────────────────────

POST {{base_url}}/{{app_name}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_complessi.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_tid: jsonpath "$.temp_id"


POST {{base_url}}/{{app_name}}/api/import/data
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

POST {{base_url}}/{{app_name}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_saggi.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
saggi_tid: jsonpath "$.temp_id"


POST {{base_url}}/{{app_name}}/api/import/data
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

POST {{base_url}}/{{app_name}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_us.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_tid: jsonpath "$.temp_id"


POST {{base_url}}/{{app_name}}/api/import/data
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

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 31, "id_second": 33, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 31, "id_second": 34, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 32, "id_second": 34, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 32, "id_second": 35, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 33, "id_second": 36, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 34, "id_second": 37, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 35, "id_second": 37, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 35, "id_second": 38, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 36, "id_second": 39, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 37, "id_second": 40, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 38, "id_second": 40, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 38, "id_second": 41, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 39, "id_second": 42, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 40, "id_second": 43, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 40, "id_second": 44, "relation": 1 }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id_first": 41, "id_second": 45, "relation": 1 }
HTTP 200


# ── Upload + import reperti (375, 25 per US) ──────────────────────────

POST {{base_url}}/{{app_name}}/api/import/preview-file
Authorization: Bearer {{jwt}}
[MultipartFormData]
type: csv
file: file,fixtures/seed_reperti.csv; type=text/csv
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rep_tid: jsonpath "$.temp_id"


POST {{base_url}}/{{app_name}}/api/import/data
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

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":4,"geometry":{"type":"Point","coordinates":[11.3447,44.3211]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":5,"geometry":{"type":"Point","coordinates":[14.0520,40.8468]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":6,"geometry":{"type":"Point","coordinates":[10.5000,43.0000]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":7,"geometry":{"type":"Point","coordinates":[16.2500,38.2333]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":8,"geometry":{"type":"Point","coordinates":[16.8250,40.3750]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":9,"geometry":{"type":"Point","coordinates":[13.5860,37.2960]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":10,"geometry":{"type":"Point","coordinates":[15.1000,41.0167]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":11,"geometry":{"type":"Point","coordinates":[11.9869,42.0000]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":12,"geometry":{"type":"Point","coordinates":[11.7500,42.2500]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":13,"geometry":{"type":"Point","coordinates":[14.0850,40.7600]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":14,"geometry":{"type":"Point","coordinates":[12.5020,41.0560]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":15,"geometry":{"type":"Point","coordinates":[14.5330,40.7530]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":16,"geometry":{"type":"Point","coordinates":[14.4520,40.7570]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":17,"geometry":{"type":"Point","coordinates":[12.7900,41.9600]}}
HTTP 200

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"siti","id":18,"geometry":{"type":"Point","coordinates":[12.5420,42.1440]}}
HTTP 200


# ── Geodata: 1 poligono per complesso di sito 1 (IDs 9-11) ───────────
# Piccoli poligoni centrati su Monte Bibele (11.34, 44.32)

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"complessi","id":9,"geometry":{"type":"Polygon","coordinates":[[[11.3440,44.3205],[11.3455,44.3205],[11.3455,44.3215],[11.3440,44.3215],[11.3440,44.3205]]]}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"complessi","id":10,"geometry":{"type":"Polygon","coordinates":[[[11.3456,44.3205],[11.3470,44.3205],[11.3470,44.3218],[11.3456,44.3218],[11.3456,44.3205]]]}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{"tb":"complessi","id":11,"geometry":{"type":"Polygon","coordinates":[[[11.3435,44.3218],[11.3460,44.3218],[11.3460,44.3230],[11.3435,44.3230],[11.3435,44.3218]]]}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19o. Sepolture — demo inventario osteologico ─────────────────
#
# Crea la tabella `sepolture` e attiva il plugin osteologico.
# Inserisce 3 sepolture con inventario completo delle ossa:
#   SEP001 — adulto, buona conservazione (27 ossa documentate)
#   SEP002 — infante ca. 4-6 anni, conservazione scarsa
#   SEP003 — sepoltura doppia (2 individui, uno in connessione,
#             l'altro disarticolato)
# ════════════════════════════════════════════════════════════════════

# ── 19o-1. Crea tabella sepolture ────────────────────────────────
POST {{base_url}}/{{app_name}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sepolture", "label": "Sepolture", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/sepolture
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sepolture", "label": "Sepolture", "is_plugin": "0", "order": "sigla", "id_field": "sigla", "preview": ["sigla", "eta_biologica", "sesso_biologico"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19o-2. Campi ─────────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/config/table/sepolture/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sigla", "label": "Sigla", "type": "text", "db_type": "TEXT", "check": ["not_empty", "no_dupl"] }
HTTP 200

POST {{base_url}}/{{app_name}}/api/config/table/sepolture/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sito", "label": "Sito", "type": "text", "db_type": "TEXT" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/config/table/sepolture/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tipo_sepoltura", "label": "Tipo di sepoltura", "type": "select", "db_type": "TEXT", "vocabulary_set": "tipo_sepoltura" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/config/table/sepolture/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "posizione", "label": "Posizione scheletrica", "type": "select", "db_type": "TEXT", "vocabulary_set": "posizione_scheletrica" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/config/table/sepolture/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "orientamento", "label": "Orientamento", "type": "select", "db_type": "TEXT", "vocabulary_set": "orientamento_sepoltura" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/config/table/sepolture/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "eta_biologica", "label": "Età biologica", "type": "select", "db_type": "TEXT", "vocabulary_set": "eta_biologica" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/config/table/sepolture/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sesso_biologico", "label": "Sesso biologico", "type": "select", "db_type": "TEXT", "vocabulary_set": "sesso_biologico" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/config/table/sepolture/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nmi", "label": "NMI", "type": "text", "db_type": "INTEGER" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/config/table/sepolture/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "note", "label": "Note", "type": "long_text", "db_type": "TEXT" }
HTTP 200

# ── 19o-3. Vocabolari ────────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_sepoltura", "def": "Inumazione" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_sepoltura", "def": "Incinerazione" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_sepoltura", "def": "Cremazione diretta" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "posizione_scheletrica", "def": "Supino" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "posizione_scheletrica", "def": "Prono" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "posizione_scheletrica", "def": "Laterale destro" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "posizione_scheletrica", "def": "Laterale sinistro" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "posizione_scheletrica", "def": "Rannicchiato" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "orientamento_sepoltura", "def": "N-S" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "orientamento_sepoltura", "def": "S-N" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "orientamento_sepoltura", "def": "E-O" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "orientamento_sepoltura", "def": "O-E" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "orientamento_sepoltura", "def": "NE-SO" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "eta_biologica", "def": "Infante I (0-6 anni)" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "eta_biologica", "def": "Infante II (7-12 anni)" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "eta_biologica", "def": "Adolescente" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "eta_biologica", "def": "Adulto giovane (20-35)" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "eta_biologica", "def": "Adulto maturo (35-50)" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "eta_biologica", "def": "Adulto senile (>50)" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "eta_biologica", "def": "Indeterminata" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sesso_biologico", "def": "Maschile" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sesso_biologico", "def": "Femminile" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sesso_biologico", "def": "Probabilmente maschile" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sesso_biologico", "def": "Probabilmente femminile" }
HTTP 200

POST {{base_url}}/{{app_name}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sesso_biologico", "def": "Indeterminato" }
HTTP 200

# ── 19o-4. Attiva plugin osteologico ─────────────────────────────
POST {{base_url}}/{{app_name}}/api/config/table/sepolture/osteology
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "osteology_activated"

# ── 19o-5. SEP001 — Adulto maschio, buona conservazione ──────────
POST {{base_url}}/{{app_name}}/api/record/sepolture
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "SEP001",
    "sito": "Colle Oppio — Roma",
    "tipo_sepoltura": "Inumazione",
    "posizione": "Supino",
    "orientamento": "N-S",
    "eta_biologica": "Adulto maturo (35-50)",
    "sesso_biologico": "Maschile",
    "nmi": "1",
    "note": "Scheletro in connessione anatomica parziale, testa verso nord. Buona conservazione generale."
  },
  "plugins": {}
}
HTTP 200
[Captures]
sep001_id: jsonpath "$.id"
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/sepolture
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{sep001_id}},
  "core": {
    "osteo_data": "{\"individuals\":[{\"id\":1,\"label\":\"Individuo 1\",\"notes\":\"Adulto, connessione anatomica parziale\",\"bones\":{\"cranium\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\"},\"mandible\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\"},\"cervical_vertebrae\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\"},\"thoracic_vertebrae\":{\"present\":true,\"conservation\":\"lt50\",\"certainty\":\"certain\"},\"lumbar_vertebrae\":{\"present\":true,\"conservation\":\"fragmentary\",\"certainty\":\"certain\"},\"sacrum\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\"},\"clavicle_right\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"clavicle_left\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"scapula_right\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"scapula_left\":{\"present\":true,\"conservation\":\"fragmentary\",\"certainty\":\"probable\",\"laterality_certainty\":\"uncertain\"},\"ribs_right\":{\"present\":true,\"conservation\":\"fragmentary\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"ribs_left\":{\"present\":true,\"conservation\":\"fragmentary\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"humerus_right\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"humerus_left\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"radius_right\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"radius_left\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"ulna_right\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"ulna_left\":{\"present\":true,\"conservation\":\"lt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"ilium_right\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"ilium_left\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"femur_right\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"femur_left\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"tibia_right\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"tibia_left\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"fibula_right\":{\"present\":true,\"conservation\":\"lt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"fibula_left\":{\"present\":true,\"conservation\":\"fragmentary\",\"certainty\":\"certain\",\"laterality_certainty\":\"uncertain\"},\"calcaneus_right\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"calcaneus_left\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"}}}]}"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19o-6. SEP002 — Infante, conservazione scarsa ────────────────
POST {{base_url}}/{{app_name}}/api/record/sepolture
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "SEP002",
    "sito": "Colle Oppio — Roma",
    "tipo_sepoltura": "Inumazione",
    "posizione": "Laterale destro",
    "orientamento": "E-O",
    "eta_biologica": "Infante I (0-6 anni)",
    "sesso_biologico": "Indeterminato",
    "nmi": "1",
    "note": "Non-adulto ca. 4-6 anni. Ossa molto fragili, solo elementi maggiori conservati."
  },
  "plugins": {}
}
HTTP 200
[Captures]
sep002_id: jsonpath "$.id"
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/sepolture
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{sep002_id}},
  "core": {
    "osteo_data": "{\"individuals\":[{\"id\":1,\"label\":\"Infante\",\"notes\":\"Non-adulto ca. 4-6 anni, ossa molto fragili\",\"bones\":{\"cranium\":{\"present\":true,\"conservation\":\"lt50\",\"certainty\":\"certain\"},\"mandible\":{\"present\":true,\"conservation\":\"fragmentary\",\"certainty\":\"certain\"},\"humerus_right\":{\"present\":true,\"conservation\":\"traces\",\"certainty\":\"probable\",\"laterality_certainty\":\"uncertain\"},\"femur_right\":{\"present\":true,\"conservation\":\"fragmentary\",\"certainty\":\"probable\",\"laterality_certainty\":\"uncertain\"},\"femur_left\":{\"present\":true,\"conservation\":\"traces\",\"certainty\":\"uncertain\",\"laterality_certainty\":\"uncertain\"},\"tibia_right\":{\"present\":false}}}]}"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19o-7. SEP003 — Sepoltura doppia (2 individui) ───────────────
POST {{base_url}}/{{app_name}}/api/record/sepolture
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla": "SEP003",
    "sito": "Monte Bibele — Monterenzio (BO)",
    "tipo_sepoltura": "Inumazione",
    "posizione": "Supino",
    "orientamento": "NE-SO",
    "eta_biologica": "Adulto giovane (20-35)",
    "sesso_biologico": "Indeterminato",
    "nmi": "2",
    "note": "Sepoltura doppia: individuo A in connessione anatomica, individuo B disarticolato, probabilmente deposto in un secondo momento."
  },
  "plugins": {}
}
HTTP 200
[Captures]
sep003_id: jsonpath "$.id"
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/sepolture
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{sep003_id}},
  "core": {
    "osteo_data": "{\"individuals\":[{\"id\":1,\"label\":\"Individuo A\",\"notes\":\"Adulto maschio, in connessione anatomica\",\"bones\":{\"cranium\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\"},\"mandible\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\"},\"humerus_right\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"humerus_left\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"femur_right\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"femur_left\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"tibia_right\":{\"present\":true,\"conservation\":\"complete\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"tibia_left\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"ilium_right\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"},\"ilium_left\":{\"present\":true,\"conservation\":\"gt50\",\"certainty\":\"certain\",\"laterality_certainty\":\"certain\"}}},{\"id\":2,\"label\":\"Individuo B\",\"notes\":\"Adulto, resti disarticolati deposti successivamente\",\"bones\":{\"cranium\":{\"present\":true,\"conservation\":\"fragmentary\",\"certainty\":\"certain\"},\"mandible\":{\"present\":false},\"humerus_right\":{\"present\":true,\"conservation\":\"lt50\",\"certainty\":\"probable\",\"laterality_certainty\":\"uncertain\"},\"femur_left\":{\"present\":true,\"conservation\":\"fragmentary\",\"certainty\":\"probable\",\"laterality_certainty\":\"uncertain\"},\"tibia_left\":{\"present\":true,\"conservation\":\"lt50\",\"certainty\":\"uncertain\",\"laterality_certainty\":\"uncertain\"}}}]}"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19r. File allegati — immagini demo ────────────────────────────────
# Riusa la stessa fixture della fase 34 (unico asset immagine disponibile
# nel repo) su alcuni record di reperti/sepolture/siti, con descrizione
# e keyword realistiche via PATCH /api/file/{id}. Prima di questa fase il
# seed demo non allegava alcuna immagine.

POST {{base_url}}/{{app_name}}/api/record/reperti/{{rep_id_1}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
img_rep_1: jsonpath "$.file.id"

PATCH {{base_url}}/{{app_name}}/api/file/{{img_rep_1}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "description": "Fibula in bronzo, vista dall'alto", "keywords": "reperto, metallo, fibula" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti/{{rep_id_2}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
img_rep_2: jsonpath "$.file.id"

PATCH {{base_url}}/{{app_name}}/api/file/{{img_rep_2}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "description": "Frammento ceramico decorato", "keywords": "reperto, ceramica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti/{{rep_id_3}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
img_rep_3: jsonpath "$.file.id"

PATCH {{base_url}}/{{app_name}}/api/file/{{img_rep_3}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "description": "Moneta in argento, dritto e rovescio", "keywords": "reperto, numismatica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/sepolture/{{sep001_id}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
img_sep_1: jsonpath "$.file.id"

PATCH {{base_url}}/{{app_name}}/api/file/{{img_sep_1}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "description": "SEP001 — inumazione in connessione anatomica parziale", "keywords": "sepoltura, scavo, foto di scavo" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/sepolture/{{sep002_id}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
img_sep_2: jsonpath "$.file.id"

PATCH {{base_url}}/{{app_name}}/api/file/{{img_sep_2}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "description": "SEP002 — inumazione infantile", "keywords": "sepoltura, scavo, foto di scavo" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/siti/{{sito_urbano}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
img_sito_1: jsonpath "$.file.id"

PATCH {{base_url}}/{{app_name}}/api/file/{{img_sito_1}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "description": "Veduta generale dell'area di scavo", "keywords": "sito, panoramica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19s. Assemblage Analysis — esempio di pivot ───────────────────────
# Conteggio dei reperti per US di provenienza (righe) e categoria
# (colonne) — usa i campi già seedati in 19i (reperti.categoria,
# reperti.us_provenienza_id). Condivisa (is_global) così è visibile a
# qualunque utente della demo, non solo all'autore.

POST {{base_url}}/{{app_name}}/api/assemblages
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "Reperti per US e categoria",
  "definition": {
    "source_tb": "reperti",
    "char_field": "categoria",
    "group_path": [],
    "group_field": "us_provenienza_id",
    "measure": "count",
    "measure_field": null,
    "filters": []
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
assemblage_id_1: jsonpath "$.analysis.id"

POST {{base_url}}/{{app_name}}/api/assemblage/{{assemblage_id_1}}/share
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19t. Ricerca salvata — esempio ────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/saved-queries
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "Reperti in ceramica",
  "tb": "reperti",
  "query": { "categoria": { "_eq": "Ceramica" } }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
saved_query_id_1: jsonpath "$.query.id"

POST {{base_url}}/{{app_name}}/api/saved-query/{{saved_query_id_1}}/share
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19u. Template di stampa — esempio (Unità stratigrafiche) ──────────
POST {{base_url}}/{{app_name}}/api/template/us/scheda_completa
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "sections": [
    {
      "label": "Identificazione",
      "content": [
        { "field": "sigla", "width": "1/4" },
        { "field": "tipo",  "width": "1/4" },
        { "field": "sito_id", "width": "1/4" },
        { "field": "complesso_id", "width": "1/4" }
      ]
    },
    {
      "label": "Descrizione e interpretazione",
      "content": [
        { "field": "descrizione", "width": "1/2" },
        { "field": "interpretazione", "width": "1/2" },
        { "field": "affidabilita", "width": "1/4" },
        { "field": "periodo", "width": "1/4" }
      ]
    },
    {
      "label": "Dati di scavo",
      "content": [
        { "field": "anno_scavo", "width": "1/4" },
        { "field": "nr_cassette", "width": "1/4" },
        { "field": "profondita_cm", "width": "1/4" },
        { "field": "verificata", "width": "1/4" },
        { "field": "note", "width": "1/1" }
      ]
    }
  ]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19v. Utente demo con privilegio ridotto ───────────────────────────
# "writer" (20) di base, ma con override "reader" (30) su sepolture —
# dimostra il caso d'uso principale di User Manager: privilegi diversi
# per tabelle diverse (dati sensibili).
POST {{base_url}}/{{app_name}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "Editor Demo",
  "email": "editor@example.com",
  "password": "Editor1234!",
  "privilege": 20
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
editor_user_id: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/user/{{editor_user_id}}/privileges
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "user_id": {{editor_user_id}}, "table_name": "sepolture", "privilege": 30 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19w. Messaggio di benvenuto homepage ──────────────────────────────
PUT {{base_url}}/{{app_name}}/api/welcome
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "content": "# Benvenuti in BraDypUS\n\nQuesta è un'applicazione demo con dati di scavo archeologico di esempio (siti, US, reperti, sepolture, datazioni al radiocarbonio, analisi di assemblaggio...). Esplorate liberamente — i dati sono rigenerati ad ogni seed." }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19x. Record eliminato — demo "Deleted records" / restore ──────────
# rep_id_9 non è referenziato da nessun'altra fase del seed (misure,
# geodata, userlinks), quindi eliminarlo qui non lascia riferimenti orfani.
DELETE {{base_url}}/{{app_name}}/api/record/reperti/{{rep_id_9}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19y. Zotero — libreria di gruppo pubblica e citazioni ─────────────
# Gruppo pubblico "Epirus" (https://www.zotero.org/groups/336647/epirus/library),
# nessuna api_key necessaria (lettura pubblica). I due zotero_key sono item
# reali della libreria: addLink() ne recupera subito la citazione via API.

POST {{base_url}}/{{app_name}}/api/zotero/lib
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "type": "group", "zotero_id": "336647", "name": "Epirus (Zotero group library)" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
zotero_lib_id: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/zotero/link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "siti", "record_id": {{sito_urbano}}, "lib_id": {{zotero_lib_id}}, "zotero_key": "6NKQX4Q8", "notes": "Inquadramento storico generale" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/zotero/link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "siti", "record_id": {{sito_rurale}}, "lib_id": {{zotero_lib_id}}, "zotero_key": "QPR8HQ62", "notes": "Confronto metodologico da scavi in area balcanica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19z. Arricchimento gerarchico dei 3 siti principali (convenzione demo) ───

# Regola: i dati più completi vivono nei primi 1-3 elementi di ogni tabella.
# Per ciascuno dei 3 siti "diretti" (Colle Oppio, Casale Selva Rotonda, Porto
# Nerone): >=3 complessi; il 1° complesso ha >=3 saggi; il 1° saggio ha 20 us;
# la 1° us ha 25 reperti. Questo blocco vive alla fine del file, dopo 19y,
# per due motivi: (1) l'import CSV di Monte Bibele e affini (sopra) usa ID
# numerici hardcoded (siti 4-18, complessi 9-11, us 31-45) che dipendono
# dall'ordine di inserimento — un record diretto inserito prima di quel
# blocco li sposterebbe e lo romperebbe; (2) l'esempio Zotero qui sotto
# riusa {{zotero_lib_id}}, catturato in 19y. Tutto ciò che segue usa solo
# variabili già catturate sopra (sito_urbano, compl_edif_a, sag_01,
# us_id_1, zotero_lib_id, ecc.), mai ID letterali.

# ── 19z-1. Porto Nerone: 3° complesso ─────────────────────────────────────

POST {{base_url}}/{{app_name}}/api/record/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Relitto minore", "tipo": "Struttura", "sito_id": {{sito_subacqueo}}, "cronologia": "Romano", "descrizione": "Nucleo disperso di anfore e resti lignei a nord del molo, riferibile a una piccola imbarcazione naufragata in prossimita' dell'approdo." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
compl_relitto: jsonpath "$.id"

# ── 19z-2. Nuovi saggi per raggiungere >=3 nel 1° complesso di ogni sito ───

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio VI", "anno": 2024, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "descrizione": "Estensione sud-ovest dell'edificio, verso il limite del saggio I." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
sag_vi: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio E", "anno": 2024, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "descrizione": "Settore ovest delle terme, con canalizzazioni di scarico." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
sag_e: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio Sub-3", "anno": 2024, "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "descrizione": "Prosecuzione del filare di pilae verso il largo, quota -6/-7 m." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
sag_sub3: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio Sub-4", "anno": 2024, "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "descrizione": "Settore sud del molo, area di accumulo di massi di crollo." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
sag_sub4: jsonpath "$.id"

# ── 19z-3. US aggiuntive: Saggio I / Colle Oppio (target 20) ──────────────

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US031", "tipo": "Strato", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Livello di preparazione del piano in cocciopesto, ghiaia e frammenti fittili pressati.", "interpretazione": "Sottofondo pavimentale", "affidabilita": "Certa", "periodo": "Tardo Antico", "anno_scavo": 2018, "profondita_cm": 110 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_31: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US032", "tipo": "Crollo", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Crollo localizzato di tegole e coppi con tracce di incendio.", "interpretazione": "Evento distruttivo puntuale", "affidabilita": "Probabile", "periodo": "Tardo Antico", "anno_scavo": 2018, "nr_cassette": 2, "profondita_cm": 115 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_32: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US033", "tipo": "Struttura", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Risega di fondazione in blocchetti di tufo legati con malta pozzolanica.", "interpretazione": "Fondazione muro perimetrale", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2018, "profondita_cm": 130, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_33: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US034", "tipo": "Taglio", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Taglio della trincea di fondazione, pareti a scarpa e fondo piano.", "interpretazione": "Trincea di fondazione", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2018, "profondita_cm": 132 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_34: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US035", "tipo": "Riempimento", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Riempimento della trincea con terreno di scarico e frammenti laterizi.", "interpretazione": "Reinterro post-costruzione", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2018, "nr_cassette": 1, "profondita_cm": 128 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_35: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US036", "tipo": "Strato", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Strato di vita con battuto in terra e piccole buche di palo periferiche.", "interpretazione": "Piano di frequentazione domestico", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2019, "profondita_cm": 140 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US037", "tipo": "Interfaccia", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Superficie livellata con inclusi ceramici abbondanti, leggermente inclinata verso ovest.", "interpretazione": "Piano di cantiere", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2019, "profondita_cm": 145 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US038", "tipo": "Strato", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Accumulo cineroso con carboni sparsi e frammenti di impasto grezzo.", "interpretazione": "Livello di frequentazione protostorica", "affidabilita": "Certa", "periodo": "Ferro", "anno_scavo": 2019, "nr_cassette": 3, "profondita_cm": 165 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US039", "tipo": "Taglio", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Buca circolare di piccole dimensioni con pareti concave.", "interpretazione": "Buca di scarico", "affidabilita": "Probabile", "periodo": "Ferro", "anno_scavo": 2019, "profondita_cm": 168 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US040", "tipo": "Riempimento", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Riempimento della buca con cenere, ossa animali combuste e ceramica d'impasto.", "interpretazione": "Scarico domestico", "affidabilita": "Certa", "periodo": "Ferro", "anno_scavo": 2019, "nr_cassette": 2, "profondita_cm": 170, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US041", "tipo": "Strato", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Strato limoso bruno con rari inclusi antropici, probabile suolo di vita diffuso.", "interpretazione": "Suolo di occupazione diffusa", "affidabilita": "Incerta", "periodo": "Ferro", "anno_scavo": 2019, "profondita_cm": 180 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US042", "tipo": "Strato", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Livello sabbioso-limoso con sporadici manufatti litici in selce.", "interpretazione": "Frequentazione sporadica preistorica", "affidabilita": "Incerta", "periodo": "Preistorico", "anno_scavo": 2020, "profondita_cm": 195 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US043", "tipo": "Interfaccia", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Superficie irregolare di contatto con il banco geologico, tracce di dilavamento.", "interpretazione": "Paleosuperficie", "affidabilita": "Probabile", "periodo": "Preistorico", "anno_scavo": 2020, "profondita_cm": 198 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US044", "tipo": "Strato", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "descrizione": "Banco di sabbie e argille alluvionali sterili, privo di reperti.", "interpretazione": "Substrato geologico", "affidabilita": "Certa", "periodo": "Preistorico", "anno_scavo": 2020, "profondita_cm": 205, "note": "Chiude la sequenza del saggio I; scavo sospeso al raggiungimento del banco naturale." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-4. US aggiuntive: Saggio A / Casale Selva Rotonda (target 20) ─────

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US045", "tipo": "Struttura", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Canale di smaltimento in laterizi con copertura a cappuccina.", "interpretazione": "Sistema di scarico delle terme", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2019, "profondita_cm": 75, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_45: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US046", "tipo": "Riempimento", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Riempimento del canale con concrezioni calcaree e frammenti ceramici.", "interpretazione": "Colmatura post-uso", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2019, "nr_cassette": 1, "profondita_cm": 78 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_46: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US047", "tipo": "Strato", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Battuto in opus signinum con inserti litici, superficie usurata al centro.", "interpretazione": "Pavimento del frigidarium", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2019, "profondita_cm": 68, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_47: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US048", "tipo": "Struttura", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Praefurnium con bocca di alimentazione rivestita in laterizi refrattari.", "interpretazione": "Focolare di riscaldamento ipocausto", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2019, "nr_cassette": 2, "profondita_cm": 82 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_48: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US049", "tipo": "Strato", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Accumulo di cenere e carboni davanti alla bocca del praefurnium.", "interpretazione": "Scarico di combustione", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2019, "profondita_cm": 84 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_49: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US050", "tipo": "Crollo", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Crollo delle suspensurae con pile di bipedales rovesciate.", "interpretazione": "Cedimento del piano di calpestio riscaldato", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2020, "nr_cassette": 3, "profondita_cm": 72 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US051", "tipo": "Struttura", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Condotto per fistula plumbea, ancora in situ per un tratto di 1,2 m.", "interpretazione": "Adduzione idrica alle terme", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2020, "profondita_cm": 60, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US052", "tipo": "Strato", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Intonaco dipinto crollato in frammenti, tracce di rosso pompeiano e giallo ocra.", "interpretazione": "Crollo della decorazione parietale", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2020, "nr_cassette": 4, "profondita_cm": 65 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US053", "tipo": "Strato", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Massetto di preparazione musiva in cocciopesto fine.", "interpretazione": "Sottofondo del mosaico del tepidarium", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2020, "profondita_cm": 70 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US054", "tipo": "Interfaccia", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Superficie di spoliazione con solchi di asportazione delle lastre marmoree.", "interpretazione": "Spoliazione del rivestimento marmoreo", "affidabilita": "Probabile", "periodo": "Alto Medioevo", "anno_scavo": 2020, "profondita_cm": 55 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US055", "tipo": "Taglio", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Fossa irregolare che intacca il mosaico, pareti a strapiombo.", "interpretazione": "Fossa di spoliazione", "affidabilita": "Certa", "periodo": "Alto Medioevo", "anno_scavo": 2020, "profondita_cm": 58 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US056", "tipo": "Riempimento", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Riempimento eterogeneo con frammenti musivi, malta e terreno bruno.", "interpretazione": "Colmatura della fossa di spoliazione", "affidabilita": "Certa", "periodo": "Alto Medioevo", "anno_scavo": 2021, "nr_cassette": 2, "profondita_cm": 56 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US057", "tipo": "Strato", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Livello di dilavamento con ciottoli fluitati e rari embrici.", "interpretazione": "Fase di abbandono e degrado post-terme", "affidabilita": "Probabile", "periodo": "Alto Medioevo", "anno_scavo": 2021, "profondita_cm": 50 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US058", "tipo": "Struttura", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Muretto a secco di reimpiego con blocchetti di spoglio.", "interpretazione": "Struttura altomedievale di reimpiego", "affidabilita": "Certa", "periodo": "Alto Medioevo", "anno_scavo": 2021, "nr_cassette": 1, "profondita_cm": 48, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US059", "tipo": "Riempimento", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Riempimento a ridosso del muretto con terreno organico e ossa animali.", "interpretazione": "Scarico domestico altomedievale", "affidabilita": "Certa", "periodo": "Alto Medioevo", "anno_scavo": 2021, "profondita_cm": 45 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US060", "tipo": "Strato", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Strato humico bruno scuro con radici e bioturbazione diffusa.", "interpretazione": "Livello di abbandono definitivo", "affidabilita": "Probabile", "periodo": "Alto Medioevo", "anno_scavo": 2021, "profondita_cm": 30 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US061", "tipo": "Strato", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Argilla compatta verde-grigia priva di inclusi antropici.", "interpretazione": "Sedimento alluvionale sterile", "affidabilita": "Certa", "periodo": "Preistorico", "anno_scavo": 2022, "profondita_cm": 20 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US062", "tipo": "Interfaccia", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Contatto diretto con il substrato geologico argilloso, superficie piana.", "interpretazione": "Substrato naturale, fine della sequenza", "affidabilita": "Certa", "periodo": "Preistorico", "anno_scavo": 2022, "profondita_cm": 15, "note": "Chiude la sequenza del saggio A." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-5. US aggiuntive: Saggio Sub-1 / Porto Nerone (target 20) ─────────

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US063", "tipo": "Struttura", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Secondo filare di pilae parallelo al primo, blocchi legati con malta pozzolanica idraulica.", "interpretazione": "Ampliamento della fondazione del molo", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2022, "profondita_cm": 310, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_63: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US064", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Sabbia grossolana con ciottoli e frammenti di intonaco idraulico (opus signinum).", "interpretazione": "Sedimento di cantiere subacqueo", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2022, "profondita_cm": 305 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_64: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US065", "tipo": "Struttura", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Palificata lignea infissa nel fondale, pali in quercia ben conservati per anossia.", "interpretazione": "Sostegno strutturale della banchina", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2022, "nr_cassette": 1, "profondita_cm": 320, "verificata": 1, "note": "Legno prelevato per campionatura C14 (v. datazione radiocarbonio collegata)." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_65_rc: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US066", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Concentrazione di zavorra litica eterogenea (basalto, calcare).", "interpretazione": "Zavorra di imbarcazione da carico", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2022, "profondita_cm": 330 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_66: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US067", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Sabbia fine con abbondanti valve di mollusco e concrezioni bioincrostanti.", "interpretazione": "Sedimentazione marina in ambiente calmo", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2023, "profondita_cm": 340 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_67: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US068", "tipo": "Crollo", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Crollo di blocchi squadrati dal filare superiore, disposti caoticamente.", "interpretazione": "Cedimento strutturale del molo", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2023, "nr_cassette": 2, "profondita_cm": 315 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US069", "tipo": "Struttura", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Anello di ormeggio in ferro murato in un blocco di ancoraggio.", "interpretazione": "Punto di ormeggio per imbarcazioni minori", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2023, "profondita_cm": 300, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US070", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Sabbia scura con abbondante materiale organico e frammenti di corda vegetale mineralizzata.", "interpretazione": "Accumulo organico da attività portuale", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2023, "profondita_cm": 325 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US071", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Concentrazione di ceppi d'ancora in piombo dispersi in un'area di 3x2 m.", "interpretazione": "Area di perdita accidentale di ancore", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2023, "nr_cassette": 3, "profondita_cm": 335, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US072", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Sabbia con laminazioni sottili alternate a livelli fangosi.", "interpretazione": "Sequenza deposizionale stagionale", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2023, "profondita_cm": 350 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US073", "tipo": "Struttura", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Basamento in cementizio idraulico per una possibile struttura di segnalazione.", "interpretazione": "Base di torre o fanale portuale", "affidabilita": "Incerta", "periodo": "Romano", "anno_scavo": 2023, "profondita_cm": 295 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US074", "tipo": "Riempimento", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Riempimento di una cavità tra i blocchi del filare con detrito misto.", "interpretazione": "Colmatura di interstizio strutturale", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2024, "profondita_cm": 318 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US075", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Fango grigio-verde compatto con rara fauna marina bentonica.", "interpretazione": "Sedimento di bacino portuale in fase di interramento", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2024, "profondita_cm": 360 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US076", "tipo": "Taglio", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Depressione naturale nel banco roccioso, margini erosi.", "interpretazione": "Conca naturale preesistente all'impianto portuale", "affidabilita": "Incerta", "periodo": "Romano", "anno_scavo": 2024, "profondita_cm": 370 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US077", "tipo": "Riempimento", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Riempimento della conca con sabbia e detrito antropico misto.", "interpretazione": "Colmatura della depressione naturale", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2024, "profondita_cm": 365 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US078", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Sabbia grossolana pulita, priva di inclusi antropici.", "interpretazione": "Sedimento marino pre-portuale", "affidabilita": "Certa", "periodo": "Preistorico", "anno_scavo": 2024, "profondita_cm": 385 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US079", "tipo": "Interfaccia", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Superficie del banco roccioso naturale, con tracce di bioerosione.", "interpretazione": "Substrato geologico sommerso", "affidabilita": "Certa", "periodo": "Preistorico", "anno_scavo": 2024, "profondita_cm": 400 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US080", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "descrizione": "Roccia calcarenitica compatta, base della sequenza subacquea.", "interpretazione": "Substrato geologico, fine della sequenza", "affidabilita": "Certa", "periodo": "Preistorico", "anno_scavo": 2024, "profondita_cm": 410, "note": "Chiude la sequenza del saggio Sub-1." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-6. US di cornice sui nuovi saggi (2 ciascuno, per non lasciarli vuoti) ───

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US081", "tipo": "Strato", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_vi}}, "descrizione": "Livello di macerie edilizie miste, laterizi frantumati e malta polverosa.", "interpretazione": "Accumulo di demolizione recente", "affidabilita": "Probabile", "periodo": "Basso Medioevo", "anno_scavo": 2024, "profondita_cm": 20 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US082", "tipo": "Strato", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_vi}}, "descrizione": "Strato argilloso compatto simile a US002, probabile prosecuzione verso sud.", "interpretazione": "Livello di abbandono, prosecuzione di US002", "affidabilita": "Probabile", "periodo": "Basso Medioevo", "anno_scavo": 2024, "profondita_cm": 32 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US083", "tipo": "Strato", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_e}}, "descrizione": "Crollo di tegole frammentarie analogo a US015, con minore densità.", "interpretazione": "Margine occidentale del crollo delle terme", "affidabilita": "Probabile", "periodo": "Tardo Antico", "anno_scavo": 2024, "profondita_cm": 33 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US084", "tipo": "Struttura", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_e}}, "descrizione": "Tratto di canaletta di scarico in laterizi, sezione a U.", "interpretazione": "Prosecuzione del sistema di drenaggio", "affidabilita": "Certa", "periodo": "Romano", "anno_scavo": 2024, "profondita_cm": 70, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US085", "tipo": "Struttura", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_sub3}}, "descrizione": "Prosecuzione del filare di pilae verso il largo, 3 blocchi conservati.", "interpretazione": "Estensione della fondazione del molo", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2024, "profondita_cm": 315 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US086", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_sub3}}, "descrizione": "Sabbia con frammenti anforari dispersi, bassa densità.", "interpretazione": "Sedimentazione marina periferica", "affidabilita": "Incerta", "periodo": "Romano", "anno_scavo": 2024, "profondita_cm": 340 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US087", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_sub4}}, "descrizione": "Accumulo di massi di crollo di grandi dimensioni, disposti caoticamente.", "interpretazione": "Area di collasso strutturale del molo", "affidabilita": "Probabile", "periodo": "Romano", "anno_scavo": 2024, "nr_cassette": 1, "profondita_cm": 320 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US088", "tipo": "Strato", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_sub4}}, "descrizione": "Sabbia grossolana con rari frammenti ceramici rotolati.", "interpretazione": "Sedimentazione periferica di bassa energia", "affidabilita": "Incerta", "periodo": "Romano", "anno_scavo": 2024, "profondita_cm": 345 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-7. Harris Matrix: prosecuzione della sequenza sotto le 3 us capostipite ───

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_1}}, "second": {{us_id_31}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_31}}, "second": {{us_id_32}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_32}}, "second": {{us_id_33}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_33}}, "second": {{us_id_34}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_34}}, "second": {{us_id_35}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_15}}, "second": {{us_id_45}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_45}}, "second": {{us_id_46}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_46}}, "second": {{us_id_47}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_47}}, "second": {{us_id_48}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_48}}, "second": {{us_id_49}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_26}}, "second": {{us_id_63}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_63}}, "second": {{us_id_64}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_64}}, "second": {{us_id_65_rc}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_65_rc}}, "second": {{us_id_66}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_66}}, "second": {{us_id_67}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-8. Reperti aggiuntivi: 25 da US001 (Colle Oppio) ──────────────────

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP021", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Ceramica", "classe": "Terra sigillata", "stato_cons": "Frammentario", "descrizione": "Frammento di parete di coppa in sigillata italica con bollo di fondo parzialmente leggibile.", "data_ritrovamento": "2018-06-18", "tipologia": "Coppa", "disegnato": 1, "pubblicato": 1, "frammenti_conservati": "Parete e attacco del fondo, ca. 1/4 del vaso", "decorazione": "Ingobbio rosso-arancio lucido, priva di decorazione plastica", "dati_epigrafici": "Bollo in planta pedis, cartiglio rettangolare, lettura incerta", "note": "Confrontabile con produzioni aretine di fine I sec. a.C." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
rep_id_21: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP022", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Ceramica", "classe": "Anfora", "stato_cons": "Frammentato", "descrizione": "Ansa di anfora Dressel 2-4 con impronta digitale sull'attacco superiore.", "data_ritrovamento": "2018-06-18", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP023", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Integro", "descrizione": "Moneta in bronzo di modulo piccolo, conservazione buona ma legenda illeggibile per usura.", "data_ritrovamento": "2018-06-19", "tipologia": "Moneta", "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP024", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Vetro", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Fondo di unguentario in vetro verdastro soffiato a stampo.", "data_ritrovamento": "2018-06-19" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP025", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Lacunoso", "descrizione": "Chiodo da carpenteria a sezione quadrata, punta piegata.", "data_ritrovamento": "2018-06-20" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP026", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Osso", "classe": "Osso lavorato", "stato_cons": "Integro", "descrizione": "Ago crinale in osso con capocchia sagomata a globetto.", "data_ritrovamento": "2018-06-20", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP027", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Litico", "classe": "Selce", "stato_cons": "Frammentato", "descrizione": "Scheggia in selce con ritocco marginale, residuale in strato disturbato.", "data_ritrovamento": "2018-06-21", "note": "Materiale residuale, non pertinente al periodo dello strato." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP028", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Ceramica", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Orlo di brocca monoansata con decorazione a pettine sull'ansa.", "data_ritrovamento": "2018-06-21" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP029", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Frammentato", "descrizione": "Frammento di fibula a balestra con arco decorato a tacche.", "data_ritrovamento": "2018-06-22", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP030", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Ceramica", "classe": "Ceramica depurata", "stato_cons": "Frammentario", "descrizione": "Parete di lucerna a canale con traccia di rivestimento ingobbiato.", "data_ritrovamento": "2018-06-22" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP031", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Laterizio", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Frammento di tegola con impronta di zampa di cane impressa prima della cottura.", "data_ritrovamento": "2018-06-23", "note": "Reperto curioso, spesso richiesto per attività didattiche." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP032", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Frammentato", "descrizione": "Coltello con lama frammentaria e resti del codolo.", "data_ritrovamento": "2018-06-23" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP033", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Ceramica", "classe": "Anfora", "stato_cons": "Lacunoso", "descrizione": "Puntale di anfora africana da olio, tipo Africana II.", "data_ritrovamento": "2018-06-24" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP034", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Vetro", "classe": "Ceramica comune", "stato_cons": "Integro", "descrizione": "Piccola perla vitrea blu cobalto, forse elemento di collana.", "data_ritrovamento": "2018-06-24", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP035", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Osso", "classe": "Osso lavorato", "stato_cons": "Frammentario", "descrizione": "Manico di coltello in osso con decorazione incisa a losanghe.", "data_ritrovamento": "2018-06-25" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP036", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Integro", "descrizione": "Anello digitale in bronzo con castone privo di gemma.", "data_ritrovamento": "2018-06-25" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP037", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Ceramica", "classe": "Terra sigillata", "stato_cons": "Frammentario", "descrizione": "Fondo di piatto in sigillata africana con bollo di fondo illeggibile.", "data_ritrovamento": "2018-06-26" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP038", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Litico", "classe": "Ossidiana", "stato_cons": "Frammentato", "descrizione": "Lama in ossidiana, verosimilmente residuale da contesti più antichi.", "data_ritrovamento": "2018-06-26", "note": "Ossidiana non locale; possibile importazione da Lipari o Pantelleria." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP039", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Lacunoso", "descrizione": "Elemento di serratura con resti di chiave a mandata semplice.", "data_ritrovamento": "2018-06-27" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP040", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Ceramica", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Coperchio troncoconico con presa a bottone.", "data_ritrovamento": "2018-06-27" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP041", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Osso", "classe": "Osso lavorato", "stato_cons": "Integro", "descrizione": "Dado da gioco in osso con numeri incisi a occhio di dado.", "data_ritrovamento": "2018-06-28", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP042", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Frammentato", "descrizione": "Frammento di specchio in bronzo con superficie riflettente ossidata.", "data_ritrovamento": "2018-06-28" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP043", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Ceramica", "classe": "Ceramica depurata", "stato_cons": "Frammentario", "descrizione": "Parete decorata a rotellatura con motivo a spina di pesce.", "data_ritrovamento": "2018-06-29" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP044", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Lacunoso", "descrizione": "Punta di freccia da caccia a losanga, verosimilmente intrusiva e moderna.", "data_ritrovamento": "2018-06-29", "note": "Oggetto verosimilmente moderno, coerente con la matrice disturbata di US001." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP045", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}}, "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_1}}, "categoria": "Ceramica", "classe": "Ceramica comune", "stato_cons": "Integro", "descrizione": "Piccola olletta miniaturistica intera, forse giocattolo infantile.", "data_ritrovamento": "2018-06-30", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-9. Reperti aggiuntivi: 23 da US015 (Casale Selva Rotonda, totale 25 con REP011-012) ───

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP046", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Ceramica", "classe": "Terra sigillata", "stato_cons": "Frammentario", "descrizione": "Parete di coppa in sigillata africana A con ingobbio rosso corallino.", "data_ritrovamento": "2019-07-10" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP047", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Vetro", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Frammento di lastra di vetro da finestra, colore verdastro, spessore 4 mm.", "data_ritrovamento": "2019-07-10" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP048", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Litico", "classe": "Selce", "stato_cons": "Integro", "descrizione": "Tessera musiva in marmo bianco, forma cubica regolare.", "data_ritrovamento": "2019-07-11", "tipologia": "Tessera musiva" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP049", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Frammentato", "descrizione": "Cardine di porta in bronzo con perno superstite.", "data_ritrovamento": "2019-07-11" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP050", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Laterizio", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Frammento di tubulo fittile da intercapedine di riscaldamento.", "data_ritrovamento": "2019-07-12" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP051", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Lacunoso", "descrizione": "Grappa da fissaggio di lastre di rivestimento parietale.", "data_ritrovamento": "2019-07-12" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP052", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Ceramica", "classe": "Ceramica depurata", "stato_cons": "Frammentario", "descrizione": "Orlo di coppa carenata con vernice nera residua.", "data_ritrovamento": "2019-07-13" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP053", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Vetro", "classe": "Ceramica comune", "stato_cons": "Integro", "descrizione": "Balsamario fusiforme in vetro azzurrino, integro.", "data_ritrovamento": "2019-07-13", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP054", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Integro", "descrizione": "Ago crinale in bronzo con capocchia sferica.", "data_ritrovamento": "2019-07-14" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP055", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Litico", "classe": "Selce", "stato_cons": "Frammentato", "descrizione": "Frammento di lastra di rivestimento in marmo cipollino.", "data_ritrovamento": "2019-07-14" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP056", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Ceramica", "classe": "Anfora", "stato_cons": "Lacunoso", "descrizione": "Ansa di anfora vinaria con bollo circolare poco leggibile.", "data_ritrovamento": "2019-07-15" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP057", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Osso", "classe": "Osso lavorato", "stato_cons": "Frammentario", "descrizione": "Spillone in osso con testa sagomata a oliva.", "data_ritrovamento": "2019-07-15" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP058", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Frammentato", "descrizione": "Chiave a doppia mandata con anello di sospensione.", "data_ritrovamento": "2019-07-16", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP059", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Ceramica", "classe": "Terra sigillata", "stato_cons": "Frammentario", "descrizione": "Fondo di piatto in sigillata italica con bollo planta pedis illeggibile.", "data_ritrovamento": "2019-07-16" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP060", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Laterizio", "classe": "Ceramica comune", "stato_cons": "Integro", "descrizione": "Bollo laterizio circolare su frammento di bipedalis, officina non identificata.", "data_ritrovamento": "2019-07-17", "tipologia": "Bollo laterizio", "dati_epigrafici": "Cartiglio circolare, lettura parziale ...OFF..." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP061", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Vetro", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Frammento di coppa in vetro soffiato con costolature.", "data_ritrovamento": "2019-07-17" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP062", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Lacunoso", "descrizione": "Elemento decorativo a foglia di bronzo, forse applique.", "data_ritrovamento": "2019-07-18" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP063", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Ceramica", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Parete di dolio con decorazione a cordone plastico.", "data_ritrovamento": "2019-07-18" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP064", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Osso", "classe": "Osso lavorato", "stato_cons": "Integro", "descrizione": "Ago da cucito in osso, punta integra.", "data_ritrovamento": "2019-07-19" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP065", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Frammentato", "descrizione": "Anello di sospensione per lucerna a sospensione.", "data_ritrovamento": "2019-07-19" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP066", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Ceramica", "classe": "Ceramica depurata", "stato_cons": "Integro", "descrizione": "Piccola coppa miniaturistica votiva.", "data_ritrovamento": "2019-07-20", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP067", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Litico", "classe": "Selce", "stato_cons": "Integro", "descrizione": "Peso da telaio in pietra calcarea forato.", "data_ritrovamento": "2019-07-20" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP068", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Frammentato", "descrizione": "Frammento di specchio circolare in bronzo con manico.", "data_ritrovamento": "2019-07-21" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-10. Reperti aggiuntivi: 25 da US026 (Porto Nerone) ────────────────

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP069", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Anfora", "stato_cons": "Integro", "descrizione": "Anfora Dressel 6A integra, ancora chiusa da opercolo fittile.", "data_ritrovamento": "2022-08-10", "tipologia": "Anfora", "disegnato": 1, "pubblicato": 1, "frammenti_conservati": "Integra, opercolo conservato in situ", "note": "Contenuto interno campionato per analisi archeometriche." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP070", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Anfora", "stato_cons": "Frammentato", "descrizione": "Collo di anfora Dressel 20 con bollo su ansa.", "data_ritrovamento": "2022-08-10", "dati_epigrafici": "Bollo ovale, sigla QIVL parzialmente leggibile" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP071", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Lacunoso", "descrizione": "Ceppo d'ancora in ferro con concrezioni calcaree spesse.", "data_ritrovamento": "2022-08-11" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP072", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Integro", "descrizione": "Moneta in bronzo (sesterzio?) con patina marina verde.", "data_ritrovamento": "2022-08-11", "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP073", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Olla da cambusa con annerimento da fuoco sul fondo.", "data_ritrovamento": "2022-08-12" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP074", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Frammentato", "descrizione": "Chiodo navale a testa larga, tipico del fasciame.", "data_ritrovamento": "2022-08-12" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP075", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Terra sigillata", "stato_cons": "Frammentario", "descrizione": "Coppa in sigillata africana C, con usura di bordo.", "data_ritrovamento": "2022-08-13" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP076", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Lacunoso", "descrizione": "Grappa di collegamento tra madieri, fortemente concrezionata.", "data_ritrovamento": "2022-08-13" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP077", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Osso", "classe": "Osso lavorato", "stato_cons": "Frammentario", "descrizione": "Manico di utensile in osso, forse posata da bordo.", "data_ritrovamento": "2022-08-14" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP078", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Anfora", "stato_cons": "Frammentato", "descrizione": "Puntale di anfora orientale, forse tipo Kapitan II.", "data_ritrovamento": "2022-08-14" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP079", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Frammentato", "descrizione": "Gancio o rebbio in bronzo, funzione non determinata.", "data_ritrovamento": "2022-08-15" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP080", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Ceramica comune", "stato_cons": "Integro", "descrizione": "Boccale monoansato intero, forse dotazione dell'equipaggio.", "data_ritrovamento": "2022-08-15", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP081", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Lacunoso", "descrizione": "Sonda da scandaglio in piombo, forma troncoconica con base concava per il grasso di segnalazione.", "data_ritrovamento": "2022-08-16", "tipologia": "Scandaglio da sonda" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP082", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Anfora", "stato_cons": "Frammentato", "descrizione": "Ansa di anfora con impronta digitale sull'attacco.", "data_ritrovamento": "2022-08-16" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP083", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Lacunoso", "descrizione": "Frammento di catena, verosimilmente di ormeggio.", "data_ritrovamento": "2022-08-17" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP084", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Ceramica depurata", "stato_cons": "Frammentario", "descrizione": "Piatto da mensa con orlo estroflesso, uso di bordo.", "data_ritrovamento": "2022-08-17" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP085", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Osso", "classe": "Osso lavorato", "stato_cons": "Frammentario", "descrizione": "Frammento di pettine in osso a doppia dentatura.", "data_ritrovamento": "2022-08-18" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP086", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Frammentato", "descrizione": "Fibbia di cinturone militare in bronzo.", "data_ritrovamento": "2022-08-18", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP087", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Anfora", "stato_cons": "Integro", "descrizione": "Anfora Dressel 2-4 integra con residuo di pece impermeabilizzante interna.", "data_ritrovamento": "2022-08-19" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP088", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Ferro", "stato_cons": "Lacunoso", "descrizione": "Frammento di ancora ammiragliata, ceppo mancante.", "data_ritrovamento": "2022-08-19" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP089", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Ceramica comune", "stato_cons": "Frammentario", "descrizione": "Coperchio biconico con presa a disco.", "data_ritrovamento": "2022-08-20" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP090", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Metallo", "classe": "Bronzo", "stato_cons": "Integro", "descrizione": "Peso da stadera in bronzo a forma di ghianda.", "data_ritrovamento": "2022-08-20" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP091", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Terra sigillata", "stato_cons": "Frammentario", "descrizione": "Fondo di coppa in sigillata orientale con bollo circolare.", "data_ritrovamento": "2022-08-21" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP092", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Osso", "classe": "Osso lavorato", "stato_cons": "Integro", "descrizione": "Ago da rammendo in osso, punta integra.", "data_ritrovamento": "2022-08-21" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP093", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}}, "saggio_id": {{sag_11}}, "us_provenienza_id": {{us_id_26}}, "categoria": "Ceramica", "classe": "Anfora", "stato_cons": "Frammentato", "descrizione": "Collo di anfora con traccia di titulus pictus illeggibile.", "data_ritrovamento": "2022-08-22", "note": "Chiude il lotto di reperti dalla US026; materiale in corso di studio archeometrico." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-11. Campo Markdown (unico tipo mai usato finora) su siti ──────────

POST {{base_url}}/{{app_name}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "presentazione", "label": "Presentazione (markdown)", "type": "md", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{sito_urbano}}, "core": { "presentazione": "## Colle Oppio\n\nSito **urbano** con stratificazione ininterrotta dal VI sec. a.C. all'età moderna.\n\n- Edificio pubblico in opus sectile\n- Riuso altomedievale con fosse di scarico\n- Area esterna lastricata\n\nCfr. [scheda di inquadramento storico](https://www.zotero.org/groups/336647/epirus/library)." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{sito_rurale}}, "core": { "presentazione": "## Casale Selva Rotonda\n\nVilla *rustica* tardorepubblicana con impianto vinicolo e settore termale.\n\n- **Pars urbana**: terme con ipocausto e mosaici\n- **Pars rustica**: torcularium e dolia\n- Necropoli di fondazione della prima età del Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{sito_subacqueo}}, "core": { "presentazione": "## Porto Nerone\n\nFondali con resti di strutture **portuali** di età imperiale, quota -3/-5 m.\n\n- Molo in opus pilarum con palificata lignea datata al radiocarbonio\n- Deposito anforario da un possibile relitto\n- Relitto minore con carico disperso" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-12. Radiocarbonio: parità su Porto Nerone (già presente su siti 1 e 2) ───

POST {{base_url}}/{{app_name}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{us_id_65_rc}}, "core": {}, "plugins": { "us_radiocarbon": [ { "id": null, "_isNew": true, "fields": { "lab_code": "Beta-412004", "bp": 1920, "bp_error": 30, "material": "waterlogged oak wood" } } ] } }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-13. Zotero: parità su Porto Nerone + primo esempio su us e reperti ───

POST {{base_url}}/{{app_name}}/api/zotero/link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "siti", "record_id": {{sito_subacqueo}}, "lib_id": {{zotero_lib_id}}, "zotero_key": "QPR8HQ62", "notes": "Confronto per le strutture portuali sommerse" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/zotero/link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "record_id": {{us_id_1}}, "lib_id": {{zotero_lib_id}}, "zotero_key": "6NKQX4Q8", "notes": "Confronto stratigrafico per la sequenza tardoantica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/zotero/link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "reperti", "record_id": {{rep_id_21}}, "lib_id": {{zotero_lib_id}}, "zotero_key": "6NKQX4Q8", "notes": "Confronto tipologico per il bollo in planta pedis" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-14. Foto: parità su Porto Nerone (già presente su Colle Oppio) ────

POST {{base_url}}/{{app_name}}/api/record/siti/{{sito_subacqueo}}/file
Authorization: Bearer {{jwt}}
[MultipartFormData]
file: file,fixtures/test_image.png;image/png
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
img_sito_3: jsonpath "$.file.id"

PATCH {{base_url}}/{{app_name}}/api/file/{{img_sito_3}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "description": "Immersione di ricognizione sul relitto minore", "keywords": "sito, subacqueo, immersione" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-15. Geodata: colma il vuoto sulla us capostipite di Casale Selva Rotonda ───

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_15}}, "geometry": {"type":"Point","coordinates":[12.7325,41.6892]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19z-16. combo_select: un valore libero fuori vocabolario forma_tipo ───
# NOTA: come i m_reperti_in_us/misure/m_reperti_in_complessi di 19j-19l, questo
# insert non asserisce $.status perche' fallisce silenziosamente (HTTP 200 ma
# {"status":"error"}) su tutte le tabelle plugin generiche (is_plugin=1): manca
# la colonna "creator" nello schema creato da Manage per queste tabelle, quindi
# Record\Persist non riesce a fare l'INSERT. Bug preesistente, non introdotto
# da questa modifica; segnalato in project_issue_tracker (#61), non risolto qui
# perche' e' una modifica di struttura/codice, fuori scope per questa sessione
# di soli dati demo. Il payload resta pronto per quando verra' corretto.

POST {{base_url}}/{{app_name}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{us_id_65_rc}}, "materiale_classe": "Metallo non ferroso", "forma_tipo": "Ceppo d'ancora in piombo", "forma_completa": 0, "datazione_generica": "Romano", "nr_totale_orli": 0, "nr_totale_fondi": 0, "nr_totale_pareti": 0, "nr_totale_anse": 0, "mni": 1, "peso_totale": "38000 g", "note": "forma_tipo valorizzato con testo libero non presente nel vocabolario, a dimostrazione del combo_select." }, "plugins": {} }
HTTP 200

# ── 19z-17. Misure sui reperti più ricchi delle nuove 3 us capostipite ────
# Stesso bug preesistente di 19z-16 (tabella plugin senza colonna creator).

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_21}}, "tipo_misura": "Diametro", "valore": "9.4", "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/{{app_name}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "id_link": {{rep_id_21}}, "tipo_misura": "Altezza", "valore": "4.1", "unita": "cm" }, "plugins": {} }
HTTP 200

# ── 19z-18. Reperti già esistenti: valorizza i campi finora mai usati sui primi 3 ───

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_1}}, "core": { "tipologia": "Coppa", "frammenti_conservati": "Orlo e parte della vasca, ca. 1/3 del vaso", "decorazione": "Bollo in planta pedis leggibile ATEI, ingobbio rosso brillante", "dati_epigrafici": "ATEI, retrograde, cartiglio rettangolare", "note": "Uno dei pochi bolli completamente leggibili del sito." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_2}}, "core": { "tipologia": "Anfora", "frammenti_conservati": "Orlo e parte del collo", "dati_epigrafici": "Titulus pictus in caratteri capitali, lettura parziale" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{rep_id_3}}, "core": { "tipologia": "Fibula", "decorazione": "Arco a sezione romboidale con incisioni a spina di pesce", "note": "Confrontabile con esemplari da contesti augustei." }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19z-19. Ordina le liste per id, non per nome/sigla ──────────────
# Di default order="nome"/"sigla"/"inventario" (alfabetico) fa sì che i
# record CSV con sigla "EXT..."/nomi come "Baia" precedano in lista i
# record diretti più ricchi (US001, REP001, Colle Oppio...), vanificando
# la convenzione "i primi record sono i più completi". Stessi payload di
# PUT config/table/{tb} già usati in 19a, con solo "order" cambiato a
# "id" — nessun'altra impostazione (geodata/zotero/fuzzy_date/rs/preview)
# viene toccata. Deciso con l'utente il 2026-09-13 dopo verifica dal vivo.

PUT {{base_url}}/{{app_name}}/api/config/table/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "siti", "label": "Siti Archeologici", "is_plugin": "0",
  "order": "id", "id_field": "nome", "preview": ["nome", "localita"],
  "geodata": 1, "zotero": 1, "fuzzy_date": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "complessi", "label": "Complessi", "is_plugin": "0",
  "order": "id", "id_field": "nome", "preview": ["nome", "tipo"],
  "geodata": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "saggi", "label": "Saggi di scavo", "is_plugin": "0",
  "order": "id", "id_field": "nome", "preview": ["nome", "anno"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "us", "label": "Unità stratigrafiche", "is_plugin": "0",
  "order": "id", "id_field": "sigla", "preview": ["sigla"],
  "rs": "sigla", "geodata": 1, "zotero": 1, "fuzzy_date": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/{{app_name}}/api/config/table/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "reperti", "label": "Reperti mobili", "is_plugin": "0",
  "order": "id", "id_field": "inventario", "preview": ["inventario"],
  "geodata": 1, "zotero": 1, "fuzzy_date": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
