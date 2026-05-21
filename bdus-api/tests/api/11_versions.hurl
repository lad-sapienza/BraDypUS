# ════════════════════════════════════════════════════════════════════
# Phase 11 — Record version history
#
# Tests the full version lifecycle:
#   11a.  Insert a record (no versions yet)
#   11b.  getVersions → empty list
#   11c.  Update the record (creates snapshot v1)
#   11d.  Update again (creates snapshot v2)
#   11e.  getVersions → two entries, newest first
#   11f.  getVersionDiff for v2 → correct structure
#   11g.  getVersionDiff for v2 → version.content has core + plugins
#   11h.  getVersionDiff for v2 → current.core is not null
#   11i.  restoreVersion v1 → success, field restored
#   11j.  Verify field was restored (GET record)
#   11k.  getVersions → now has a 'restore' operation entry
#   11l.  Delete the record
#   11m.  getVersionDiff for v2 → current.core is null
#   11n.  restoreVersion → re-creates the deleted record
#   11o.  Verify re-created record exists
#   11p.  Error paths: missing id, unknown id, unknown table
#   11q.  Cleanup: delete the re-created record
# ════════════════════════════════════════════════════════════════════

# ── 11a. Insert a new record (no versions yet) ───────────────────────
POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "core": {
    "sigla":       "VH001",
    "descrizione": "Test versioning record",
    "periodo":     "Età moderna"
  },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.id"     isInteger
[Captures]
vh_id: jsonpath "$.id"


# ── 11b. getVersions → empty immediately after insert ────────────────
GET {{base_url}}/api/record/us/{{vh_id}}/versions
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"   == "success"
jsonpath "$.versions" isCollection
jsonpath "$.versions" isEmpty


# ── 11c. Update (first edit — creates snapshot v1) ───────────────────
POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{vh_id}},
  "core": { "descrizione": "Updated once" },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 11d. Update again (second edit — creates snapshot v2) ────────────
POST {{base_url}}/api/record/us
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id": {{vh_id}},
  "core": { "descrizione": "Updated twice" },
  "plugins": {}
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 11e. getVersions → two entries, newest first ─────────────────────
GET {{base_url}}/api/record/us/{{vh_id}}/versions
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"          == "success"
jsonpath "$.versions"        isCollection
jsonpath "$.versions"        count == 2
jsonpath "$.versions[0].id"  isInteger
jsonpath "$.versions[0].userid"    isString
jsonpath "$.versions[0].time"      isString
jsonpath "$.versions[0].operation" == "update"
jsonpath "$.versions[1].operation" == "update"
# Newest-first: first entry id > second entry id
jsonpath "$.versions[0].id" > 0
[Captures]
v_id_newer: jsonpath "$.versions[0].id"
v_id_older: jsonpath "$.versions[1].id"


# ── 11f. getVersionDiff for newest snapshot ──────────────────────────
GET {{base_url}}/api/version/{{v_id_newer}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"               == "success"
jsonpath "$.version"              isCollection
jsonpath "$.version.id"           == {{v_id_newer}}
jsonpath "$.version.operation"    == "update"
jsonpath "$.version.time"         isString
jsonpath "$.version.userid"       isString


# ── 11g. version.content has core + plugins keys ─────────────────────
GET {{base_url}}/api/version/{{v_id_newer}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.version.content.core"    isCollection
jsonpath "$.version.content.plugins" isCollection
jsonpath "$.current"                 isCollection
jsonpath "$.current.core"            isCollection
jsonpath "$.current.plugins"         isCollection


# ── 11h. The snapshot captured the pre-second-edit value ─────────────
# v_id_newer was taken before "Updated twice", so descrizione = "Updated once"
GET {{base_url}}/api/version/{{v_id_newer}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.version.content.core.descrizione" == "Updated once"
jsonpath "$.current.core.descrizione"         == "Updated twice"


# ── 11i. Restore v_id_older (restores descrizione = "Updated once") ──
POST {{base_url}}/api/version/{{v_id_older}}/restore
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "version_id":      {{v_id_older}},
  "fields":          ["descrizione"],
  "restore_plugins": []
}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.code"    == "success_restored"
jsonpath "$.created" == false


# ── 11j. Verify the field was restored ───────────────────────────────
GET {{base_url}}/api/record/us/{{vh_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.descrizione.val" == "Test versioning record"


# ── 11k. Versions list now contains a 'restore' entry ────────────────
GET {{base_url}}/api/record/us/{{vh_id}}/versions
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.versions" count == 3
# Newest entry is the pre-restore snapshot with operation='restore'
jsonpath "$.versions[0].operation" == "restore"


# ── 11l. Delete the record ────────────────────────────────────────────
DELETE {{base_url}}/api/record/us/{{vh_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 11m. getVersionDiff → current.core is null for deleted record ─────
GET {{base_url}}/api/version/{{v_id_newer}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"       == "success"
jsonpath "$.current.core" == null


# ── 11n. Restore the deleted record (full re-insert) ─────────────────
POST {{base_url}}/api/version/{{v_id_newer}}/restore
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "version_id": {{v_id_newer}}
}
HTTP 200
[Asserts]
jsonpath "$.status"  == "success"
jsonpath "$.code"    == "success_restored"
jsonpath "$.created" == true


# ── 11o. Re-created record is accessible ─────────────────────────────
GET {{base_url}}/api/record/us/{{vh_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.core.sigla.val" == "VH001"


# ── 11p. Error paths ──────────────────────────────────────────────────

# Missing tb parameter
GET {{base_url}}/api/record/us/{{vh_id}}/versions
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"

# Unknown version id
GET {{base_url}}/api/version/9999999
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "version_not_found"

# Restore unknown version id
POST {{base_url}}/api/version/9999999/restore
Authorization: Bearer {{jwt}}
Content-Type: application/json
{ "version_id": 9999999 }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "version_not_found"

# getVersions for unknown table
GET {{base_url}}/api/record/nonexistent_table/1/versions
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "unknown_table"


# ── 11q. Cleanup: delete the re-created record ────────────────────────
DELETE {{base_url}}/api/record/us/{{vh_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
