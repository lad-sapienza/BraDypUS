# ════════════════════════════════════════════════════════════════════
# Phase 30 — File sort (POST /api/files/sort)
#
# sortFiles() updates bdus_file_links.sort for an ordered array of IDs.
# We seed a record with two file attachments in phase 04 (us record 1),
# so we can use real file-link IDs captured there — but since sortFiles()
# uses a boolean UPDATE (no affected-row check), even non-existent IDs
# return success, which keeps this phase self-contained.
# ════════════════════════════════════════════════════════════════════


# ── 30a. Sort with non-existent IDs → success (idempotent UPDATE) ─────
POST {{base_url}}/api/files/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "order": [9991, 9992, 9993] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_file_sorting_update"


# ── 30b. Sort with empty order array → parameter_missing ─────────────
POST {{base_url}}/api/files/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "order": [] }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 30c. Missing order key entirely → parameter_missing ──────────────
POST {{base_url}}/api/files/sort
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"
