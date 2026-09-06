# ════════════════════════════════════════════════════════════════════
# Phase 41 — Error responses are always JSON
#
# Regression guard for the API contract: every failure path must return
# a JSON envelope ({ status:'error', code, message }) with an
# application/json content type — never a raw text/HTML error page.
#
# Covers the reachable-over-HTTP error classes. The last-resort net for
# uncatchable failures (die()/exit(), true fatals) lives in index.php
# (register_shutdown_function + _bdus_emit_error) and cannot be triggered
# from here without a crash endpoint.
# ════════════════════════════════════════════════════════════════════


# ── 41a. Unknown route, no auth → JSON 401 'unauthenticated' ───────────
GET {{base_url}}/{{app_name}}/api/this-route-does-not-exist
HTTP 401
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.status"  == "error"
jsonpath "$.code"    == "unauthenticated"


# ── 41b. Unknown route, valid JWT → JSON 'dispatch_error' + message ────
GET {{base_url}}/{{app_name}}/api/this-route-does-not-exist
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.status"  == "error"
jsonpath "$.code"    == "dispatch_error"
jsonpath "$.message" isString
jsonpath "$.message" != ""


# ── 41c. Wrong HTTP method on a real route → JSON 405 ─────────────────
DELETE {{base_url}}/api/auth/apps
HTTP 405
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.status"  == "error"
jsonpath "$.code"    == "method_not_allowed"
jsonpath "$.message" isString


# ── 41d. Malformed request body → still a JSON error envelope (not raw text) ──
# The unparseable body leaves email/password unset; the endpoint must still
# answer with JSON. The exact code is not important here (email_password_needed
# or generic_error depending on how far the handler gets) — only that it is a
# structured error, never an HTML/text error page.
POST {{base_url}}/{{app_name}}/api/auth/login
Content-Type: application/json
```
not-valid-json
```
HTTP 200
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.status" == "error"
jsonpath "$.code"   isString
jsonpath "$.code"   != ""


# ── 41e. Garbage legacy obj/method on a non-REST path → JSON 'dispatch_error' ──
GET {{base_url}}/not-an-api-path?obj=Bogus%5CController&method=nope
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
header "Content-Type" contains "json"
jsonpath "$.status" == "error"
jsonpath "$.code"   == "dispatch_error"
