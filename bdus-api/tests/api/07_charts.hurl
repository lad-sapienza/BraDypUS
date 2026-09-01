# ════════════════════════════════════════════════════════════════════
# Phase 7 — Charts
# ════════════════════════════════════════════════════════════════════

# ── 7a. List charts (empty) ──────────────────────────────────────────
GET {{base_url}}/api/charts
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 7b. Save a count chart: US per periodo ──────────────────────────
POST {{base_url}}/api/charts
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name": "US per periodo",
  "definition": {
    "tb": "crud_test",
    "type":       "pie",
    "x_field":    "periodo",
    "y_field":    "id",
    "y_function": "COUNT"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status"    == "success"
jsonpath "$.code"      == "ok_save_chart"
jsonpath "$.chart.id"  isInteger
[Captures]
chart_id: jsonpath "$.chart.id"


# ── 7c. Fetch chart data ─────────────────────────────────────────────
POST {{base_url}}/api/chart/data
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "definition": {
    "tb": "crud_test",
    "type":       "pie",
    "x_field":    "periodo",
    "y_field":    "id",
    "y_function": "COUNT"
  }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.data"   isCollection


# ── 7d. List charts — should have 1 now ─────────────────────────────
GET {{base_url}}/api/charts
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.charts"  count == 1


# ── 7e. Share the chart with all users ──────────────────────────────
POST {{base_url}}/api/chart/{{chart_id}}/share
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_sharing_chart"


# ── 7f. Shared chart appears in list with is_global flag ────────────
GET {{base_url}}/api/charts
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.charts[0].is_global" == 1


# ── 7g. Unshare the chart ────────────────────────────────────────────
POST {{base_url}}/api/chart/{{chart_id}}/unshare
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_unsharing_chart"


# ── 7h. Delete the chart ─────────────────────────────────────────────
DELETE {{base_url}}/api/chart/{{chart_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_chart_erase"


# ── 7i. List charts — should be empty again ─────────────────────────
GET {{base_url}}/api/charts
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.charts" count == 0
