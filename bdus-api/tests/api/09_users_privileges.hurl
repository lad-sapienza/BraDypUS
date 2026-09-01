# ════════════════════════════════════════════════════════════════════
# Phase 9 — Users and privilege enforcement
#
# Verifies role-based access control end-to-end:
#
#   Reader  (privilege = 30) — may only read data
#   Writer  (privilege = 20) — may read and write data, not admin
#
# For each role the suite checks:
#   • Operations that MUST succeed (correct privilege)
#   • Operations that MUST be rejected (not_enough_privilege)
#   • Privilege escalation attempts (should always be rejected)
#
# Requires: tables and records created in phases 3–4 are still present.
# ════════════════════════════════════════════════════════════════════

# ── 9a. Admin creates a reader user (privilege = 30) ────────────────
POST {{base_url}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "Reader User",
  "email":     "reader@bdus.test",
  "password":  "Reader_2025!",
  "privilege": 30
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "user_data_saved"
jsonpath "$.id"     isInteger
[Captures]
reader_id: jsonpath "$.id"


# ── 9b. Admin creates a writer user (privilege = 20) ────────────────
POST {{base_url}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "Writer User",
  "email":     "writer@bdus.test",
  "password":  "Writer_2025!",
  "privilege": 20
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "user_data_saved"
jsonpath "$.id"     isInteger
[Captures]
writer_id: jsonpath "$.id"


# ════════════════════════════════════════════════════════════════════
# Reader (privilege = 30)
# ════════════════════════════════════════════════════════════════════

# ── 9c. Reader logs in → capture JWT ────────────────────────────────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "reader@bdus.test",
  "password": "Reader_2025!",
  "app":      "{{app_name}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.token"  isString
[Captures]
reader_jwt: jsonpath "$.token"


# ── 9d. Reader CAN list records (read allowed) ───────────────────────
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{reader_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 9e. Reader CAN read a single record ─────────────────────────────
GET {{base_url}}/api/record/crud_test/1
Authorization: Bearer {{reader_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 9f. Reader CANNOT insert a record ───────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{reader_jwt}}
Content-Type: application/json
{ "sigla": "UNAUTH" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9g. Reader CANNOT delete a record ───────────────────────────────
DELETE {{base_url}}/api/record/crud_test/1
Authorization: Bearer {{reader_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9h. Reader CANNOT save a chart ──────────────────────────────────
POST {{base_url}}/api/charts
Authorization: Bearer {{reader_jwt}}
Content-Type: application/json
{ "name": "test", "definition": { "tb": "crud_test", "type": "pie", "x_field": "sigla", "y_field": "id", "y_function": "COUNT" } }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9i. Reader CANNOT access app configuration ──────────────────────
GET {{base_url}}/api/config/tables
Authorization: Bearer {{reader_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9j. Reader CANNOT create a new user ─────────────────────────────
POST {{base_url}}/api/user
Authorization: Bearer {{reader_jwt}}
Content-Type: application/json
{
  "name":      "Rogue",
  "email":     "rogue@bdus.test",
  "password":  "Rogue_2025!",
  "privilege": 10
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9k. Privilege escalation — reader tries to promote self ─────────
# Sends own id with privilege=1 (super_admin). Must be rejected.
POST {{base_url}}/api/user
Authorization: Bearer {{reader_jwt}}
Content-Type: application/json
{
  "id":        {{reader_id}},
  "name":      "Reader User",
  "email":     "reader@bdus.test",
  "privilege": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9k-neg. Privilege escalation — reader tries to edit another user ─
POST {{base_url}}/api/user
Authorization: Bearer {{reader_jwt}}
Content-Type: application/json
{
  "id":        {{writer_id}},
  "name":      "I took over",
  "email":     "writer@bdus.test",
  "privilege": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ════════════════════════════════════════════════════════════════════
# Writer (privilege = 20)
# ════════════════════════════════════════════════════════════════════

# ── 9l. Writer logs in → capture JWT ────────────────────────────────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "writer@bdus.test",
  "password": "Writer_2025!",
  "app":      "{{app_name}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
writer_jwt: jsonpath "$.token"


# ── 9m. Writer CAN list records ─────────────────────────────────────
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{writer_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 9n. Writer CAN insert a record ──────────────────────────────────
POST {{base_url}}/api/record/crud_test
Authorization: Bearer {{writer_jwt}}
Content-Type: application/json
{ "sigla": "W999", "descrizione": "Inserted by writer" }
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
writer_record_id: jsonpath "$.id"


# ── 9o. Writer CAN delete their own record ──────────────────────────
DELETE {{base_url}}/api/record/crud_test/{{writer_record_id}}
Authorization: Bearer {{writer_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 9p. Writer CANNOT access app configuration ──────────────────────
GET {{base_url}}/api/config/tables
Authorization: Bearer {{writer_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9q. Writer CANNOT create a new user ─────────────────────────────
POST {{base_url}}/api/user
Authorization: Bearer {{writer_jwt}}
Content-Type: application/json
{
  "name":      "Rogue",
  "email":     "rogue@bdus.test",
  "password":  "Rogue_2025!",
  "privilege": 10
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9r. Privilege escalation — writer tries to promote self ─────────
POST {{base_url}}/api/user
Authorization: Bearer {{writer_jwt}}
Content-Type: application/json
{
  "id":        {{writer_id}},
  "name":      "Writer User",
  "email":     "writer@bdus.test",
  "privilege": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9s. Admin creates an intermediate admin (privilege = 10) ─────────
POST {{base_url}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "name":      "Mid Admin",
  "email":     "midadmin@bdus.test",
  "password":  "MidAdmin_2025!",
  "privilege": 10
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.id"     isInteger
[Captures]
midadmin_id: jsonpath "$.id"


# ── 9s-login. Mid-admin logs in ──────────────────────────────────────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "midadmin@bdus.test",
  "password": "MidAdmin_2025!",
  "app":      "{{app_name}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
midadmin_jwt: jsonpath "$.token"


# ── 9s-esc. Mid-admin CANNOT escalate writer to super_admin (1 < 10) ─
POST {{base_url}}/api/user
Authorization: Bearer {{midadmin_jwt}}
Content-Type: application/json
{
  "id":        {{writer_id}},
  "name":      "Writer User",
  "email":     "writer@bdus.test",
  "privilege": 1
}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9s-self. Writer CAN update own name (non-sensitive self-edit) ────
POST {{base_url}}/api/user
Authorization: Bearer {{writer_jwt}}
Content-Type: application/json
{
  "id":    {{writer_id}},
  "name":  "Writer User (updated)",
  "email": "writer@bdus.test"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ════════════════════════════════════════════════════════════════════
# Mid-admin (privilege = 10): config and free-sql are super_admin-only
# ════════════════════════════════════════════════════════════════════

# ── 9t-cfg. Mid-admin CANNOT list config tables (super_admin only) ───
GET {{base_url}}/api/config/tables
Authorization: Bearer {{midadmin_jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9t-cfg2. Mid-admin CANNOT create a config table ─────────────────
POST {{base_url}}/api/config/tables
Authorization: Bearer {{midadmin_jwt}}
Content-Type: application/json
{ "name": "forbidden", "label": "Forbidden", "is_plugin": "0" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ── 9t-sql. Mid-admin CANNOT run free SQL (super_admin only) ─────────
POST {{base_url}}/api/free-sql/run
Authorization: Bearer {{midadmin_jwt}}
Content-Type: application/json
{ "sql": "SELECT 1" }
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "not_enough_privilege"


# ════════════════════════════════════════════════════════════════════
# Token revocation
# ════════════════════════════════════════════════════════════════════

# ── 9s-rev1. Admin revokes reader's session ──────────────────────────
POST {{base_url}}/api/user/{{reader_id}}/revoke
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
jsonpath "$.code"   == "token_revoked"


# ── 9s-rev2. Reader's old JWT is now rejected (401) ──────────────────
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{reader_jwt}}
HTTP 401


# ── 9s-rev3. Reader logs in again → gets a fresh JWT ────────────────
POST {{base_url}}/api/auth/login
Content-Type: application/json
{
  "email":    "reader@bdus.test",
  "password": "Reader_2025!",
  "app":      "{{app_name}}"
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
[Captures]
reader_jwt_new: jsonpath "$.token"


# ── 9s-rev4. Reader's new JWT works correctly ────────────────────────
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{reader_jwt_new}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 9s-rev5. Admin changes writer's privilege (auto-revoke) ─────────
POST {{base_url}}/api/user
Authorization: Bearer {{jwt}}
Content-Type: application/json
{
  "id":        {{writer_id}},
  "name":      "Writer User",
  "email":     "writer@bdus.test",
  "privilege": 30
}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 9s-rev6. Writer's old JWT is invalidated after privilege change ──
GET {{base_url}}/api/records/crud_test
Authorization: Bearer {{writer_jwt}}
HTTP 401


# ── 9s-rev7. Revoke non-existent user → user_not_found ───────────────
POST {{base_url}}/api/user/999999/revoke
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "error"
jsonpath "$.code"   == "user_not_found"


# ════════════════════════════════════════════════════════════════════
# Cleanup (as admin)
# ════════════════════════════════════════════════════════════════════

# ── 9t. Admin deletes the reader user ───────────────────────────────
DELETE {{base_url}}/api/user/{{reader_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 9u. Admin deletes the writer user ───────────────────────────────
DELETE {{base_url}}/api/user/{{writer_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"


# ── 9v. Admin deletes the mid-admin user ────────────────────────────
DELETE {{base_url}}/api/user/{{midadmin_id}}
Authorization: Bearer {{jwt}}
HTTP 200
[Asserts]
jsonpath "$.status" == "success"
