# ════════════════════════════════════════════════════════════════════
# Phase 14 — Relations panel (bdus_cfg_relations CRUD)
#
# Prerequisites (from phase 3):
#   Tables: siti, complessi, saggi, us, reperti (and plugins)
#   Existing relations created by PUT calls in phase 3:
#     complessi→siti, saggi→siti, saggi→complessi,
#     us→siti, us→complessi, us→saggi, reperti→us  (7 total)
#
# This phase verifies the dedicated Relations endpoints:
#   GET    /api/config/relations          list all
#   POST   /api/config/relations          create
#   PUT    /api/config/relations/{id}     update
#   DELETE /api/config/relations/{id}     delete
# ════════════════════════════════════════════════════════════════════


# ── 14a. List relations — phase 3 relations must be present ──────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relations"
jsonpath "$.data"   isCollection
jsonpath "$.data"   count >= 7
jsonpath "$.data[*].from_tb" contains "us"
jsonpath "$.data[*].from_tb" contains "reperti"
[Captures]
initial_rel_count: jsonpath "$.data" count


# ── 14b. Create relation: siti ↔ reperti ─────────────────────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "siti",
  "to_tb":   "reperti",
  "fld":     [{ "my": "id", "other": "sito_id" }]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relation_saved"
jsonpath "$.id"     isInteger
[Captures]
rel_id: jsonpath "$.id"


# ── 14c. List relations — new relation must appear ────────────────────
# Backend stores canonical form: from_tb=reperti (r < s alphabetically), to_tb=siti
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data"   count >= {{initial_rel_count}}
jsonpath "$.data[*].from_tb" contains "reperti"
jsonpath "$.data[*].to_tb"   contains "siti"


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
  "from_tb": "siti",
  "to_tb":   "reperti",
  "fld":     [{ "my": "id", "other": "sito_id" }, { "my": "nome", "other": "tipologia" }]
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
jsonpath "$.data"   count >= {{initial_rel_count}}


# ── 14g-neg. Duplicate pair (canonical direction) → error ────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "reperti",
  "to_tb":   "siti",
  "fld":     []
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "relation_already_exists"


# ── 14h-neg. Duplicate pair (reverse direction) → error ──────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "siti",
  "to_tb":   "reperti",
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


# ── 14m. List after delete — siti↔reperti relation gone ──────────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data"   count >= 7
# The temporary siti↔reperti relation must no longer appear
jsonpath "$.data[*].id" not contains {{rel_id}}
