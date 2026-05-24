# ════════════════════════════════════════════════════════════════════
# Phase 15 — Vocabularies CRUD
#
# Routes:
#   GET    /api/vocabularies          list all vocabulary entries
#   POST   /api/vocabularies          add entry (body: { voc, def })
#   POST   /api/vocabularies/sort     re-sort entries (body: { ids: [...] })
#   PATCH  /api/vocabulary/{id}       edit entry label (body: { val })
#   DELETE /api/vocabulary/{id}       delete entry
# ════════════════════════════════════════════════════════════════════


# ── 15a. List vocabularies — empty at start ──────────────────────────
GET {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.vocs"   isCollection
jsonpath "$.vocs"   count == 0


# ── 15b. Add first entry (periodo / Arcaico) ─────────────────────────
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "voc": "periodo",
  "def": "Arcaico"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_def_added"


# ── 15c. Add second entry (periodo / Classico) ───────────────────────
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "voc": "periodo",
  "def": "Classico"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_def_added"


# ── 15d. Add entry in a second set (tipo / Positivo) ─────────────────
POST {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "voc": "tipo",
  "def": "Positivo"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_def_added"


# ── 15e. List vocabularies — two sets, three entries ─────────────────
GET {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"        == "success"
jsonpath "$.vocs"          count == 2
jsonpath "$.vocs[*].name"  contains "periodo"
jsonpath "$.vocs[*].name"  contains "tipo"
[Captures]
# Grab the id of the first entry in the first set for later operations.
# periodo group is [0] (alphabetical order guaranteed by the query: ORDER BY voc, sort)
voc_id_1: jsonpath "$.vocs[0].items[0].id"
voc_id_2: jsonpath "$.vocs[0].items[1].id"


# ── 15f. GET response shape is complete ──────────────────────────────
GET {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.vocs[0].name"          isString
jsonpath "$.vocs[0].items"         isCollection
jsonpath "$.vocs[0].items[0].id"   isInteger
jsonpath "$.vocs[0].items[0].def"  isString
jsonpath "$.vocs[0].items[0].sort" isInteger


# ── 15g. Edit an entry — change "Arcaico" to "Arcaico (EBA)" ─────────
PATCH {{base_url}}/api/vocabulary/{{voc_id_1}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "val": "Arcaico (EBA)"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_def_update"


# ── 15h. Verify edited value is persisted ────────────────────────────
GET {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
# The edited entry must appear with the new label
jsonpath "$.vocs[0].items[*].def" contains "Arcaico (EBA)"


# ── 15i. Sort entries — reverse the periodo order ────────────────────
POST {{base_url}}/api/vocabularies/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "ids": [{{voc_id_2}}, {{voc_id_1}}]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_sort_update"


# ── 15j-neg. Delete non-existent entry → error ───────────────────────
# Erase returns error_def_erase when the row is not found.
DELETE {{base_url}}/api/vocabulary/999999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "error_def_erase"


# ── 15k. Delete one of the periodo entries ────────────────────────────
DELETE {{base_url}}/api/vocabulary/{{voc_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_def_erase"


# ── 15l. List after delete — periodo now has one entry ────────────────
GET {{base_url}}/api/vocabularies
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
# Still two sets
jsonpath "$.vocs"   count == 2
# periodo set now has one item (Classico)
jsonpath "$.vocs[0].items" count == 1
jsonpath "$.vocs[0].items[0].def" == "Classico"
