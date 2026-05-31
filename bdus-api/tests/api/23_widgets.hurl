# ════════════════════════════════════════════════════════════════════
# Phase 23 — Widget endpoints
#
# Widgets are per-app vanilla ES modules stored at
# projects/{app}/widgets/{name}.js.
# The demo app has no widgets directory by default, so most tests
# verify the empty-list / not-found behaviour.
#
# Routes exercised:
#   GET /api/widgets            list available widget names
#   GET /api/widget/{name}      serve a widget JS file
# ════════════════════════════════════════════════════════════════════


# ── 23a. List widgets — no widgets directory → empty list ─────────────
GET {{base_url}}/api/widgets
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.widgets" isCollection
jsonpath "$.widgets" isEmpty


# ── 23b. Serve non-existent widget → 404 ─────────────────────────────
GET {{base_url}}/api/widget/non-existent-widget
Authorization: Bearer {{jwt}}
HTTP 404
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "widget_not_found"


# ── 23c. Invalid widget name with underscore → 400 ───────────────────
# Only [a-z0-9-] is valid; underscores, dots, uppercase are rejected.
GET {{base_url}}/api/widget/my_widget
Authorization: Bearer {{jwt}}
HTTP 400
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_widget_name"


# ── 23d. Invalid name with uppercase → 400 ───────────────────────────
GET {{base_url}}/api/widget/MyWidget
Authorization: Bearer {{jwt}}
HTTP 400
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "invalid_widget_name"


# ── 23e. Unauthenticated request → 401 ───────────────────────────────
GET {{base_url}}/api/widgets
HTTP 401
