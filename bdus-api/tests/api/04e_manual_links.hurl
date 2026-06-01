# ════════════════════════════════════════════════════════════════════
# Phase 4e — Manual links (userlinks)
#
# Tests add / list / delete of bidirectional record-to-record
# manual links between crud_test records T001 and T002.
# ════════════════════════════════════════════════════════════════════


# ── 4e-a. No manual links at start ───────────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.manualLinks" isCollection


# ── 4e-b. Add manual link T001 ↔ T002 ────────────────────────────────
POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb_one": "crud_test",
  "id_one": {{us_id_1}},
  "tb_two": "crud_test",
  "id_two": {{us_id_2}}
}
HTTP 200
[Asserts]
jsonpath "$.status"       == "success"
jsonpath "$.code"         == "all_links_saved"
jsonpath "$.link.key"     isInteger
jsonpath "$.link.tb_id"   == "crud_test"
jsonpath "$.link.ref_id"  == {{us_id_2}}
[Captures]
manual_link_id: jsonpath "$.link.key"


# ── 4e-c. Read T001 — manual link appears ────────────────────────────
GET {{base_url}}/api/record/crud_test/{{us_id_1}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.manualLinks" isCollection


# ── 4e-d. Add duplicate link → error ─────────────────────────────────
POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb_one": "crud_test",
  "id_one": {{us_id_1}},
  "tb_two": "crud_test",
  "id_two": {{us_id_2}}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "link_already_exists"


# ── 4e-e. Add reverse duplicate → also blocked ───────────────────────
POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb_one": "crud_test",
  "id_one": {{us_id_2}},
  "tb_two": "crud_test",
  "id_two": {{us_id_1}}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "link_already_exists"


# ── 4e-neg. Missing parameters ────────────────────────────────────────
POST {{base_url}}/api/manual-link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "tb_one": "crud_test", "id_one": {{us_id_1}} }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 4e-f. Delete the manual link ─────────────────────────────────────
DELETE {{base_url}}/api/manual-link/{{manual_link_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_userlink_erased"


# ── 4e-g. Delete non-existent link → not_found ───────────────────────
DELETE {{base_url}}/api/manual-link/{{manual_link_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_found"
