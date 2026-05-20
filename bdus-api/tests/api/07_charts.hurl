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
    "tb":         "us",
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
    "tb":         "us",
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


# ── 7e. Delete the chart ─────────────────────────────────────────────
DELETE {{base_url}}/api/chart/{{chart_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_chart_erase"


# ── 7f. List charts — should be empty again ─────────────────────────
GET {{base_url}}/api/charts
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.charts" count == 0
