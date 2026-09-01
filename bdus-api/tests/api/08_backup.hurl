# ════════════════════════════════════════════════════════════════════
# Phase 8 — Backup
# ════════════════════════════════════════════════════════════════════

# ── 8a. List backups (empty at start) ───────────────────────────────
GET {{base_url}}/api/backups
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.backups" count == 0


# ── 8b. Create a backup ─────────────────────────────────────────────
POST {{base_url}}/api/backups
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "ok_backup"


# ── 8c. List backups — should have 1 now ────────────────────────────
GET {{base_url}}/api/backups
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status"     == "success"
jsonpath "$.backups"    count == 1
jsonpath "$.backups[0].file" isString
[Captures]
backup_file: jsonpath "$.backups[0].file"


# ── 8d. Delete the backup ────────────────────────────────────────────
DELETE {{base_url}}/api/backup/{{backup_file}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 8e. Verify backup list is empty again ───────────────────────────
GET {{base_url}}/api/backups
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.backups" count == 0
