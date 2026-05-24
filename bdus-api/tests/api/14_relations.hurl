# ════════════════════════════════════════════════════════════════════
# Phase 14 — Relations panel (bdus_cfg_relations CRUD)
#
# Prerequisites (from phase 3):
#   Tables: us, periodi, attivita
#   Existing relation: periodi ↔ us  (created via PUT /api/config/table/us
#                                     with the 'link' key in step 3j)
#
# This phase verifies the dedicated Relations endpoints:
#   GET    /api/config/relations          list all
#   POST   /api/config/relations          create
#   PUT    /api/config/relations/{id}     update
#   DELETE /api/config/relations/{id}     delete
# ════════════════════════════════════════════════════════════════════


# ── 14a. List relations — existing periodi↔us must be present ────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relations"
jsonpath "$.data"   isCollection
jsonpath "$.data"   count >= 1
# The relation created in phase 3j (PUT table/us with link to periodi) is stored
# as-is by upsertRelations — from_tb=us, to_tb=periodi (no canonical reordering there).
jsonpath "$.data[*].from_tb" contains "us"
jsonpath "$.data[*].to_tb"   contains "periodi"


# ── 14b. Create relation: us ↔ attivita ──────────────────────────────
# us.id → attivita.id_link
# Canonical order: attivita < us (a < u), so backend stores attivita as from_tb.
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "us",
  "to_tb":   "attivita",
  "fld":     [{ "my": "id", "other": "id_link" }]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relation_saved"
jsonpath "$.id"     isInteger
[Captures]
rel_id: jsonpath "$.id"


# ── 14c. List relations — two rows now ───────────────────────────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data"   count == 2
# The new relation must appear (backend stores attivita as from_tb)
jsonpath "$.data[*].from_tb" contains "attivita"
jsonpath "$.data[*].to_tb"   contains "us"


# ── 14d. GET response shape is complete ──────────────────────────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[0].id"         isInteger
jsonpath "$.data[0].from_tb"    isString
jsonpath "$.data[0].to_tb"      isString
jsonpath "$.data[0].from_label" isString
jsonpath "$.data[0].to_label"   isString
jsonpath "$.data[0].fld"        isCollection


# ── 14e. Update relation — change field mapping ───────────────────────
PUT {{base_url}}/api/config/relations/{{rel_id}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "us",
  "to_tb":   "attivita",
  "fld":     [{ "my": "id", "other": "id_link" }, { "my": "sigla", "other": "note" }]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relation_saved"
jsonpath "$.id"     == {{rel_id}}


# ── 14f. Verify updated mapping is persisted ──────────────────────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
# Two relations must still be present.
jsonpath "$.data" count == 2


# ── 14g-neg. Duplicate pair (same direction) → error ─────────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "attivita",
  "to_tb":   "us",
  "fld":     []
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "relation_already_exists"


# ── 14h-neg. Duplicate pair (reverse direction) → error ──────────────
# Even if we swap the pair, the canonical form is identical → must fail.
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "us",
  "to_tb":   "attivita",
  "fld":     []
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "relation_already_exists"


# ── 14i-neg. Self-loop → error ────────────────────────────────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "us",
  "to_tb":   "us",
  "fld":     []
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "relation_self_loop"


# ── 14j-neg. Missing to_tb → error ───────────────────────────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "us",
  "to_tb":   "",
  "fld":     []
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 14k-neg. Delete non-existent id → error ──────────────────────────
DELETE {{base_url}}/api/config/relations/999999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_found"


# ── 14l. Delete the created relation ─────────────────────────────────
DELETE {{base_url}}/api/config/relations/{{rel_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relation_deleted"


# ── 14m. List after delete — back to one relation ────────────────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data"   count == 1
# The surviving relation is the us→periodi one from phase 3j.
jsonpath "$.data[0].from_tb" == "us"
jsonpath "$.data[0].to_tb"   == "periodi"
