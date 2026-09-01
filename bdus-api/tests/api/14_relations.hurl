# ════════════════════════════════════════════════════════════════════
# Phase 14 — FK Relations panel (bdus_cfg_relations CRUD)
#
# New schema (M026): one row per FK column pair.
#   from_tb.from_col → to_tb.to_col  (semantic direction: from_tb holds the FK)
#   No alphabetical normalization — direction is preserved as given.
#   UNIQUE(from_tb, from_col) — one column → one parent only.
#   Self-referential allowed, on_delete forced to RESTRICT / on_update to CASCADE.
#
# Pre-conditions (phase 3):
#   Phase 3 PUT calls created 7 FK config rows via ToDB::upsertRelations:
#     complessi.sito_id        → siti.id
#     saggi.sito_id            → siti.id
#     saggi.complesso_id       → complessi.id
#     us.sito_id               → siti.id
#     us.complesso_id          → complessi.id
#     us.saggio_id             → saggi.id
#     reperti.us_provenienza_id → us.id
#
# Endpoints tested:
#   GET    /api/config/relations              list
#   POST   /api/config/relations              create
#   PUT    /api/config/relations/{id}         update
#   DELETE /api/config/relations/{id}         delete
#   POST   /api/config/apply-constraints      apply all to DB
# ════════════════════════════════════════════════════════════════════


# ── 14a. List — phase-3 rows present, new schema shape ──────────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.code"    == "relations"
jsonpath "$.data"    isCollection
jsonpath "$.data"    count >= 7
[Captures]
initial_rel_count: jsonpath "$.data" count


# ── 14b. Response shape — new fields present, fld/sort absent ───────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[0].id"         isInteger
jsonpath "$.data[0].from_tb"    isString
jsonpath "$.data[0].from_col"   isString
jsonpath "$.data[0].to_tb"      isString
jsonpath "$.data[0].to_col"     isString
jsonpath "$.data[0].on_delete"  isString
jsonpath "$.data[0].on_update"  isString
jsonpath "$.data[0].from_label" isString
jsonpath "$.data[0].to_label"   isString


# ── 14c. Known relation from phase 3 is present ─────────────────────
# us.sito_id → siti.id must exist
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[*].from_tb"  contains "us"
jsonpath "$.data[*].from_col" contains "sito_id"
jsonpath "$.data[*].to_tb"    contains "siti"


# ── 14d. Direction is semantic — from_tb is the FK holder ───────────
# complessi.sito_id → siti.id: from_tb must be "complessi", not "siti"
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[*].from_tb" contains "complessi"
# "siti" should appear only as to_tb when referenced by other tables
jsonpath "$.data[*].to_tb"   contains "siti"


# ── 14e. Create — self-referential (siti.localita → siti.nome) ──────
# siti.localita is a free column not already in bdus_cfg_relations.
# siti is empty at this point (seed is phase 19) → 0 orphans.
# Self-referential forces on_delete=RESTRICT, on_update=CASCADE regardless of input.
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb":  "siti",
  "from_col": "localita",
  "to_tb":    "siti",
  "to_col":   "nome",
  "on_delete": "CASCADE",
  "on_update": "NO ACTION"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relation_saved"
jsonpath "$.id"     isInteger
[Captures]
self_rel_id: jsonpath "$.id"


# ── 14f. Self-referential policy forced to RESTRICT / CASCADE ────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
# Verify siti.localita self-referential row has forced policy (RESTRICT/CASCADE).
jsonpath "$.data[?(@.from_tb == 'siti' && @.from_col == 'localita')].on_delete" contains "RESTRICT"
jsonpath "$.data[?(@.from_tb == 'siti' && @.from_col == 'localita')].on_update" contains "CASCADE"


# ── 14g. Create — plain FK with explicit on_delete CASCADE ──────────
# Creates a FK from us.sigla → siti.nome (string-to-string — orphans possible,
# but pre-seed = empty tables → no orphans → FK applied to DB successfully).
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb":  "us",
  "from_col": "sigla",
  "to_tb":    "siti",
  "to_col":   "nome",
  "on_delete": "CASCADE",
  "on_update": "CASCADE"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relation_saved"
jsonpath "$.id"     isInteger
[Captures]
cascade_rel_id: jsonpath "$.id"


# ── 14h. List — new rows appeared ───────────────────────────────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data[*].id" contains {{self_rel_id}}
jsonpath "$.data[*].id" contains {{cascade_rel_id}}


# ── 14i. Update — change on_delete of the cascade relation ──────────
PUT {{base_url}}/api/config/relations/{{cascade_rel_id}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb":  "us",
  "from_col": "sigla",
  "to_tb":    "siti",
  "to_col":   "nome",
  "on_delete": "RESTRICT",
  "on_update": "CASCADE"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relation_saved"
jsonpath "$.id"     == {{cascade_rel_id}}


# ── 14j. Update — verify on_delete change persisted ─────────────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.data[?(@.id == {{cascade_rel_id}})].on_delete" contains "RESTRICT"


# ── 14k. applyAllConstraints — applies all config rows to DB ────────
# Pre-seed: empty tables → 0 orphans for all relations → all applied.
POST {{base_url}}/api/config/apply-constraints
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.applied" isInteger
jsonpath "$.skipped" isInteger
jsonpath "$.errors"  isCollection
jsonpath "$.skipped" == 0


# ── 14l. Delete — cascade relation ──────────────────────────────────
DELETE {{base_url}}/api/config/relations/{{cascade_rel_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relation_deleted"


# ── 14m. Delete — self-referential ──────────────────────────────────
DELETE {{base_url}}/api/config/relations/{{self_rel_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "relation_deleted"


# ── 14n. List after deletes — count back to initial ─────────────────
GET {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data"   count == {{initial_rel_count}}
jsonpath "$.data[*].id" not contains {{cascade_rel_id}}
jsonpath "$.data[*].id" not contains {{self_rel_id}}


# ════════════════════════════════════════════════════════════════════
# Negative cases
# ════════════════════════════════════════════════════════════════════

# ── 14o-neg. Missing from_col → error ───────────────────────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "us",
  "to_tb":   "siti",
  "to_col":  "id"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 14p-neg. Missing to_col → error ─────────────────────────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb":  "us",
  "from_col": "sigla",
  "to_tb":    "siti"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 14q-neg. Duplicate (from_tb, from_col) → error ──────────────────
# us.sito_id → siti.id already exists from phase 3.
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb":  "us",
  "from_col": "sito_id",
  "to_tb":    "siti",
  "to_col":   "nome"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "relation_already_exists"


# ── 14r-neg. Invalid on_delete policy → error ───────────────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb":  "complessi",
  "from_col": "nome",
  "to_tb":    "siti",
  "to_col":   "nome",
  "on_delete": "EXPLODE"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_on_delete"


# ── 14s-neg. Invalid on_update policy → error ───────────────────────
POST {{base_url}}/api/config/relations
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb":  "complessi",
  "from_col": "nome",
  "to_tb":    "siti",
  "to_col":   "nome",
  "on_delete": "RESTRICT",
  "on_update": "BOOM"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_on_update"


# ── 14t-neg. Update non-existent id → error ─────────────────────────
PUT {{base_url}}/api/config/relations/999999
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "from_tb": "us", "from_col": "sito_id",
  "to_tb":   "siti", "to_col": "id"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_found"


# ── 14u-neg. Delete non-existent id → error ─────────────────────────
DELETE {{base_url}}/api/config/relations/999999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_found"
