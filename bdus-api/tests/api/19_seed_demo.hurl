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
#   complessi (8 direct + 3 via CSV = 11 total)
#   saggi (12 direct + 5 via CSV = 17 total)
#   us (30 direct + 15 via CSV = 45 total)
#   reperti (20 direct + 375 via CSV = 395 total)
#   misure (~60), m_reperti_in_us (~40), m_reperti_in_complessi (~15)
#   RS relations (~41), geodata, userlinks (21 labeled), chart
# ════════════════════════════════════════════════════════════════════


# ── 19a. Table-level plugin configuration ───────────────────────────
# Must run before data insertion so RS, geodata and fuzzy_date columns
# (added via add_new_fld) are in place before records are created.

PUT {{base_url}}/api/config/table/siti
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
POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_from", "label": "Dal (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_to", "label": "Al (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_label", "label": "Etichetta cronologica", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_certainty", "label": "Certezza", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_period", "label": "Periodo", "type": "select", "db_type": "TEXT", "vocabulary_set": "periodi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/api/config/table/complessi
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

PUT {{base_url}}/api/config/table/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "saggi", "label": "Saggi di scavo", "is_plugin": "0",
  "order": "nome", "id_field": "nome", "preview": ["nome", "anno"]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/api/config/table/us
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
POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_from", "label": "Dal (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_to", "label": "Al (anno)", "type": "text", "db_type": "INTEGER", "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_label", "label": "Etichetta cronologica", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_certainty", "label": "Certezza", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "chrono_period", "label": "Periodo", "type": "select", "db_type": "TEXT", "vocabulary_set": "periodi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/api/config/table/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "reperti", "label": "Reperti mobili", "is_plugin": "0",
  "order": "inventario", "id_field": "inventario", "preview": ["inventario"],
  "geodata": 1, "zotero": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19b. Vocabularies ────────────────────────────────────────────────

# periodi
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Preistorico" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Bronzo" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Ferro" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Greco-Ellenistico" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Romano" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Tardo Antico" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Alto Medioevo" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "periodi", "def": "Basso Medioevo" }
HTTP 200

# sito_tipologia
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Abitato" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Necropoli" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Santuario" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Villa" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Insediamento rurale" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "sito_tipologia", "def": "Sito subacqueo" }
HTTP 200

# complesso_tipo
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Edificio" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Vano" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Area esterna" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Struttura" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "complesso_tipo", "def": "Ambiente" }
HTTP 200

# us_tipo
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Strato" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Taglio" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Struttura" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Riempimento" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Interfaccia" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "us_tipo", "def": "Crollo" }
HTTP 200

# affidabilita
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "affidabilita", "def": "Certa" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "affidabilita", "def": "Probabile" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "affidabilita", "def": "Incerta" }
HTTP 200

# reperto_categoria
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Ceramica" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Metallo" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Litico" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Organico" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Vetro" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Laterizio" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_categoria", "def": "Osso" }
HTTP 200

# reperto_classe
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Terra sigillata" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Ceramica comune" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Ceramica depurata" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Anfora" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Bronzo" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Ferro" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Selce" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Ossidiana" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "reperto_classe", "def": "Osso lavorato" }
HTTP 200

# stato_conservazione
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "stato_conservazione", "def": "Integro" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "stato_conservazione", "def": "Frammentario" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "stato_conservazione", "def": "Lacunoso" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "stato_conservazione", "def": "Frammentato" }
HTTP 200

# tipo_misura
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Lunghezza" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Larghezza" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Altezza" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Spessore" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "tipo_misura", "def": "Diametro" }
HTTP 200

# unita_misura
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "unita_misura", "def": "mm" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "unita_misura", "def": "cm" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "unita_misura", "def": "m" }
HTTP 200

# materiale_classe
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Ceramica da mensa" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Ceramica da cucina" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Ceramica da trasporto" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Ceramica fine" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Metallo ferroso" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Metallo non ferroso" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Vetro" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Laterizio" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Litico" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "materiale_classe", "def": "Osso lavorato" }
HTTP 200

# forma_tipo
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Coppa" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Piatto" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Brocca" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Anfora" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Olla" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Tegola" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Mattone" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Fibula" }
HTTP 200

POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "voc": "forma_tipo", "def": "Chiodo" }
HTTP 200


# ── 19c. Siti ────────────────────────────────────────────────────────

POST {{base_url}}/api/record/siti
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

POST {{base_url}}/api/record/siti
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

POST {{base_url}}/api/record/siti
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

POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{sito_urbano}}, "core": { "chrono_from": -600, "chrono_to": 1800, "chrono_label": "-600/1800", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{sito_rurale}}, "core": { "chrono_from": -100, "chrono_to": 400, "chrono_label": "-100/400", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": {{sito_subacqueo}}, "core": { "chrono_from": 1, "chrono_to": 300, "chrono_label": "1/300", "chrono_certainty": 3, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19d. Complessi ───────────────────────────────────────────────────

# Colle Oppio: 3 complessi
POST {{base_url}}/api/record/complessi
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

POST {{base_url}}/api/record/complessi
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

POST {{base_url}}/api/record/complessi
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
POST {{base_url}}/api/record/complessi
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

POST {{base_url}}/api/record/complessi
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

POST {{base_url}}/api/record/complessi
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
POST {{base_url}}/api/record/complessi
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

POST {{base_url}}/api/record/complessi
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

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio I",   "anno": 2018, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},    "descrizione": "Trincea est-ovest 5x2 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_01: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio II",  "anno": 2019, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},    "descrizione": "Ampliamento nord 3x3 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_02: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio III", "anno": 2020, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}},   "descrizione": "Saggio esplorativo 2x4 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_03: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio IV",  "anno": 2021, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}},   "descrizione": "Estensione saggio III." }, "plugins": {} }
HTTP 200
[Captures]
sag_04: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio V",   "anno": 2022, "sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},    "descrizione": "Pulizia lastricato." }, "plugins": {} }
HTTP 200
[Captures]
sag_05: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio A",   "anno": 2019, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}},  "descrizione": "Trincea terme 4x2 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_06: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio B",   "anno": 2020, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}},  "descrizione": "Ampliamento nord." }, "plugins": {} }
HTTP 200
[Captures]
sag_07: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio C",   "anno": 2021, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "descrizione": "Torcularium 3x3 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_08: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio D",   "anno": 2022, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},      "descrizione": "Sepolture 1-10." }, "plugins": {} }
HTTP 200
[Captures]
sag_09: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio D2",  "anno": 2023, "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},      "descrizione": "Sepolture 11-20." }, "plugins": {} }
HTTP 200
[Captures]
sag_10: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio Sub-1", "anno": 2022, "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}},     "descrizione": "Quadrato 4x4 m -3/-5 m." }, "plugins": {} }
HTTP 200
[Captures]
sag_11: jsonpath "$.id"

POST {{base_url}}/api/record/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "nome": "Saggio Sub-2", "anno": 2023, "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_dep_anf}}, "descrizione": "Rilievo concentrazione anforica." }, "plugins": {} }
HTTP 200
[Captures]
sag_12: jsonpath "$.id"


# ── 19f. US records ──────────────────────────────────────────────────

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US001", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Strato di humus superficiale con materiale moderno.",     "interpretazione": "Accumulo recente",          "affidabilita": "Certa",    "periodo": "Basso Medioevo", "anno_scavo": 2018, "nr_cassette": 2, "profondita_cm": 15, "verificata": 1 }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_1: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US002", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Strato argilloso grigio-marrone compatto.",              "interpretazione": "Livello di abbandono",      "affidabilita": "Certa",    "periodo": "Basso Medioevo", "anno_scavo": 2018, "nr_cassette": 3, "profondita_cm": 30, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_2: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US003", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Preparazione del piano in cocciopesto.",                 "interpretazione": "Piano di calpestio",        "affidabilita": "Certa",    "periodo": "Tardo Antico",   "anno_scavo": 2018, "nr_cassette": 1, "profondita_cm": 45, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_3: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US004", "tipo": "Taglio",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Taglio irregolare con pareti verticali.",               "interpretazione": "Fossa di deposizione",      "affidabilita": "Probabile","periodo": "Basso Medioevo", "anno_scavo": 2018, "profondita_cm": 60 }, "plugins": {} }
HTTP 200
[Captures]
us_id_4: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US005", "tipo": "Riempimento","sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "descrizione": "Riempimento con terreno sciolto e inclusi carboniosi.", "interpretazione": "Riempimento secondario",    "affidabilita": "Certa",    "periodo": "Basso Medioevo", "anno_scavo": 2018, "nr_cassette": 2, "profondita_cm": 55 }, "plugins": {} }
HTTP 200
[Captures]
us_id_5: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US006", "tipo": "Struttura",  "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "descrizione": "Muro in laterizi con malta di calce.",                  "interpretazione": "Muro di delimitazione",     "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2019, "profondita_cm": 80, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_6: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US007", "tipo": "Crollo",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "descrizione": "Crollo con mattoni frantumati e tegole.",               "interpretazione": "Evento sismico",            "affidabilita": "Probabile","periodo": "Tardo Antico",   "anno_scavo": 2019, "nr_cassette": 4, "profondita_cm": 90 }, "plugins": {} }
HTTP 200
[Captures]
us_id_7: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US008", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "descrizione": "Pavimento in opus sectile lacunoso.",                   "interpretazione": "Piano di calpestio romano", "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2019, "nr_cassette": 1, "profondita_cm": 100, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_8: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US009", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "descrizione": "Strato argilloso sterile grigio-verde.",               "interpretazione": "Alluvione naturale",        "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2020, "profondita_cm": 140 }, "plugins": {} }
HTTP 200
[Captures]
us_id_9: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US010", "tipo": "Taglio",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "descrizione": "Buca di palo circolare diam. 0,20 m.",                 "interpretazione": "Sostegno struttura lignea", "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2020, "profondita_cm": 135 }, "plugins": {} }
HTTP 200
[Captures]
us_id_10: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US011", "tipo": "Riempimento","sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "descrizione": "Riempimento buca di palo con terreno carbonioso.",     "interpretazione": "Disfacimento palo",        "affidabilita": "Probabile","periodo": "Ferro",          "anno_scavo": 2020 }, "plugins": {} }
HTTP 200
[Captures]
us_id_11: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US012", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_04}}, "descrizione": "Piano di frequentazione con impronte di pali.",        "interpretazione": "Pavimento capanna",        "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2021, "nr_cassette": 3, "profondita_cm": 120, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_12: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US013", "tipo": "Interfaccia","sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},  "saggio_id": {{sag_05}}, "descrizione": "Superficie di frequentazione con lastricato.",         "interpretazione": "Piano stradale",           "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2022, "profondita_cm": 50, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_13: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US014", "tipo": "Strato",     "sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},  "saggio_id": {{sag_05}}, "descrizione": "Sottofondo in ghiaia e frammenti ceramici.",           "interpretazione": "Preparazione lastricato",  "affidabilita": "Probabile","periodo": "Romano",         "anno_scavo": 2022, "nr_cassette": 2, "profondita_cm": 65 }, "plugins": {} }
HTTP 200
[Captures]
us_id_14: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US015", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Livello di crollo con tegole.",                       "interpretazione": "Abbandono terme",          "affidabilita": "Certa",    "periodo": "Tardo Antico",   "anno_scavo": 2019, "nr_cassette": 5, "profondita_cm": 35 }, "plugins": {} }
HTTP 200
[Captures]
us_id_15: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US016", "tipo": "Struttura",  "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "descrizione": "Suspensurae in bipedales.",                           "interpretazione": "Pavimento riscaldato",     "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2019, "profondita_cm": 70, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_16: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US017", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_07}}, "descrizione": "Strato limoso di colmata.",                           "interpretazione": "Alluvione post-abbandono", "affidabilita": "Probabile","periodo": "Alto Medioevo",  "anno_scavo": 2020, "profondita_cm": 20 }, "plugins": {} }
HTTP 200
[Captures]
us_id_17: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US018", "tipo": "Struttura",  "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "saggio_id": {{sag_08}}, "descrizione": "Contrappeso del torchio in calcare.",                "interpretazione": "Impianto vinicolo",        "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2021, "profondita_cm": 55, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_18: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US019", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "saggio_id": {{sag_08}}, "descrizione": "Strato di lavorazione con vinaccioli carbonizzati.", "interpretazione": "Pavimento cantina",       "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2021, "nr_cassette": 2, "profondita_cm": 60 }, "plugins": {} }
HTTP 200
[Captures]
us_id_19: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US020", "tipo": "Taglio",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "descrizione": "Fossa sepolcrale T.1 a pianta rettangolare.",        "interpretazione": "Deposizione primaria",    "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2022, "profondita_cm": 85 }, "plugins": {} }
HTTP 200
[Captures]
us_id_20: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US021", "tipo": "Riempimento","sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "descrizione": "Riempimento fossa T.1 con scheletro e corredo.",     "interpretazione": "Deposizione inumato",     "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2022, "nr_cassette": 6, "profondita_cm": 80, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_21: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US022", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "descrizione": "Copertura della fossa con lastrine di tufo.",        "interpretazione": "Chiusura sepoltura",      "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2022, "profondita_cm": 75 }, "plugins": {} }
HTTP 200
[Captures]
us_id_22: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US023", "tipo": "Taglio",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "descrizione": "Fossa sepolcrale T.2 a pianta ovale.",              "interpretazione": "Deposizione primaria",    "affidabilita": "Probabile","periodo": "Ferro",          "anno_scavo": 2023, "profondita_cm": 90 }, "plugins": {} }
HTTP 200
[Captures]
us_id_23: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US024", "tipo": "Riempimento","sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "descrizione": "Riempimento T.2 con ossa e ceramica miniaturistica.","interpretazione": "Corredo funerario",        "affidabilita": "Certa",    "periodo": "Ferro",          "anno_scavo": 2023, "nr_cassette": 4, "profondita_cm": 85, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_24: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US025", "tipo": "Strato",     "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "descrizione": "Strato di terreno sterile preistoricogiallo.",      "interpretazione": "Substrato geologico",     "affidabilita": "Certa",    "periodo": "Preistorico",    "anno_scavo": 2023, "profondita_cm": 200 }, "plugins": {} }
HTTP 200
[Captures]
us_id_25: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US026", "tipo": "Struttura",  "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}},     "saggio_id": {{sag_11}}, "descrizione": "Filare di pilae in opus incertum sommerso.",    "interpretazione": "Fondazione molo",         "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2022, "profondita_cm": 300, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_26: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US027", "tipo": "Strato",     "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_molo}},     "saggio_id": {{sag_11}}, "descrizione": "Sabbia di posidonia con frammenti ceramici.",   "interpretazione": "Sedimento marino",        "affidabilita": "Certa",    "periodo": "Romano",         "anno_scavo": 2022, "profondita_cm": 400 }, "plugins": {} }
HTTP 200
[Captures]
us_id_27: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US028", "tipo": "Strato",     "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_dep_anf}},  "saggio_id": {{sag_12}}, "descrizione": "Concentrazione anfore Dressel 2-4 in giacitura primaria.", "interpretazione": "Carico nave naufragata", "affidabilita": "Probabile","periodo": "Romano",         "anno_scavo": 2023, "nr_cassette": 8, "profondita_cm": 500, "verificata": 1 }, "plugins": {} }
HTTP 200
[Captures]
us_id_28: jsonpath "$.id"

POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "sigla": "US029", "tipo": "Strato",     "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_dep_anf}},  "saggio_id": {{sag_12}}, "descrizione": "Strato di sabbia grossolana sotto le anfore.",   "interpretazione": "Fondale preesistente",    "affidabilita": "Incerta",  "periodo": "Romano",         "anno_scavo": 2023, "profondita_cm": 490 }, "plugins": {} }
HTTP 200
[Captures]
us_id_29: jsonpath "$.id"

POST {{base_url}}/api/record/us
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

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_30}}, "second": {{us_id_1}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_1}},  "second": {{us_id_2}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_2}},  "second": {{us_id_3}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_2}},  "second": {{us_id_4}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_4}},  "second": {{us_id_5}},  "relation": 3 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_3}},  "second": {{us_id_6}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_6}},  "second": {{us_id_7}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_7}},  "second": {{us_id_8}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_8}},  "second": {{us_id_9}},  "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_9}},  "second": {{us_id_12}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_9}},  "second": {{us_id_10}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_10}}, "second": {{us_id_11}}, "relation": 3 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_9}},  "second": {{us_id_25}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_22}}, "second": {{us_id_21}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_21}}, "second": {{us_id_20}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_22}}, "second": {{us_id_23}}, "relation": 5 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_23}}, "second": {{us_id_24}}, "relation": 3 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_20}}, "second": {{us_id_25}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_27}}, "second": {{us_id_26}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_29}}, "second": {{us_id_28}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_13}}, "second": {{us_id_14}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_15}}, "second": {{us_id_16}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_17}}, "second": {{us_id_15}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_18}}, "second": {{us_id_19}}, "relation": 9 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/record/us/rs
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "first": {{us_id_16}}, "second": {{us_id_18}}, "relation": 1 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── 19h. Geodata US — POST /api/geoface/feature con GeoJSON ──────────
POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_1}}, "geometry": {"type":"Point","coordinates":[12.4964,41.9028]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_6}}, "geometry": {"type":"Polygon","coordinates":[[[12.4960,41.9025],[12.4968,41.9025],[12.4968,41.9031],[12.4960,41.9031],[12.4960,41.9025]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_13}}, "geometry": {"type":"Point","coordinates":[12.4970,41.9030]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_18}}, "geometry": {"type":"Point","coordinates":[12.7320,41.6890]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "us", "id": {{us_id_26}}, "geometry": {"type":"Polygon","coordinates":[[[12.6280,41.4510],[12.6290,41.4510],[12.6290,41.4520],[12.6280,41.4520],[12.6280,41.4510]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "siti", "id": {{sito_urbano}}, "geometry": {"type":"Polygon","coordinates":[[[12.4955,41.9020],[12.4975,41.9020],[12.4975,41.9035],[12.4955,41.9035],[12.4955,41.9020]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "siti", "id": {{sito_rurale}}, "geometry": {"type":"Polygon","coordinates":[[[12.7310,41.6880],[12.7340,41.6880],[12.7340,41.6900],[12.7310,41.6900],[12.7310,41.6880]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "siti", "id": {{sito_subacqueo}}, "geometry": {"type":"Polygon","coordinates":[[[12.6270,41.4500],[12.6300,41.4500],[12.6300,41.4525],[12.6270,41.4525],[12.6270,41.4500]]]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19i. Reperti ────────────────────────────────────────────────────

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP001", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_2}},  "categoria": "Ceramica", "classe": "Terra sigillata",    "stato_cons": "Frammentario", "descrizione": "Coppa in sigillata italica con bollo plantare Ateius.",   "data_ritrovamento": "2018-06-15", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_1: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP002", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_2}},  "categoria": "Ceramica", "classe": "Anfora",             "stato_cons": "Frammentato",   "descrizione": "Orlo di anfora Dressel 2-4 con titulus pictus.",          "data_ritrovamento": "2018-06-16", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_2: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP003", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_3}},  "categoria": "Metallo",  "classe": "Bronzo",             "stato_cons": "Integro",       "descrizione": "Fibula ad arco in bronzo tipo Aucissa.",                  "data_ritrovamento": "2018-07-02", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_3: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP004", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_01}}, "us_provenienza_id": {{us_id_5}},  "categoria": "Ceramica", "classe": "Ceramica comune",    "stato_cons": "Lacunoso",      "descrizione": "Olla con orlo a mandorla e decorazione a pettine.",       "data_ritrovamento": "2018-07-10" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_4: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP005", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "us_provenienza_id": {{us_id_7}},  "categoria": "Laterizio","classe": "Ceramica depurata",   "stato_cons": "Frammentario",  "descrizione": "Frammento di tegola con bollo laterizio officina Domizia.","data_ritrovamento": "2019-05-20" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_5: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP006", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_edif_a}},  "saggio_id": {{sag_02}}, "us_provenienza_id": {{us_id_8}},  "categoria": "Ceramica", "classe": "Terra sigillata",    "stato_cons": "Lacunoso",      "descrizione": "Piatto in sigillata africana tipo Hayes 50A.",            "data_ritrovamento": "2019-05-22", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_6: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP007", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "us_provenienza_id": {{us_id_12}}, "categoria": "Ceramica", "classe": "Ceramica depurata",  "stato_cons": "Frammentato",   "descrizione": "Coppa carenata con orlo estroflesso.",                   "data_ritrovamento": "2020-06-01" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_7: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP008", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_vano_b1}}, "saggio_id": {{sag_03}}, "us_provenienza_id": {{us_id_12}}, "categoria": "Litico",   "classe": "Selce",              "stato_cons": "Integro",       "descrizione": "Lamella in selce ritoccata su entrambi i bordi.",        "data_ritrovamento": "2020-06-03", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_8: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP009", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},  "saggio_id": {{sag_05}}, "us_provenienza_id": {{us_id_13}}, "categoria": "Metallo",  "classe": "Ferro",              "stato_cons": "Lacunoso",      "descrizione": "Chiodo in ferro a sezione quadrata, lunghezza 8 cm.",    "data_ritrovamento": "2022-05-10" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_9: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP010", "sito_id": {{sito_urbano}}, "complesso_id": {{compl_area_n}},  "saggio_id": {{sag_05}}, "us_provenienza_id": {{us_id_14}}, "categoria": "Vetro",    "classe": "Ceramica comune",    "stato_cons": "Frammentario",  "descrizione": "Fondo di balsamario in vetro azzurrino soffiato.",        "data_ritrovamento": "2022-05-12", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_10: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP011", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Ceramica", "classe": "Terra sigillata",   "stato_cons": "Frammentario",  "descrizione": "Scodella in sigillata africana tipo Hayes 91.",           "data_ritrovamento": "2019-07-08", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_11: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP012", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_urb}}, "saggio_id": {{sag_06}}, "us_provenienza_id": {{us_id_15}}, "categoria": "Metallo",  "classe": "Bronzo",            "stato_cons": "Integro",       "descrizione": "Ago crinale in bronzo con punta integra.",               "data_ritrovamento": "2019-07-09" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_12: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP013", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "saggio_id": {{sag_08}}, "us_provenienza_id": {{us_id_19}}, "categoria": "Ceramica", "classe": "Anfora",            "stato_cons": "Lacunoso",      "descrizione": "Dolio con orlo ingrossato tipo Gauloise 4.",             "data_ritrovamento": "2021-06-14" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_13: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP014", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_pars_rust}}, "saggio_id": {{sag_08}}, "us_provenienza_id": {{us_id_18}}, "categoria": "Litico",   "classe": "Selce",             "stato_cons": "Integro",       "descrizione": "Macinello in basalto vesuviano.",                        "data_ritrovamento": "2021-06-15", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_14: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP015", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "us_provenienza_id": {{us_id_21}}, "categoria": "Ceramica", "classe": "Ceramica depurata", "stato_cons": "Integro",       "descrizione": "Skyphos biansato miniaturistico tipo Corinto.",          "data_ritrovamento": "2022-07-20", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_15: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP016", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "us_provenienza_id": {{us_id_21}}, "categoria": "Metallo",  "classe": "Bronzo",            "stato_cons": "Integro",       "descrizione": "Fibula a sanguisuga in bronzo con arco foliato.",        "data_ritrovamento": "2022-07-21", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_16: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP017", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_09}}, "us_provenienza_id": {{us_id_21}}, "categoria": "Osso",     "classe": "Osso lavorato",     "stato_cons": "Lacunoso",      "descrizione": "Spillone in osso con testa a bulbo.",                   "data_ritrovamento": "2022-07-22" }, "plugins": {} }
HTTP 200
[Captures]
rep_id_17: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP018", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "us_provenienza_id": {{us_id_24}}, "categoria": "Ceramica", "classe": "Ceramica comune",   "stato_cons": "Integro",       "descrizione": "Tazza monoansata con decorazione incisa a bande.",       "data_ritrovamento": "2023-06-11", "disegnato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_18: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP019", "sito_id": {{sito_rurale}}, "complesso_id": {{compl_necr}},     "saggio_id": {{sag_10}}, "us_provenienza_id": {{us_id_24}}, "categoria": "Litico",   "classe": "Ossidiana",         "stato_cons": "Integro",       "descrizione": "Grattatoio in ossidiana di Palmarola.",                 "data_ritrovamento": "2023-06-12", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_19: jsonpath "$.id"

POST {{base_url}}/api/record/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "inventario": "REP020", "sito_id": {{sito_subacqueo}}, "complesso_id": {{compl_dep_anf}},  "saggio_id": {{sag_12}}, "us_provenienza_id": {{us_id_28}}, "categoria": "Ceramica", "classe": "Anfora",            "stato_cons": "Integro",       "descrizione": "Anfora Dressel 2-4 integra con pece residua interna.",  "data_ritrovamento": "2023-08-05", "disegnato": 1, "pubblicato": 1 }, "plugins": {} }
HTTP 200
[Captures]
rep_id_20: jsonpath "$.id"


# ── 19j. Misure (plugin → reperti) ───────────────────────────────────

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_1}}, "tipo_misura": "Diametro",  "valore": "18.5", "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_1}}, "tipo_misura": "Altezza",   "valore": "5.2",  "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_3}}, "tipo_misura": "Lunghezza", "valore": "42",   "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_3}}, "tipo_misura": "Larghezza", "valore": "12",   "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_6}}, "tipo_misura": "Diametro",  "valore": "22",   "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_8}}, "tipo_misura": "Lunghezza", "valore": "35",   "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_8}}, "tipo_misura": "Larghezza", "valore": "8",    "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_15}}, "tipo_misura": "Altezza",  "valore": "7.8",  "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_15}}, "tipo_misura": "Diametro", "valore": "9.2",  "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_16}}, "tipo_misura": "Lunghezza","valore": "58",   "unita": "mm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_20}}, "tipo_misura": "Altezza",  "valore": "52",   "unita": "cm" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "reperti", "id_link": {{rep_id_20}}, "tipo_misura": "Diametro", "valore": "14",   "unita": "cm" }, "plugins": {} }
HTTP 200


# ── 19k. m_reperti_in_us (plugin → us) ──────────────────────────────

POST {{base_url}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "us", "id_link": {{us_id_2}},  "materiale_classe": "Ceramica da mensa",    "forma_tipo": "Coppa",  "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 12, "nr_totale_fondi": 8,  "nr_totale_pareti": 145, "nr_totale_anse": 4,  "mni": 5, "peso_totale": "1240 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "us", "id_link": {{us_id_2}},  "materiale_classe": "Ceramica da trasporto", "forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 6,  "nr_totale_fondi": 3,  "nr_totale_pareti": 220, "nr_totale_anse": 12, "mni": 3, "peso_totale": "3680 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "us", "id_link": {{us_id_5}},  "materiale_classe": "Ceramica da cucina",    "forma_tipo": "Olla",   "forma_completa": 0, "datazione_generica": "Basso Medioevo","nr_totale_orli": 8,  "nr_totale_fondi": 6,  "nr_totale_pareti": 98,  "nr_totale_anse": 0,  "mni": 4, "peso_totale": "980 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "us", "id_link": {{us_id_9}},  "materiale_classe": "Ceramica depurata",     "forma_tipo": "Coppa",  "forma_completa": 1, "datazione_generica": "Ferro",        "nr_totale_orli": 3,  "nr_totale_fondi": 2,  "nr_totale_pareti": 41,  "nr_totale_anse": 0,  "mni": 2, "peso_totale": "320 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "us", "id_link": {{us_id_12}}, "materiale_classe": "Ceramica depurata",     "forma_tipo": "Piatto", "forma_completa": 0, "datazione_generica": "Ferro",        "nr_totale_orli": 5,  "nr_totale_fondi": 4,  "nr_totale_pareti": 67,  "nr_totale_anse": 0,  "mni": 3, "peso_totale": "560 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "us", "id_link": {{us_id_19}}, "materiale_classe": "Ceramica da trasporto", "forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 24, "nr_totale_fondi": 15, "nr_totale_pareti": 530, "nr_totale_anse": 48, "mni": 12, "peso_totale": "18500 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "us", "id_link": {{us_id_21}}, "materiale_classe": "Ceramica da mensa",    "forma_tipo": "Coppa",  "forma_completa": 1, "datazione_generica": "Ferro",        "nr_totale_orli": 2,  "nr_totale_fondi": 2,  "nr_totale_pareti": 18,  "nr_totale_anse": 2,  "mni": 1, "peso_totale": "145 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "us", "id_link": {{us_id_28}}, "materiale_classe": "Ceramica da trasporto", "forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 42, "nr_totale_fondi": 38, "nr_totale_pareti": 820, "nr_totale_anse": 84, "mni": 21, "peso_totale": "65000 g" }, "plugins": {} }
HTTP 200


# ── 19l. m_reperti_in_complessi (plugin → complessi) ─────────────────

POST {{base_url}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "complessi", "id_link": {{compl_edif_a}},   "materiale_classe": "Ceramica da mensa",    "forma_tipo": "Coppa",  "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 45, "nr_totale_fondi": 32, "nr_totale_pareti": 680, "nr_totale_anse": 12, "mni": 18, "peso_totale": "8400 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "complessi", "id_link": {{compl_edif_a}},   "materiale_classe": "Ceramica da trasporto","forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 18, "nr_totale_fondi": 12, "nr_totale_pareti": 340, "nr_totale_anse": 36, "mni": 9, "peso_totale": "22000 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "complessi", "id_link": {{compl_pars_rust}}, "materiale_classe": "Ceramica da trasporto","forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 86, "nr_totale_fondi": 71, "nr_totale_pareti": 1840,"nr_totale_anse": 172,"mni": 43, "peso_totale": "145000 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "complessi", "id_link": {{compl_necr}},      "materiale_classe": "Ceramica da mensa",    "forma_tipo": "Coppa",  "forma_completa": 1, "datazione_generica": "Ferro",        "nr_totale_orli": 12, "nr_totale_fondi": 10, "nr_totale_pareti": 89,  "nr_totale_anse": 8,  "mni": 6, "peso_totale": "1250 g" }, "plugins": {} }
HTTP 200

POST {{base_url}}/api/record/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "core": { "table_link": "complessi", "id_link": {{compl_dep_anf}},   "materiale_classe": "Ceramica da trasporto","forma_tipo": "Anfora", "forma_completa": 0, "datazione_generica": "Romano",       "nr_totale_orli": 156,"nr_totale_fondi": 148,"nr_totale_pareti": 3200,"nr_totale_anse": 312,"mni": 78, "peso_totale": "380000 g" }, "plugins": {} }
HTTP 200


# ── 19m. Geodata reperti ────────────────────────────────────────────
POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "reperti", "id": {{rep_id_1}}, "geometry": {"type":"Point","coordinates":[12.4963,41.9027]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "reperti", "id": {{rep_id_3}}, "geometry": {"type":"Point","coordinates":[12.4965,41.9029]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb": "reperti", "id": {{rep_id_15}}, "geometry": {"type":"Point","coordinates":[12.7322,41.6891]} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/geoface/feature
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
POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_3}}, "tb_two": "reperti", "id_two": {{rep_id_6}}, "label": "ceramica diagnostica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_8}}, "tb_two": "reperti", "id_two": {{rep_id_1}}, "label": "elemento datante" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_9}}, "tb_two": "reperti", "id_two": {{rep_id_7}}, "label": "rinvenuto in" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_9}}, "tb_two": "reperti", "id_two": {{rep_id_8}}, "label": "rinvenuto in" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_21}}, "tb_two": "reperti", "id_two": {{rep_id_15}}, "label": "corredo funerario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_21}}, "tb_two": "reperti", "id_two": {{rep_id_16}}, "label": "corredo funerario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_21}}, "tb_two": "reperti", "id_two": {{rep_id_17}}, "label": "corredo funerario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_24}}, "tb_two": "reperti", "id_two": {{rep_id_18}}, "label": "corredo funerario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_24}}, "tb_two": "reperti", "id_two": {{rep_id_19}}, "label": "elemento datante" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_28}}, "tb_two": "reperti", "id_two": {{rep_id_20}}, "label": "carico originario" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── us ↔ us (non-stratigraphic interpretative links) ─────────────────
POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_3}}, "tb_two": "us", "id_two": {{us_id_8}}, "label": "sequenza pavimentale" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_6}}, "tb_two": "us", "id_two": {{us_id_7}}, "label": "crollo del muro" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_7}}, "tb_two": "us", "id_two": {{us_id_15}}, "label": "evento coevo" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_18}}, "tb_two": "us", "id_two": {{us_id_19}}, "label": "impianto produttivo" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_20}}, "tb_two": "us", "id_two": {{us_id_23}}, "label": "sepolture coeve" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "us", "id_one": {{us_id_21}}, "tb_two": "us", "id_two": {{us_id_24}}, "label": "corredi coevi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ── reperti ↔ reperti (typological associations) ─────────────────────
POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_1}}, "tb_two": "reperti", "id_two": {{rep_id_6}}, "label": "stessa classe ceramica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_3}}, "tb_two": "reperti", "id_two": {{rep_id_16}}, "label": "stesso tipo funzionale" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_7}}, "tb_two": "reperti", "id_two": {{rep_id_15}}, "label": "forma analoga" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_15}}, "tb_two": "reperti", "id_two": {{rep_id_18}}, "label": "ceramica miniaturistica" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "reperti", "id_one": {{rep_id_13}}, "tb_two": "reperti", "id_two": {{rep_id_20}}, "label": "ceramica da trasporto" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 19o. Chart ──────────────────────────────────────────────────────
POST {{base_url}}/api/charts
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

POST {{base_url}}/api/charts
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
GET {{base_url}}/api/rs/matrix?tb=us
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


# ── Fuzzy date (chrono) per i 15 siti importati via CSV (IDs 4-18) ────
# ID 4 — Monte Bibele: sito celtico del Ferro IV–II sec. a.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 4, "core": { "chrono_from": -400, "chrono_to": -200, "chrono_label": "-400/-200", "chrono_certainty": 2, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 5 — Cuma: colonia greca dall'VIII sec. a.C. al V sec. d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 5, "core": { "chrono_from": -750, "chrono_to": 500, "chrono_label": "-750/500", "chrono_certainty": 2, "chrono_period": "Greco-Ellenistico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 6 — Populonia: città etrusca VII sec. a.C. – I sec. d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 6, "core": { "chrono_from": -700, "chrono_to": 100, "chrono_label": "-700/100", "chrono_certainty": 2, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 7 — Locri Epizefiri: colonia locrese VII sec. a.C. – III sec. d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 7, "core": { "chrono_from": -680, "chrono_to": 300, "chrono_label": "-680/300", "chrono_certainty": 1, "chrono_period": "Greco-Ellenistico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 8 — Metaponto: santuario arcaico di Hera VII–III sec. a.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 8, "core": { "chrono_from": -700, "chrono_to": -200, "chrono_label": "-700/-200", "chrono_certainty": 2, "chrono_period": "Greco-Ellenistico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 9 — Valle dei Templi: area sacra ellenistica VI–I sec. a.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 9, "core": { "chrono_from": -580, "chrono_to": -100, "chrono_label": "-580/-100", "chrono_certainty": 1, "chrono_period": "Greco-Ellenistico" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 10 — Valle d'Ansanto: santuario italico IV sec. a.C. – I sec. d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 10, "core": { "chrono_from": -400, "chrono_to": 100, "chrono_label": "-400/100", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 11 — Cerveteri: necropoli etrusca della Banditaccia VIII–I sec. a.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 11, "core": { "chrono_from": -800, "chrono_to": -100, "chrono_label": "-800/-100", "chrono_certainty": 1, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 12 — Tarquinia: necropoli Monterozzi con pitture parietali VII–I sec. a.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 12, "core": { "chrono_from": -700, "chrono_to": -100, "chrono_label": "-700/-100", "chrono_certainty": 1, "chrono_period": "Ferro" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 13 — Baia: parco sommerso con ville e terme romane I sec. a.C. – IV sec. d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 13, "core": { "chrono_from": -50, "chrono_to": 400, "chrono_label": "-50/400", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 14 — Porto di Caligola: porto di età giulio-claudia I–II sec. d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 14, "core": { "chrono_from": 37, "chrono_to": 200, "chrono_label": "37/200", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 15 — Gragnano: villa rustica vinicola I sec. a.C. – IV sec. d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 15, "core": { "chrono_from": -100, "chrono_to": 400, "chrono_label": "-100/400", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 16 — Oplontis B: edificio commerciale distrutto dal 79 d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 16, "core": { "chrono_from": -50, "chrono_to": 79, "chrono_label": "-50/79", "chrono_certainty": 1, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 17 — Quintiliolo: villa tardorepubblicana I sec. a.C. – I sec. d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 17, "core": { "chrono_from": -100, "chrono_to": 100, "chrono_label": "-100/100", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# ID 18 — Lucus Feroniae: forum e colonia romana IV sec. a.C. – IV sec. d.C.
POST {{base_url}}/api/record/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 18, "core": { "chrono_from": -300, "chrono_to": 400, "chrono_label": "-300/400", "chrono_certainty": 2, "chrono_period": "Romano" }, "plugins": {} }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


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
