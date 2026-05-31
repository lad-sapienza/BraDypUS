# ════════════════════════════════════════════════════════════════════
# Phase 27 — Record-view layout templates
#
# Routes exercised:
#   GET    /api/templates                         list tables with templates
#   GET    /api/templates/{tb}                    list template names for table
#   GET    /api/template/{tb}/{name}              get template JSON content
#   POST   /api/template/{tb}/{name}              save (create/overwrite) template
#   POST   /api/template/{tb}/{name}/rename       rename template
#   DELETE /api/template/{tb}/{name}              delete template
#
# Also covers: GET /api/config/field-structure
# ════════════════════════════════════════════════════════════════════


# ── 27a. GET /api/config/field-structure — returns meta-schema ───────
GET {{base_url}}/api/config/field-structure
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.structure" isCollection


# ── 27b. List tables — no templates yet ──────────────────────────────
GET {{base_url}}/api/templates
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.tables" isCollection


# ── 27c. List template names for "us" — empty ────────────────────────
GET {{base_url}}/api/templates/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.templates" isCollection


# ── 27d. Save a template for "us" ────────────────────────────────────
POST {{base_url}}/api/template/us/ci_test_tpl
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "sections": [
    {
      "label": "Main info",
      "content": [
        { "field": "sigla" },
        { "field": "descrizione" }
      ]
    }
  ]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "template_saved"


# ── 27e. Template appears in list ────────────────────────────────────
GET {{base_url}}/api/templates/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.templates" includes "ci_test_tpl"


# ── 27f. Get template content ────────────────────────────────────────
GET {{base_url}}/api/template/us/ci_test_tpl
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.template" isCollection


# ── 27g. Rename template ─────────────────────────────────────────────
POST {{base_url}}/api/template/us/ci_test_tpl/rename
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "new_name": "ci_test_tpl_renamed" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "template_renamed"


# ── 27h. Old name gone; new name present ────────────────────────────
GET {{base_url}}/api/templates/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.templates" not includes "ci_test_tpl"
jsonpath "$.templates" includes "ci_test_tpl_renamed"


# ── 27i. Delete renamed template ─────────────────────────────────────
DELETE {{base_url}}/api/template/us/ci_test_tpl_renamed
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "template_deleted"


# ── 27j. List empty again ────────────────────────────────────────────
GET {{base_url}}/api/templates/us
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.templates" not includes "ci_test_tpl_renamed"


# ── 27k. Get non-existent template → template_not_found ─────────────
GET {{base_url}}/api/template/us/no_such_template
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "template_not_found"
