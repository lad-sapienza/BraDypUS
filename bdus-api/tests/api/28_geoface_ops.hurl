# ════════════════════════════════════════════════════════════════════
# Phase 28 — Geoface feature CRUD operations
#
# Prerequisites: demo schema seeded (phase 19) so table "us" exists
# and has at least one record (record id 1).
#
# Routes exercised:
#   POST   /api/geoface/feature   insert a new geometry
#   PUT    /api/geoface/feature   update geometry WKT
#   DELETE /api/geoface/feature   delete a geometry
# ════════════════════════════════════════════════════════════════════


# ── 28a. Insert a point geometry for us record 1 ─────────────────────
POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb": "crud_test",
  "id":       1,
  "geometry": { "type": "Point", "coordinates": [12.48, 41.89] }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_insert_geodata"
jsonpath "$.geo_id" isInteger
[Captures]
geo_id: jsonpath "$.geo_id"


# ── 28b. Update geometry (geodata array format) ──────────────────────
PUT {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "geodata": [
    { "id": {{geo_id}}, "geometry": { "type": "Point", "coordinates": [12.50, 41.90] } }
  ]
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_update_geometry"


# ── 28c. GeoJSON for table reflects the new geometry ─────────────────
GET {{base_url}}/api/geoface?tb=crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.geojson.type"    == "FeatureCollection"
jsonpath "$.geojson.features" isCollection


# ── 28d. Delete the geometry (ids array format) ──────────────────────
DELETE {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "ids": [{{geo_id}}] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_delete_geodata"


# ── 28e. Insert without tb → parameter_missing ───────────────────────
POST {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "id": 1, "geometry": { "type": "Point", "coordinates": [0, 0] } }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"


# ── 28f. Update with empty geodata array → parameter_missing ─────────
PUT {{base_url}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "geodata": [] }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "parameter_missing"
