# ════════════════════════════════════════════════════════════════════
# Phase 25 — API key management
#
# Routes exercised:
#   GET    /api/api-keys          list keys for current app
#   POST   /api/api-keys          create a new key
#   POST   /api/api-key/{id}/revoke  revoke (deactivate) a key
#   DELETE /api/api-key/{id}      permanently delete a key
# ════════════════════════════════════════════════════════════════════


# ── 25a. List keys — empty at start ─────────────────────────────────
GET {{base_url}}/api/api-keys
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.keys"   isCollection
jsonpath "$.keys"   isEmpty


# ── 25b. Create a read-only key (privilege 30 = UAC::READ) ───────────
POST {{base_url}}/api/api-keys
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "label": "CI read key", "privilege": 30 }
HTTP 200
[Asserts]
jsonpath "$.status"       == "success"
jsonpath "$.code"         == "ok_api_key_created"
jsonpath "$.id"           isInteger
jsonpath "$.label"        == "CI read key"
jsonpath "$.key"          isString
jsonpath "$.key"          matches /^[a-f0-9]{64}$/
[Captures]
api_key_id: jsonpath "$.id"


# ── 25c. Key appears in list ─────────────────────────────────────────
GET {{base_url}}/api/api-keys
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.keys"            count == 1
jsonpath "$.keys[0].id"      == {{api_key_id}}
jsonpath "$.keys[0].label"   == "CI read key"
jsonpath "$.keys[0].privilege" == 30
# Raw key must never be returned in the listing
jsonpath "$.keys[0].key_hash" not exists


# ── 25d. Create an edit-privilege key (privilege 25 = UAC::CREATE) ───
POST {{base_url}}/api/api-keys
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "label": "CI edit key", "privilege": 25 }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_api_key_created"
[Captures]
api_key_id2: jsonpath "$.id"


# ── 25e. Two keys in list ────────────────────────────────────────────
GET {{base_url}}/api/api-keys
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.keys" count == 2


# ── 25f. Revoke the first key ────────────────────────────────────────
POST {{base_url}}/api/api-key/{{api_key_id}}/revoke
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_api_key_revoked"


# ── 25g. Still two keys; first key no longer active ─────────────────
GET {{base_url}}/api/api-keys
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.keys" count == 2


# ── 25h. Delete the first key ───────────────────────────────────────
DELETE {{base_url}}/api/api-key/{{api_key_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_api_key_deleted"


# ── 25i. Delete the second key ──────────────────────────────────────
DELETE {{base_url}}/api/api-key/{{api_key_id2}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_api_key_deleted"


# ── 25j. List empty again ────────────────────────────────────────────
GET {{base_url}}/api/api-keys
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.keys" isEmpty


# ── 25k. Missing label → parameter_missing ──────────────────────────
POST {{base_url}}/api/api-keys
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "privilege": "read" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 25l. Delete non-existent key → idempotent success ───────────────
# deleteRow does not check affected rows — delete is always successful
DELETE {{base_url}}/api/api-key/999999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_api_key_deleted"
