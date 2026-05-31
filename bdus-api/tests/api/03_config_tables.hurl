# ════════════════════════════════════════════════════════════════════
# Phase 3 — Configure tables and fields
#
# Creates all tables for the bdus_demo app:
#   siti, complessi, saggi, us, reperti  (main tables)
#   misure, m_reperti_in_us, m_reperti_in_complessi  (plugin tables)
#
# Field types covered: text, textarea, long_text, boolean, date,
#                      select, combo_select, multi_select, slider
# Population policies: vocabulary_set, dic, id_from_tb, get_values_from_tb
# Validation checks:   not_empty, int, email, no_dupl, range, regex
# ════════════════════════════════════════════════════════════════════


# ── 3a. Create tables ────────────────────────────────────────────────

POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "siti", "label": "Siti Archeologici", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.tb" == "siti"

POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "complessi", "label": "Complessi", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "saggi", "label": "Saggi di scavo", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "us", "label": "Unità stratigrafiche", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.tb" == "us"

POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "reperti", "label": "Reperti mobili", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "misure", "label": "Misure", "is_plugin": "1" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "m_reperti_in_us", "label": "Reperti in US (conteggio)", "is_plugin": "1" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "m_reperti_in_complessi", "label": "Reperti in complessi (conteggio)", "is_plugin": "1" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3a-neg. Duplicate table name ─────────────────────────────────────
POST {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "us", "label": "Duplicate", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "tb_already_available"


# ── 3b. Fields: siti ─────────────────────────────────────────────────
POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nome", "label": "Nome sito", "type": "text", "db_type": "TEXT",
  "check": ["not_empty"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "localita", "label": "Località", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tipologia", "label": "Tipologia", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "sito_tipologia" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "contatto_email", "label": "Email referente", "type": "text", "db_type": "TEXT",
  "check": ["email"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/siti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "descrizione", "label": "Descrizione", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3c. Fields: complessi ─────────────────────────────────────────────
POST {{base_url}}/api/config/table/complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nome", "label": "Nome complesso", "type": "text", "db_type": "TEXT",
  "check": ["not_empty"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tipo", "label": "Tipo", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "complesso_tipo" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sito_id", "label": "Sito", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "siti" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "cronologia", "label": "Cronologia", "type": "multi_select", "db_type": "TEXT",
  "vocabulary_set": "periodi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "descrizione", "label": "Descrizione", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3d. Fields: saggi ─────────────────────────────────────────────────
POST {{base_url}}/api/config/table/saggi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nome", "label": "Nome saggio", "type": "text", "db_type": "TEXT",
  "check": ["not_empty"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/saggi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "anno", "label": "Anno", "type": "text", "db_type": "INTEGER",
  "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/saggi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sito_id", "label": "Sito", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "siti" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/saggi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "complesso_id", "label": "Complesso", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "complessi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/saggi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "descrizione", "label": "Descrizione", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3e. Fields: us ───────────────────────────────────────────────────
POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sigla", "label": "Sigla US", "type": "text", "db_type": "TEXT",
  "check": ["not_empty", "no_dupl", "regex"],
  "pattern": "^[A-Z]{1,3}\\d{3,}$" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# 3e-neg. Duplicate field
POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sigla", "label": "Duplicate", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "fld_already_available"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tipo", "label": "Tipo US", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "us_tipo" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sito_id", "label": "Sito", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "siti" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "complesso_id", "label": "Complesso", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "complessi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "saggio_id", "label": "Saggio", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "saggi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

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
{ "name": "affidabilita", "label": "Affidabilità interpretazione", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "affidabilita" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "periodo", "label": "Periodo", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "periodi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "anno_scavo", "label": "Anno di scavo", "type": "text", "db_type": "INTEGER",
  "check": ["int"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nr_cassette", "label": "Nr. cassette", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "profondita_cm", "label": "Profondità (cm)", "type": "slider", "db_type": "INTEGER",
  "max": 500 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "note", "label": "Note", "type": "long_text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "verificata", "label": "Verificata", "type": "boolean", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3f. Fields: reperti ───────────────────────────────────────────────
POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "inventario", "label": "N. inventario", "type": "text", "db_type": "TEXT",
  "check": ["not_empty", "no_dupl"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sito_id", "label": "Sito", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "siti" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "complesso_id", "label": "Complesso", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "complessi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "saggio_id", "label": "Saggio", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "saggi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "us_provenienza_id", "label": "US di provenienza", "type": "select", "db_type": "INTEGER",
  "id_from_tb": "us" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "categoria", "label": "Categoria", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "reperto_categoria" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "classe", "label": "Classe", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "reperto_classe" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tipologia", "label": "Tipologia", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "stato_cons", "label": "Stato di conservazione", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "stato_conservazione" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "descrizione", "label": "Descrizione", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "frammenti_conservati", "label": "Frammenti conservati", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "decorazione", "label": "Decorazione", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "dati_epigrafici", "label": "Dati epigrafici", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "data_ritrovamento", "label": "Data di ritrovamento", "type": "date", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "disegnato", "label": "Disegnato", "type": "boolean", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "pubblicato", "label": "Pubblicato", "type": "boolean", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/reperti/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "note", "label": "Note", "type": "long_text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3g. Fields: misure (plugin → reperti) ────────────────────────────
POST {{base_url}}/api/config/table/misure/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "tipo_misura", "label": "Tipo misura", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "tipo_misura" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/misure/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "valore", "label": "Valore", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/misure/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "unita", "label": "Unità", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "unita_misura" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3h. Fields: m_reperti_in_us (plugin → us) ────────────────────────
POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "materiale_classe", "label": "Classe materiale", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "materiale_classe" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "forma_tipo", "label": "Forma e tipo", "type": "combo_select", "db_type": "TEXT",
  "dic": ["Coppa", "Piatto", "Brocca", "Anfora", "Olla", "Tegola", "Mattone", "Fibula", "Chiodo"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "forma_completa", "label": "Forma completa", "type": "boolean", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "datazione_generica", "label": "Datazione generica", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "periodi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nr_totale_orli", "label": "Nr. orli", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nr_totale_fondi", "label": "Nr. fondi", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nr_totale_pareti", "label": "Nr. pareti", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nr_totale_anse", "label": "Nr. anse", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "mni", "label": "MNI (minimo individui)", "type": "text", "db_type": "INTEGER",
  "check": ["range"], "min": 1, "max": 9999 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "peso_totale", "label": "Peso totale", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "note", "label": "Note", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_us/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sort", "label": "Ordine", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3i. Fields: m_reperti_in_complessi (plugin → complessi) ──────────
POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "materiale_classe", "label": "Classe materiale", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "materiale_classe" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "forma_tipo", "label": "Forma e tipo", "type": "combo_select", "db_type": "TEXT",
  "dic": ["Coppa", "Piatto", "Brocca", "Anfora", "Olla", "Tegola", "Mattone", "Fibula", "Chiodo"] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "forma_completa", "label": "Forma completa", "type": "boolean", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "datazione_generica", "label": "Datazione generica", "type": "select", "db_type": "TEXT",
  "vocabulary_set": "periodi" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nr_totale_orli", "label": "Nr. orli", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nr_totale_fondi", "label": "Nr. fondi", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nr_totale_pareti", "label": "Nr. pareti", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "nr_totale_anse", "label": "Nr. anse", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "mni", "label": "MNI (minimo individui)", "type": "text", "db_type": "INTEGER",
  "check": ["range"], "min": 1, "max": 9999 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "peso_totale", "label": "Peso totale", "type": "text", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "note", "label": "Note", "type": "textarea", "db_type": "TEXT" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/api/config/table/m_reperti_in_complessi/field
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "name": "sort", "label": "Ordine", "type": "text", "db_type": "INTEGER" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3j. Configure table features (PUT) ───────────────────────────────

PUT {{base_url}}/api/config/table/siti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "siti",
  "label":     "Siti Archeologici",
  "is_plugin": "0",
  "order":     "nome",
  "id_field":  "id",
  "preview":   ["nome", "localita"],
  "geodata":   1,
  "zotero":    1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/api/config/table/complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "complessi",
  "label":     "Complessi",
  "is_plugin": "0",
  "order":     "nome",
  "id_field":  "id",
  "preview":   ["nome", "tipo"],
  "geodata":   1,
  "link": [
    { "other_tb": "siti", "fld": [{ "my": "sito_id", "other": "id" }] }
  ]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/api/config/table/saggi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "saggi",
  "label":     "Saggi di scavo",
  "is_plugin": "0",
  "order":     "nome",
  "id_field":  "id",
  "preview":   ["nome", "anno"],
  "link": [
    { "other_tb": "siti",     "fld": [{ "my": "sito_id",     "other": "id" }] },
    { "other_tb": "complessi", "fld": [{ "my": "complesso_id", "other": "id" }] }
  ]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

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
  "rs":        "sigla",
  "geodata":   1,
  "zotero":    1,
  "link": [
    { "other_tb": "siti",     "fld": [{ "my": "sito_id",     "other": "id" }] },
    { "other_tb": "complessi", "fld": [{ "my": "complesso_id", "other": "id" }] },
    { "other_tb": "saggi",     "fld": [{ "my": "saggio_id",   "other": "id" }] }
  ]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/api/config/table/reperti
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "reperti",
  "label":     "Reperti mobili",
  "is_plugin": "0",
  "order":     "inventario",
  "id_field":  "id",
  "preview":   ["inventario"],
  "geodata":   1,
  "zotero":    1,
  "link": [
    { "other_tb": "us", "fld": [{ "my": "us_provenienza_id", "other": "id" }] }
  ]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


PUT {{base_url}}/api/config/table/misure
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "misure",
  "label":     "Misure",
  "is_plugin": "1",
  "plugin_of": "reperti",
  "id_field":  "id"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/api/config/table/m_reperti_in_us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "m_reperti_in_us",
  "label":     "Reperti in US (conteggio)",
  "is_plugin": "1",
  "plugin_of": "us",
  "id_field":  "id"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

PUT {{base_url}}/api/config/table/m_reperti_in_complessi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "m_reperti_in_complessi",
  "label":     "Reperti in complessi (conteggio)",
  "is_plugin": "1",
  "plugin_of": "complessi",
  "id_field":  "id"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 3k. Verify final table list ───────────────────────────────────────
GET {{base_url}}/api/config/tables
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"           == "success"
jsonpath "$.tables"           count >= 8
jsonpath "$.tables[*].name"   contains "siti"
jsonpath "$.tables[*].name"   contains "complessi"
jsonpath "$.tables[*].name"   contains "saggi"
jsonpath "$.tables[*].name"   contains "us"
jsonpath "$.tables[*].name"   contains "reperti"
jsonpath "$.tables[*].name"   contains "misure"
jsonpath "$.tables[*].name"   contains "m_reperti_in_us"
jsonpath "$.tables[*].name"   contains "m_reperti_in_complessi"

# ── 3l. Verify us config: RS, plugins, geodata, links ────────────────
GET {{base_url}}/api/config/table/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"              == "success"
jsonpath "$.table.rs"            == "sigla"
jsonpath "$.table.geodata"       not == null
jsonpath "$.table.zotero"        not == null
jsonpath "$.table.plugin"        isCollection
jsonpath "$.table.plugin[0]"     == "m_reperti_in_us"
jsonpath "$.table.link"          isCollection
jsonpath "$.table.link"          count == 4

# ── 3m. Verify field labels are persisted (getFldList returns {name: label} map)
GET {{base_url}}/api/config/table/us/fields
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"              == "success"
jsonpath "$.fields.sigla"        isString
jsonpath "$.fields.profondita_cm" isString
jsonpath "$.fields.verificata"   isString
jsonpath "$.fields.note"         isString
