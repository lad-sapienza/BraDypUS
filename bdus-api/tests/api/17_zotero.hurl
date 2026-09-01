# ════════════════════════════════════════════════════════════════════
# Phase 17 — Zotero library management & citation links
#
# Tests all non-network endpoints.  The search and sync routes that
# call the live Zotero API are covered only for their error-handling
# paths (expected in CI where no API key / network is available).
# ════════════════════════════════════════════════════════════════════

# ── 17a. List libs when empty ────────────────────────────────────────
GET {{base_url}}/api/zotero/libs
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.libs"   isCollection
jsonpath "$.libs"   isEmpty

# ── 17b. Add a Zotero library (public group, no API key) ─────────────
POST {{base_url}}/api/zotero/lib
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "type":           "group",
  "zotero_id":      "9999999",
  "name":           "Test Archaeology Group",
  "citation_style": "chicago-author-date"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_lib_added"
jsonpath "$.id"     isInteger
[Captures]
zotero_lib_id: jsonpath "$.id"

# ── 17c. Library appears in list; api_key is redacted ────────────────
GET {{base_url}}/api/zotero/libs
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"               == "success"
jsonpath "$.libs"                 count == 1
jsonpath "$.libs[0].name"         == "Test Archaeology Group"
jsonpath "$.libs[0].type"         == "group"
jsonpath "$.libs[0].zotero_id"    == "9999999"
jsonpath "$.libs[0].citation_style" == "chicago-author-date"
jsonpath "$.libs[0].has_api_key"  == false
# api_key must never appear in the response
jsonpath "$.libs[0].api_key" not exists

# ── 17d. Missing required params returns error ────────────────────────
POST {{base_url}}/api/zotero/lib
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "type": "group" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"

# ── 17e. Get links for a record (empty) ──────────────────────────────
GET {{base_url}}/api/zotero/links/{{app_name}}_us/1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.links"  isEmpty

# ── 17f. Add a link (citation fetch fails gracefully without network) ─
POST {{base_url}}/api/zotero/link
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb":         "{{app_name}}_us",
  "record_id":  1,
  "lib_id":     {{zotero_lib_id}},
  "zotero_key": "ABCD1234",
  "pages":      "10-15",
  "notes":      "See chapter 3",
  "sort":       0
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_link_added"
jsonpath "$.id"     isInteger
[Captures]
zotero_link_id: jsonpath "$.id"

# ── 17g. Link appears when listing for that record ────────────────────
GET {{base_url}}/api/zotero/links/{{app_name}}_us/1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.links"           count == 1
jsonpath "$.links[0].zotero_key" == "ABCD1234"
jsonpath "$.links[0].pages"  == "10-15"
jsonpath "$.links[0].notes"  == "See chapter 3"
# Group libraries must have a public Zotero URL
jsonpath "$.links[0].zotero_url" contains "zotero.org"

# ── 17h. Edit link metadata (pages / notes / sort) ───────────────────
PATCH {{base_url}}/api/zotero/link/{{zotero_link_id}}
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "pages": "12-14", "notes": "Updated note" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_link_updated"

# ── 17i. Changes persisted ────────────────────────────────────────────
GET {{base_url}}/api/zotero/links/{{app_name}}_us/1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.links[0].pages"  == "12-14"
jsonpath "$.links[0].notes"  == "Updated note"

# ── 17j. Sync record (no network → 0 updated, graceful) ──────────────
POST {{base_url}}/api/zotero/sync/{{app_name}}_us/1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.updated"  isInteger
jsonpath "$.detached" isInteger

# ── 17k. Search — parameter validation ───────────────────────────────
GET {{base_url}}/api/zotero/search
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"

# ── 17l. Search — unknown lib ─────────────────────────────────────────
GET {{base_url}}/api/zotero/search?lib_id=99999&q=pompeii
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "lib_not_found"

# ── 17m. Delete link ──────────────────────────────────────────────────
DELETE {{base_url}}/api/zotero/link/{{zotero_link_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_link_deleted"

# ── 17n. Link gone after delete ───────────────────────────────────────
GET {{base_url}}/api/zotero/links/{{app_name}}_us/1
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.links"  isEmpty

# ── 17o. Delete library ───────────────────────────────────────────────
DELETE {{base_url}}/api/zotero/lib/{{zotero_lib_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_lib_deleted"

# ── 17p. Library gone after delete ────────────────────────────────────
GET {{base_url}}/api/zotero/libs
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.libs"   isEmpty

# ── 17q. Sync-all — no libs → 0 items, graceful ──────────────────────
POST {{base_url}}/api/zotero/sync
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.total"    == 0
jsonpath "$.updated"  == 0
jsonpath "$.detached" == 0
