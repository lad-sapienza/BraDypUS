# ════════════════════════════════════════════════════════════════════
# Phase 43 — Pleiades gazetteer-linking plugin (end-to-end)
#
# Tests the full lifecycle:
#   activate → save a record with pleiades_id/label/alt_label → attach a
#   reprPoint geometry via the EXISTING Geoface endpoints (POST/PUT/DELETE
#   /api/geoface/feature — Pleiades introduces no new geometry endpoint,
#   see feature_pleiades_plugin_design.md) → remove the link (fields
#   nulled + geometry erased, "pulizia totale") → delete a record with an
#   active link (Record::erase()'s Pleiades-only cascade hook must clean
#   up its bdus_geodata row too) → deactivate (data preserved) → reactivate.
#
# Deliberately network-free: privilege enforcement AND a real live call to
# pleiades.stoa.org are both covered elsewhere (PleiadesCtrlTest and
# PleiadesClientTest, PHPUnit) rather than here, so this phase stays
# deterministic in CI — same reasoning as why Zotero's one real network
# call lives only in the opt-in demo seed (19_seed_demo.hurl), not in the
# default --tests suite. Everything below drives the backend contract
# directly, exactly as PleiadesSection.vue/RecordView.vue's save
# orchestration would (search/getPlace results are simulated by writing
# pleiades_id/label/reprPoint straight into the record-save payload).
#
# Depends on: phase 03 (crud_test table created), phase 02 (jwt captured).
# ════════════════════════════════════════════════════════════════════


# ── 43a. Table config shows pleiades=false before activation ─────────
GET {{base_url}}/{{app_name}}/api/config/table/crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.table.pleiades" not exists


# ── 43b-neg. Unknown table returns error ───────────────────────────────
POST {{base_url}}/{{app_name}}/api/config/table/__no_such_table__/pleiades
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"


# ── 43c. Activate plugin on crud_test ───────────────────────────────────
POST {{base_url}}/{{app_name}}/api/config/table/crud_test/pleiades
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "pleiades_activated"


# ── 43d. Activation is idempotent ───────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/config/table/crud_test/pleiades
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 43e. Save a record with pleiades_id/label/alt_label ─────────────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": null,
  "core": {
    "sigla":              "US430",
    "pleiades_id":         423025,
    "pleiades_label":      "Roma",
    "pleiades_alt_label":  "Rome (custom)"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.id"     isInteger
[Captures]
pleiades_record_id: jsonpath "$.id"


# ── 43f. Record schema shows has_pleiades=true; fields round-trip ───────
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{pleiades_record_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.schema.has_pleiades"         == true
jsonpath "$.core.pleiades_id.val"        == 423025
jsonpath "$.core.pleiades_label.val"     == "Roma"
jsonpath "$.core.pleiades_alt_label.val" == "Rome (custom)"
jsonpath "$.core.pleiades_geo_id.val"    == null


# ── 43g. Attach the reprPoint geometry — mirrors PleiadesSection.vue's
# post-save follow-up call (same endpoint phase 28 already exercises) ────
POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb": "crud_test",
  "id": {{pleiades_record_id}},
  "geometry": { "type": "Point", "coordinates": [12.491258174554883, 41.8899769864849] }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.geo_id" isInteger
[Captures]
pleiades_geo_id: jsonpath "$.geo_id"


# ── 43h. Persist the bookkeeping geo_id back onto the record — the
# second follow-up save RecordView.vue's savePleiadesGeometry() makes ────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{pleiades_record_id}},
  "core": { "pleiades_geo_id": {{pleiades_geo_id}} },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 43i. geo_id persisted; geometry present in the record's own geodata
# (per-record, via Record\Read::getGeodata() — unaffected by geometries on
# other crud_test records, e.g. the permanent IMP001 fixture from phase 12) ─
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{pleiades_record_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.pleiades_geo_id.val"    == {{pleiades_geo_id}}
jsonpath "$.geodata['{{pleiades_geo_id}}']" exists


# ── 43j. Remove the link: erase the geometry, then null all four
# pleiades_* columns — "pulizia totale" ──────────────────────────────────
DELETE {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "ids": [{{pleiades_geo_id}}] }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{pleiades_record_id}},
  "core": {
    "pleiades_id":        null,
    "pleiades_label":     null,
    "pleiades_alt_label": null,
    "pleiades_geo_id":    null
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 43k. Verify total cleanup: fields null, geometry gone ───────────────
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{pleiades_record_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.pleiades_id.val"        == null
jsonpath "$.core.pleiades_label.val"     == null
jsonpath "$.core.pleiades_alt_label.val" == null
jsonpath "$.core.pleiades_geo_id.val"    == null
jsonpath "$.geodata"                     count == 0


# ── 43l. Whole-record delete with an active Pleiades link: the
# erase()-hook cascade must clean up bdus_geodata too (Pleiades' own fix
# for its own bookkeeping column — not a general system-wide cascade,
# see feature_pleiades_plugin_design.md) ─────────────────────────────────
POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": null,
  "core": {
    "sigla":          "US431",
    "pleiades_id":     423025,
    "pleiades_label":  "Roma"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
pleiades_record_id_2: jsonpath "$.id"

POST {{base_url}}/{{app_name}}/api/geoface/feature
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "tb": "crud_test",
  "id": {{pleiades_record_id_2}},
  "geometry": { "type": "Point", "coordinates": [12.5, 41.9] }
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
pleiades_geo_id_2: jsonpath "$.geo_id"

POST {{base_url}}/{{app_name}}/api/record/crud_test
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{pleiades_record_id_2}},
  "core": { "pleiades_geo_id": {{pleiades_geo_id_2}} },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/record/crud_test/{{pleiades_record_id_2}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.geodata['{{pleiades_geo_id_2}}']" exists

DELETE {{base_url}}/{{app_name}}/api/record/crud_test/{{pleiades_record_id_2}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# The record itself is gone, so its own /api/record/.../geodata is no
# longer reachable — check the raw bdus_geodata row is gone via the
# table-wide Geoface feed instead, filtering to this specific geo_id (the
# only other permanent geometry on crud_test, from phase 12's IMP001
# fixture, has a different, unrelated geo_id).
GET {{base_url}}/{{app_name}}/api/geoface?tb=crud_test
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.geojson.features[?(@.properties.geo_id=={{pleiades_geo_id_2}})]" not exists


# ── 43m. Deactivate: config flag cleared, DB columns preserved ──────────
DELETE {{base_url}}/{{app_name}}/api/config/table/crud_test/pleiades
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "pleiades_deactivated"


# ── 43n. Schema shows has_pleiades=false after deactivation ─────────────
GET {{base_url}}/{{app_name}}/api/record/crud_test/{{pleiades_record_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.schema.has_pleiades" == false


# ── 43o. Re-activate: works again ────────────────────────────────────────
POST {{base_url}}/{{app_name}}/api/config/table/crud_test/pleiades
Authorization: Bearer {{jwt}}
Content-Type: application/json
{}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

GET {{base_url}}/{{app_name}}/api/record/crud_test/{{pleiades_record_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.schema.has_pleiades" == true
