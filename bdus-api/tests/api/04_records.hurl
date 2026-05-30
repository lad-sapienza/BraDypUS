# ════════════════════════════════════════════════════════════════════
# Phase 4 — Record CRUD
#
# Creates two US records, one periodo record, reads and updates them,
# then deletes one to verify the full write lifecycle.
# ════════════════════════════════════════════════════════════════════

# ── 4a. Fetch empty record skeleton ─────────────────────────────────
GET {{base_url}}/api/record/us/new
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core" isCollection


# ── 4b. Insert US 1 ─────────────────────────────────────────────────
POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":           "US001",
    "descrizione":     "Strato di humus superficiale",
    "interpretazione": "Strato di formazione moderna",
    "periodo":         "Età contemporanea"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "success_created"
jsonpath "$.id"     isInteger
[Captures]
us_id_1: jsonpath "$.id"


# ── 4c. Insert US 2 ─────────────────────────────────────────────────
POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":           "US002",
    "descrizione":     "Strato argilloso grigio-marrone",
    "interpretazione": "Livello di abbandono medievale",
    "periodo":         "Basso Medioevo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.id"     isInteger
[Captures]
us_id_2: jsonpath "$.id"


# ── 4d. Insert US 3 (will be deleted later) ─────────────────────────
POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":       "US003",
    "descrizione": "Buca di palo",
    "periodo":     "Età del Bronzo"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
us_id_3: jsonpath "$.id"


# ── 4e. Insert periodo record ────────────────────────────────────────
POST {{base_url}}/api/record/periodi
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "nome":        "Basso Medioevo",
    "data_inizio": "1000",
    "data_fine":   "1400"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
periodo_id_1: jsonpath "$.id"


# ── 4f. Read US 1 back ──────────────────────────────────────────────
GET {{base_url}}/api/record/us/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.sigla.val"           == "US001"
jsonpath "$.core.descrizione.val"     isString
jsonpath "$.metadata.can_edit"        == true
# Plugin flags: us table has rs, geodata and zotero all enabled (set in 3g)
jsonpath "$.schema.rs_field"          isString
jsonpath "$.schema.has_geodata"       == true
jsonpath "$.schema.has_zotero"        == true
jsonpath "$.geodata"                  isCollection
jsonpath "$.bibliography"             isCollection


# ── 4g. Update US 1 — add interpretazione ───────────────────────────
POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{us_id_1}},
  "core": {
    "interpretazione": "Strato di formazione moderna (aggiornato)"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "success_saved"
jsonpath "$.id"     == {{us_id_1}}


# ── 4h. Read US 1 — verify update ───────────────────────────────────
GET {{base_url}}/api/record/us/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.interpretazione.val" == "Strato di formazione moderna (aggiornato)"


# ── 4i. Get record list ──────────────────────────────────────────────
GET {{base_url}}/api/records/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.total"  >= 3
jsonpath "$.data"   isCollection


# ── 4j. Delete US 3 ─────────────────────────────────────────────────
DELETE {{base_url}}/api/record/us/{{us_id_3}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "all_record_deleted"


# ── 4k. Verify US 3 is gone ─────────────────────────────────────────
GET {{base_url}}/api/records/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.total" == 2


# ── 4l. check-unique — value does not exist → unique: true ───────────
GET {{base_url}}/api/record/us/check-unique?field=sigla&value=ZZ999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.unique" == true


# ── 4m. check-unique — value exists → unique: false ──────────────────
GET {{base_url}}/api/record/us/check-unique?field=sigla&value=US001
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.unique" == false


# ── 4n. check-unique — same record excluded (UPDATE self) ────────────
GET {{base_url}}/api/record/us/check-unique?field=sigla&value=US001&id={{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.unique" == true


# ── 4o. check-unique — missing params → error ────────────────────────
GET {{base_url}}/api/record/us/check-unique?field=sigla
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 4p. check-unique — unknown field → error ─────────────────────────
GET {{base_url}}/api/record/us/check-unique?field=nonexistent&value=x
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "field_not_found"
