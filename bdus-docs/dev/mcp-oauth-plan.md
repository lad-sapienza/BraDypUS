---
title: MCP server & OAuth 2.1 — design plan
---

# MCP server & OAuth 2.1 authorization server — design plan

::: warning Status
Design document. Implemented so far: the prerequisite refactor (decision D1)
and most of phase 1 — protocol layer, settings (M052), the ten tools of
section 4, the validation token, provenance and `create` entries in the history
(M053). The read tools were checked with Claude Code on the demo application.
The settings page (system configuration → MCP) exists. Prompts (M054) are served and can be edited in the interface and through REST. The user guide (`guide/setup/mcp.md`), `llms.txt`, `evaluate.md` and the bootstrap skill are written.
Still missing in phase 1: a critical review of the whole phase, then the release. What exists is described in
[MCP server](./mcp). Everything else here is still a plan.
Written 2026-11-15 against v5.23.1. Statements about external projects (MCP
specification, Directus, PHP libraries) were checked on that date and must be
re-checked when each phase starts.
:::

## 1. Summary and recommendation

BraDypUS gets a **generic MCP server**, one endpoint per application, that lets
an AI client read the schema, query and aggregate records, and — after the user
confirms — create and update them. It is a route of `bdus-api`, speaks MCP over
stateless HTTP with a small hand-written JSON-RPC layer, and exposes **ten
generic tools parameterised by table**, never one tool per table.

Authentication comes in two steps that share one abstraction (`Auth\CurrentUser`):

1. **Phase 1 — existing API keys** in the `Authorization: Bearer` header.
   Read-only by default; a key has no user identity, so writes stay switched off
   until phase 3 unless the administrator turns them on.
2. **Definitive — an OAuth 2.1 authorization server inside BraDypUS**, per
   application, built on `league/oauth2-server`. A token stands for a **real
   user**: creator, versions, groups and row rules work without exceptions.

Everything the MCP server does goes through the same domain classes as the REST
API (`Record\Save`, `Record\Validator`, `SQL\QueryFromRequest`, `Auth\Access`):
no second permission model, no second validation.

Decisions taken by the project owner on 2026-11-15: D1 done, D2 route in
`bdus-api`, D3 hand-written layer (documented), D4 `league/oauth2-server`,
D5 no "hidden from MCP" list, D6 existing API keys in phase 1; the first
release is the **full cycle** (read and write), every insert leaves a row in the
history, the data import goes through `Record\Save`, writes need a validation
token, stratigraphic relations get a dedicated tool and geometries wait
(section 13).

## 2. Decisions

### D1. Move the write path out of the controller — done

- **Context.** Validation, the access gate and the whole create/update logic were
  private methods of `Bdus\Controllers\Record` (3,000 lines), bound to
  `$this->post`. No other entry point could reuse them.
- **Options.** (a) extract to `lib/`; (b) let the MCP layer build a fake request
  and call the controller.
- **Decision.** (a), as a pure move: `Record\Save`, `Record\Validator`,
  `Auth\WriteGate`, `Config\TableSchema`, `Record\FieldOptions`. The API is
  unchanged; PHPUnit and the hurl phases pass on the three engines.
- **Consequences.** The MCP tools call domain classes. (b) would have tied MCP
  to superglobals and to the HTTP-200 error envelope.

### D2. A route of `bdus-api`, not a separate container

- **Context.** The brief offered a `bdus-mcp` container built from the same image.
- **Options.** (a) route in `bdus-api`; (b) separate container, same image,
  different entry point.
- **Decision.** (a).
- **Why.** (b) shares the database, the code and `projects/`, so it isolates
  nothing; it adds a service to deploy, upgrade and monitor in every
  installation (`bradypus.yml`, `bdus-ops`, the proxy VM). What (b) would give —
  switching MCP off or limiting it — is a per-application setting and a rate
  limit. Decision criteria that would reverse this: a long-running process
  becomes necessary (server-initiated streams), or MCP load must be scaled apart
  from the API. Neither holds: see D3.
- **Consequences.** MCP ships with every image; a fatal error in a tool is an
  error of the API process, contained by the existing shutdown handler.

### D3. Hand-written JSON-RPC layer behind an interface

- **Context.** The official PHP SDK `mcp/sdk` (v0.10.0, 2026-11-10) declares
  itself experimental until 1.0 and depends on PSR-7/PSR-15/PSR-17, which
  BraDypUS does not use (controllers read `$_GET`/`$_POST`).
- **Options.** (a) own layer; (b) the SDK.
- **Decision.** (a): `Mcp\Server` (dispatch of `initialize`, `tools/list`,
  `tools/call`, `prompts/list`, `prompts/get`, `ping`), `Mcp\Tool` interface,
  one class per tool. The tools never see JSON-RPC.
- **Consequences.** We track the specification ourselves: the supported
  revisions are a constant with a test per revision, and the layer must be
  documented (this page becomes `dev/mcp.md` when phase 1 ships). Replacing the
  layer with the SDK at 1.0 touches `Mcp\Server` only. See doubt 14.

### D4. OAuth authorization server on `league/oauth2-server`

- **Context.** An authorization server is where a mistake costs most.
- **Options.** (a) own implementation with opaque tokens hashed in the database,
  like API keys; (b) `league/oauth2-server` (MIT) for the grants.
- **Decision.** (b) for authorization code + PKCE, refresh tokens with rotation
  and token validation. Metadata endpoints, Client ID Metadata Documents,
  dynamic registration, consent page and the resource-side audience check are
  ours.
- **Consequences.** New dependencies: the library, a PSR-7 implementation
  (used inside the OAuth controllers only, through a small adapter) and a key
  pair per application for signing access tokens (`projects/{app}/.oauth_private.key`,
  mode 0600, next to `.jwt_secret`). Access tokens are JWTs; revocation is
  still immediate because the resource checks the token id against
  `bdus_oauth_tokens` on every request, as the Dispatcher already re-reads
  `token_version` for the login JWT. To verify at implementation: current major
  version, PHP 8.4 support, exact transitive dependencies and their licences.

### D5. No "hidden from MCP" list

- **Context.** Layout-hidden fields are reachable through the API, search and
  export; files are public. MCP sends data to a model provider.
- **Options.** (a) nothing new: MCP shows what the principal may read; (b) a
  per-application list of fields not exposed to MCP; (c) field-level permissions.
- **Decision.** (a). Sensitive columns are kept out with a SQL view plus a group
  whose rules give access to the view and not to the table — the answer already
  given to field-level visibility (#56).
- **Why not (b).** It looks like a control and is not one: the same key reads the
  field from `GET /api/records/{tb}`.
- **Consequences.** The user guide for MCP opens with this. `schema` and
  `records_read` must never return a table or a row the principal cannot read
  through the REST API — tested, not assumed.

### D6. Phase 1 uses the existing API keys

- **Context.** A dedicated key type valid only on the MCP endpoint was considered.
- **Decision.** No new key type. Per-application switches decide what MCP may do.
- **Consequences.** A key used for MCP is also valid on the REST API (it has the
  same rights there anyway). A key has no user: `creator` is null and
  `bdus_versions.userid` is 0. For that reason **writes through MCP are off by
  default** and the guide recommends leaving them off until OAuth (phase 3).

### D7. Endpoint paths stay under `/{app}/api/`

- **Context.** The brief proposed `/{app}/mcp` and `/{app}/oauth`. The frontend
  nginx forwards to PHP only `^/[^/]+/api/` and `/api/`; everything else is the
  SPA. `Router::resolveRequest()` recognises the same shape.
- **Decision.** `POST /{app}/api/mcp`, and the authorization server at
  `/{app}/api/oauth/*` (issuer `https://host/{app}/api/oauth`).
- **Consequences.** No change to nginx, to the proxy VM or to the router regex
  for phase 1. The OAuth discovery documents are the exception: RFC 9728 and
  RFC 8414 put them at the root (`/.well-known/...`), so phase 3 adds one
  `location /.well-known/` to `nginx.conf.template` and to the manually managed
  proxy, and two app-independent routes.

### D8. Writes take ids, never human keys

- **Context.** The brief asks whether writes could address a record by its key
  field ("US 1023"), reusing the import upsert.
- **Decision.** `records_write` takes the numeric `id`. `resolve` turns a human
  reference into ids, and the model shows the match to the user before writing.
- **Why.** `id_field` is not guaranteed unique (only `no_dupl` enforces it). An
  upsert by key would silently update the wrong record or create a duplicate.
  The import upsert cannot be reused either: see doubt 6.

## 3. Architecture

```
MCP client ──HTTPS──► nginx ──► Apache/mod_php ──► index.php
                                                     │
                       Router: POST /{app}/api/mcp → Controllers\Mcp::handle
                                                     │
                       Dispatcher: principal (API key │ OAuth token) → CurrentUser
                                   rate limit, app status, MCP switches
                                                     │
                       Mcp\Server ── JSON-RPC ──► Mcp\Tool (one class per tool)
                                                     │
        Config\TableSchema · SQL\QueryFromRequest · SQL\Aggregate (new)
        Record\Read · Record\Validator · Record\Save · Auth\Access · Auth\WriteGate
```

**Transport.** One `POST` per JSON-RPC message, answered with
`application/json`; `GET` and `DELETE` answer 405; a notification answers 202.
No session, no SSE stream, no server-initiated message: the PHP request model
fits as it is. `Origin` is validated against `BRADYPUS_CORS_ORIGIN` when present.

**Specification revisions.** Target: `2026-07-28` (sessions and the GET stream
were removed, a stateless JSON server is the norm). Also accepted: `2025-11-25`
and `2025-06-18`, for clients that still open with an `initialize` handshake —
answered statelessly, never issuing `Mcp-Session-Id`. Supported features: tools
(with annotations and `structuredContent`), prompts. Not supported: resources,
sampling, elicitation, subscriptions.

**Errors.** The MCP endpoint does not use the HTTP-200 envelope of the REST API.
Transport and authentication failures use HTTP status codes (401 with
`WWW-Authenticate`, 403, 429 with `Retry-After`); protocol errors are JSON-RPC
errors; a tool that refuses (validation, permission) returns a normal result
with `isError: true` and the same `code` strings as the REST API
(`not_enough_privilege`, `validation_failed`, …), so the model can read and
correct. `Controllers\Mcp` therefore bypasses `returnJson()` and `index.php`
must not force status 200 for this route.

**Auth abstraction.** Tools receive nothing about authentication: they use
`Auth\CurrentUser`, `Auth\Authorization::can()` and `Auth\Access`, as the
controllers do. Today the bearer token is tried as a login JWT in
`bootstrap.php`, then as an API key in the Dispatcher. Phase 3 adds a third
resolver for OAuth access tokens, and the three move behind one
`Auth\Authenticator` that fills `CurrentUser` and records *how*
(`auth_method`: `jwt` | `api_key` | `oauth`, plus `key_id` / `client_id`) for
provenance and rate limiting only.

**Route privilege.** `Mcp::handle` is registered as `read` and added to
`GROUP_KEY_ROUTES`; each tool then applies the table and row checks itself and
the write tools require the write switch. A key of level 39 with a group can
therefore use MCP within its group's rules.

## 4. Tool specification

All tools: names in `snake_case`, input validated against a JSON Schema, output
as `structuredContent` plus a compact text rendering. Every tool that names a
table refuses tables the principal cannot read exactly as if they did not exist.

| Tool | Purpose | Annotations |
|---|---|---|
| `guide` | Conventions of this application: filter syntax, plugin rows, key fields, how to write safely; plus the administrator's custom text | readOnly |
| `schema` | No argument: readable tables (name, label, description, abilities, features). With `table`: fields, types, help, checks, value sources, plugin tables, relations, key field, view/writable | readOnly |
| `vocabulary` | Allowed values of one field (`table`, `field`, optional `search`, `limit`) | readOnly |
| `records_read` | `table`, `filter`, `fields`, `sort`, `search`, `limit`, `offset`; or `id` for one full record | readOnly |
| `aggregate` | `table`, `filter`, `group_by` (≤ 2 fields), `metrics` (`count`, `count_distinct`, `sum`, `avg`, `min`, `max` on a field) | readOnly |
| `resolve` | `table`, `values[]` → for each value the matching ids on the key field, with the preview fields | readOnly |
| `records_validate` | Dry run of a create or update: normalised payload, errors, diff against the stored record; no write | readOnly |
| `records_write` | Create or update, single or batch, with plugin rows | destructive (update), not idempotent |
| `records_delete` | Delete records by id | destructive |
| `history` | Versions of a record and the diff of one version | readOnly |

**Limits.**

| What | Default | Hard cap |
|---|---|---|
| `records_read.limit` | 25 | 200 |
| nested rows (`fields` with relations) | — | `Record\Expand::MAX_NESTED_ROWS` |
| `aggregate` groups returned | 200 | 1,000, with `truncated` |
| `vocabulary` values | 200 | 1,000, with `truncated` |
| `resolve.values` | — | 100 |
| `records_write` batch | — | 50 records |
| `records_delete` batch | — | 50 records |
| request body | — | 1 MB |
| geometry in a write | — | 256 kB of WKT/GeoJSON per record |

**Sketches.**

```jsonc
// records_read
{ "table": "us", "filter": { "site": { "_eq": "Castro" } },
  "fields": ["us_num", "definition", "finds.*"], "sort": ["-us_num"], "limit": 25 }
// → { "total": 312, "returned": 25, "truncated": true, "rows": [ … ] }

// aggregate
{ "table": "finds", "group_by": ["class"], "metrics": [{ "fn": "count" }],
  "filter": { "year": { "_eq": 2026 } } }
// → { "groups": [ { "class": "pottery", "count": 412 }, … ], "truncated": false }

// records_validate / records_write (same input)
{ "table": "us", "records": [
    { "id": null, "core": { "us_num": "1023", "site": 4 },
      "plugins": { "us_finds": [ { "fields": { "class": "pottery" } } ] } } ] }
// validate → { "results": [ { "index": 0, "ok": false,
//      "errors": [ { "field": "site", "rule": "fk_not_found" } ], "diff": null } ] }
// write    → { "status": "success", "ids": [ 8841 ] }
```

**`records_write` rules.** The whole batch is one transaction: any refusal or
error rolls everything back and the answer names the failing index — no partial
success, because a model cannot be trusted to reconcile one. Every record goes
through `Record\Save`. A call must carry the `validation_token` returned by a
`records_validate` of the same payload (hash of the payload, short-lived,
signed with the application secret): it does not prove the user agreed, but it
makes "validate, show, then write" the only path the model can take.

**`history`.** Read-only. Restoring a version stays an administrator action of
the REST API (`restoreVersion` is `admin`); MCP can show what to restore.

**Prompts.** MCP prompts are stored per application (section 7) and listed with
`prompts/list`: "end-of-season report", "context sheet entry". They are text
templates with arguments; they grant nothing.

## 5. Workflows

**Reporting.** `guide` (once) → `schema` → `schema(table)` for the tables
involved → `aggregate` for counts and breakdowns, `records_read` for samples and
cross-table detail (`fields` with relations) → the model writes the report. No
tool returns more than the caps above; a report over thousands of rows is built
from aggregates, not from row dumps.

**Assisted data entry.**

1. The user gives a description, field notes or a photo; the client's model
   reads it (the image never reaches BraDypUS).
2. `schema(table)` and `vocabulary` for the fields with a value source;
   `resolve` for references to other records ("US 1023", a site name).
3. The model drafts the payload and calls `records_validate`.
4. Errors come back per field with the rule (`required`, `fk_not_found`,
   `no_dupl`, `value_not_allowed`, …); the model corrects or asks the user.
5. The model shows the normalised payload and, for an update, the diff. **The
   user confirms in the client** — the tool carries `destructiveHint`, so
   clients ask by default.
6. `records_write` with the `validation_token`; the answer gives the ids.
7. If something is wrong afterwards: `history` shows the previous state; an
   administrator restores it.

**Files and photos.** MCP does not upload files in the phases planned here:
base64 in a tool call is slow and costly, and files are public. A record created
through MCP gets its photos from the web interface or the photo import.

## 6. Authentication and authorization

### Phase 1 — API keys

`Authorization: Bearer <key>` only; never the query string (Directus accepts
`?access_token=`, we do not). The existing rate limits apply (`KEY_READ`,
`KEY_WRITE`, `KEY_IP`, `BAD_KEY_IP`); `tools/call` of a write tool counts as a
write whatever the HTTP method. New limit `MCP_WRITE_ROWS` (records written per
key per hour, default 500).

### Definitive — authorization server

One issuer per application: `https://host/{app}/api/oauth`.

| Piece | Path / behaviour |
|---|---|
| Protected resource metadata (RFC 9728) | `/.well-known/oauth-protected-resource/{app}/api/mcp`; the 401 of the MCP endpoint carries `WWW-Authenticate: Bearer resource_metadata="…", scope="mcp:read"` |
| Authorization server metadata (RFC 8414) | `/.well-known/oauth-authorization-server/{app}/api/oauth` |
| Authorize | `GET /{app}/api/oauth/authorize` → redirects to the SPA route `/{app}/oauth/authorize`, which reuses the login (password, Google, ORCID with its no-email flow) and shows the consent page |
| Token | `POST /{app}/api/oauth/token` — `authorization_code` with PKCE (S256, mandatory) and `refresh_token` |
| Revocation (RFC 7009) | `POST /{app}/api/oauth/revoke` |
| Registration | Client ID Metadata Documents (preferred by the specification and by Claude clients); dynamic registration (RFC 7591, deprecated by the specification, needed by some clients) behind its own switch, off by default |

- **Scopes.** `mcp:read`, `mcp:write`, `mcp:delete`. A scope never gives more
  than the user's own rights and the application's MCP switches; it lets the
  user grant less. The consent page lists them in plain words.
- **Tokens.** Access token: 15 minutes, audience-bound to the MCP resource URL
  (RFC 8707; the `resource` parameter is required). Refresh token: 30 days,
  rotated at every use; reuse of a rotated token revokes the whole grant.
  Authorization code: 60 seconds, single use.
- **CIMD safety.** HTTPS only, no redirects followed, response ≤ 5 kB, timeout
  3 s, private and loopback addresses refused (SSRF), optional allow-list of
  domains — the limits Directus applies.
- **Redirect URIs.** Exact match. To verify at implementation: the callback of
  the Claude surfaces (`https://claude.ai/api/mcp/auth_callback` in the brief).
- **Who may authorise.** A user who can enter the application
  (`Authorization::can('enter')`, privilege ≤ 39). Privilege 40 ("waiting") and
  locked accounts are stopped at the consent page with the same message as at
  login. At every token request and at every MCP request the user's privilege
  and `token_version` are read from `bdus_users`: revoking a user's sessions or
  lowering their privilege takes effect at once.
- **Per user.** A page "Authorised applications" lists the grants (client name,
  scopes, first and last use) with a revoke button; administrators see and
  revoke all of them.

### Later clients on the same authorization server

- **Vue app.** Two options: public client with authorization code + PKCE, or
  keep the current login JWT. Recommendation: keep the JWT. The SPA is
  first-party and served from the same origin; moving it to OAuth changes the
  login of every installation and buys nothing until a second first-party
  client exists. The `Auth\Authenticator` abstraction makes the move possible
  later without touching controllers.
- **QGIS plugins and scripts.** Authorization code + PKCE with a loopback
  redirect (`http://127.0.0.1:{port}`), which `league/oauth2-server` supports;
  device authorization grant only if a headless case appears. API keys stay for
  unattended scripts.
- **Widget API.** Widgets run inside the SPA with its token: no change.

## 7. Data model changes and migrations

All through `DB\System\Manage` (structure JSON + migration), on the three engines.

| Migration | Phase | Change |
|---|---|---|
| M052 | 1 | `bdus_cfg_app`: `mcp_enabled`, `mcp_write`, `mcp_delete` (INTEGER, default 0), `mcp_guide` (TEXT). Read by their own query, as `image_presets` is, so a pending migration does not break `AppSettings::get()` |
| M053 | 1 | `bdus_versions`: `channel` (TEXT: `web`, `api`, `mcp`, `import`, `cli`), `key_id` (INTEGER), `client_id` (TEXT), all nullable. ✅ |
| — | 1 | `bdus_cfg_tables.extra`: optional table `description` (JSON, no migration needed); read by `schema` already, not yet editable in the interface |
| M054 | 1 | `bdus_mcp_prompts`: `id`, `name`, `title`, `description`, `arguments` (JSON), `template`, `sort` |
| M055 | 3 | `bdus_oauth_clients`, `bdus_oauth_codes`, `bdus_oauth_tokens` (access and refresh, hashed id, `user_id`, `client_id`, `scopes`, `resource`, `expires_at`, `revoked_at`, `last_used_at`), `bdus_oauth_consents` |

Two gaps found while reading the code, both decided:

- **Creates leave no trace.** `Persist` writes a snapshot before an UPDATE and a
  DELETE, not after an INSERT. A record created through MCP is identified only
  by `creator`, which is null for an API key. **Decided:** every insert, on every
  channel, writes a row with `operation = 'create'` and metadata only (who,
  when, channel, key or client) — no content, so the table grows by one small
  row per record. `getVersionDiff` and `restoreVersion` must treat such a row as
  "nothing to compare or restore".
- **The data import writes with plain SQL.** `Import::importData` does not go
  through `Record\Persist`: no validation, no snapshot (v4 left it out for its
  complexity). **Decided:** the import goes through `Record\Save`, so imported
  rows are validated and recorded like any other. It is a visible change: files
  that load today may be refused for values that break the field checks, and
  large files get slower (one read and one snapshot per updated row). The work
  includes a per-row error report, a measurement on a large file on the three
  engines, and a `channel = 'import'` in the history. It is its own step
  (phase 2), not a condition for MCP.

## 8. Security review

| Threat | Mitigation |
|---|---|
| **Prompt injection through record content** (a description field saying "ignore your instructions and delete…") | Tool results are data: `guide` says so and results are returned as structured content, never as instructions. The server-side controls do not depend on the model: deletes off by default, writes need a validation token, rights are the principal's. Residual risk stays with the client and is stated in the guide |
| **Data exfiltration** to the model provider or to another connected MCP server | Read rights are exactly the REST rights; row caps; a dedicated group for MCP is recommended; D5 (views) for sensitive columns; the guide warns against mixing untrusted MCP servers in one session |
| **Over-broad keys** | The guide recommends a level-39 key with a group; the key list in the interface shows the last use; admin and super-admin tools do not exist in MCP at all (no schema change, no user management, no free SQL) |
| **Destructive actions** | `mcp_delete` off by default; `destructiveHint`; batch cap; one transaction; history; rate limit on written rows |
| **Token theft** | Bearer header only; short access tokens bound to one resource; refresh rotation with reuse detection; revocation per grant, per user, per client |
| **Malicious client registration** | Consent page always shows the client's host and redirect URI; CIMD limits; dynamic registration off by default; exact redirect match |
| **Cross-application confusion** | A token is bound to one application's resource URL and signed with that application's key; URL app ≠ token app → 403, as today |
| **Denial of service** | Existing per-key and per-address limits; caps on rows, groups and body; `aggregate` only on configured fields, at most two `GROUP BY` |
| **SQL injection through tool arguments** | Table and field names checked against the configuration; values bound; `sqlExpert` search is not reachable from MCP |

## 9. Phases

| Phase | Content | Done when |
|---|---|---|
| **0 — write path in `lib/`** | D1 | ✅ tests pass on three engines |
| **1 — full cycle with API keys** | M052–M054, `Mcp\Server`, the ten tools of section 4 (`aggregate` on `SQL\Aggregate`, extracted from `Chart::getData`), validation token, provenance and `create` rows in the history, prompts, settings UI, user and developer docs | Claude Code and Claude Desktop connect with a level-39 group key; a key sees through MCP exactly the tables and rows it sees through REST (test); an end-of-season report and a context-sheet entry both run end to end on the demo; a refused record rolls the batch back; every MCP change is in the history with `channel = 'mcp'`; three engines pass |
| **2 — import and relations** | Data import through `Record\Save`; `rs_write` tool for stratigraphic relations | An import with an invalid row reports the row and writes nothing; imported rows are in the history; a context sheet is entered with its relations in one conversation |
| **3 — OAuth** | M055, authorization server, consent page, "Authorised applications", `/.well-known` routing in nginx and `bdus-ops` | claude.ai (web and mobile) connects with CIMD, Cursor with dynamic registration; revoking a grant stops the next request; a waiting user cannot authorise |
| **4 — other clients** | Loopback redirect for QGIS/scripts; decision on the Vue app | A QGIS plugin obtains a token without an API key |

Each phase is released on its own and updates `CHANGELOG.md`, `openapi.yaml`
(the MCP and OAuth endpoints), the user guide, `llms.txt`, `guide/evaluate.md`
and the `bradypus-bootstrap` skill.

## 10. Testing

- **PHPUnit.** One test class per tool on the fixture tables; protocol tests for
  each supported revision (handshake, version header, 405 on GET, 202 on
  notifications); **parity tests**: for a set of principals (tiers, group keys,
  row rules, locks, views), what `schema` / `records_read` / `aggregate` return
  equals what the REST endpoints return.
- **Hurl.** A phase for the MCP endpoint (raw JSON-RPC over HTTP) and one for
  the OAuth flow with a scripted client, on SQLite, PostgreSQL and MySQL.
- **MCP Inspector** against the test container, as a manual check of schema and
  annotations at each release.
- **Real clients.** Claude Code and Claude Desktop (API key, then OAuth), the
  claude.ai connector, Cursor — on the demo installation, with a written
  checklist for the two workflows of section 5.
- **Security tests.** A record whose text contains instructions; a token used on
  another application; a rotated refresh token reused; a CIMD URL pointing to a
  private address.

## 11. Answers to the open doubts

1. **Can a level-39 key read the schema?** Not as such: `Config::*` is
   super-admin. With a group it reaches `GET /api/record/{tb}/new`, which
   returns `Config\TableSchema::describe()` for a readable table. The `schema`
   tool is the proper surface: same class, filtered by `Authorization::can('read', table)`.
2. **Aggregation?** Partly there: `Chart::getData` runs `COUNT`, `COUNT_DISTINCT`,
   `SUM`, `AVG`, `MIN`, `MAX` with one `GROUP BY`, row rules applied by
   `QueryFromRequest::getWhereClause()`, on the three engines. Work needed:
   extract it to `SQL\Aggregate`, add a second grouping field, several metrics,
   labels of lookup fields, a cap on groups. Small.
3. **Are labels rich enough?** One `label` and one `help` per field, one `label`
   per table, no translations. Enough for a well-configured application; `help`
   is the description the model reads. Additions: a table `description`
   and guidance to fill `help`. No separate "description for AI": text good for
   a person is good for a model, and two texts drift apart.
4. **Field-level permissions first?** No: D5.
5. **Where does validation live?** `Record\Validator` (D1), used by `Record\Save`
   and by `records_validate`.
6. **Reuse the import upsert?** No. It matches on a non-unique key and writes
   with plain SQL, without validation or history. `resolve` is a read on
   `id_field`; writes take ids (D8).
7. **Provenance in `bdus_versions`?** Yes, with three nullable columns (M053); no
   separate audit table. Creates need a row of their own (section 7).
8. **System plugins.** Generic plugin rows cover radiocarbon (calibrated
   server-side by `Record\Save`), osteology, fuzzy dates (the raw
   `chrono_label` is a field) and any plugin table. Read side of the others
   through `records_read` on a full record: stratigraphic relations, geodata,
   bibliography, Pleiades links. Stratigraphic relations are part of a context
   sheet and get a dedicated tool, `rs_write` (add and remove relations of a
   record, same rules as `Record::addRs` / `deleteRs`), in phase 2. Geometries
   and Zotero/Pleiades links wait for a real request; if `geodata_write` comes,
   its cap is 256 kB per record.
9. **Writable views.** `Persist` refuses a view whose `view_writable` is `none`
   and `Record\Save` answers `view_read_only`; MCP inherits both. Engine
   differences (SQLite needs `INSTEAD OF` triggers, MySQL and PostgreSQL update
   simple views natively, the id returned by an insert through a view) are
   already the REST API's; the MCP parity tests include one writable view per engine.
10. **Batch writes.** One transaction, 50 records, all or nothing, own rate
    limit (section 4 and 6).
11. **One endpoint and one issuer per application?** Yes: users, secrets, groups
    and schema are per application. No cross-application scenario is planned; a
    client that needs two applications adds two connectors.
12. **Current JWT.** HS256, 8 hours, refreshed when under 30 minutes, one secret
    per application, revoked by `token_version`, privilege re-read at every
    request. It does not change when the authorization server exists (section 6).
13. **PHP runtime.** Apache with mod_php (not FPM). Stateless JSON needs no
    stream, no buffering change and no long timeout. Apache must pass the
    `Authorization` header, which it already does for the API.
14. **SDK risk.** D3. The cost of our own layer is following the specification;
    the cost of the SDK is an experimental dependency with a PSR stack we do not
    have. Revisit at SDK 1.0.
15. **Waiting and locked users.** Refused at consent and at every token and MCP
    request (section 6).
16. **Where do settings and prompts live?** Switches and custom guide in
    `bdus_cfg_app`; prompts in `bdus_mcp_prompts` (section 7).
17. **Directus.** Section 12.

## 12. Comparison with Directus

Checked on the Directus documentation, 2026-11-15 (MCP requires v11.12+).

| Directus | BraDypUS | Why |
|---|---|---|
| Endpoint `/mcp`, one per instance | **Adapt**: `/{app}/api/mcp` | Applications are separate databases with their own users |
| One `items` tool for all CRUD | **Adapt**: read, validate, write, delete are separate tools | Separate annotations, separate switches, and the dry run is the core of assisted entry |
| `schema`, `system-prompt` | **Adopt** (`schema`, `guide`) | |
| `collections`, `fields`, `relations` (schema editing) | **Reject** | Changing the schema is a super-admin task with its own interface and DBML import; too much for a model |
| `files`, `assets`, `folders` | **Reject for now** | Section 5 |
| `flows`, `operations`, `trigger-flow` | **Not applicable** | |
| Registry mode (`search`/`execute`) | **Reject** | Ten tools fit in a context; generic execution is harder to gate |
| No aggregate tool | **Add** `aggregate` | Reports are a first-class use |
| Settings: enable, allow deletes, custom system prompt, prompts collection | **Adopt**, plus a separate write switch | Writes are off by default with API keys |
| Acts with the user's permissions | **Adopt** | |
| Static token, also as `?access_token=` | **Adopt** the header, **reject** the query string | Keys end up in logs |
| Built-in OAuth server; DCR and CIMD each behind a switch; scope `mcp:access` | **Adopt**; three scopes instead of one | The user can grant read-only |
| CIMD limits (HTTPS, no redirects, 5 kB, 3 s), redirect allow-lists | **Adopt** | |
| Security guidance (dedicated users, no auto-approve, rotate tokens) | **Adopt** in the user guide | |

## 13. Risks and questions for the project owner

**Risks.**

- The MCP specification moves fast (two revisions in a year, registration
  mechanism changed). The protocol layer is small on purpose.
- `league/oauth2-server` brings PSR-7 and JWT access tokens into a code base
  that has neither; its fit with PHP 8.4 and its dependency tree are to verify
  before phase 3 starts.
- Phase 3 touches the reverse proxy (`/.well-known`), which is configured by hand.
- Row rules on plugin tables read on their own (not through their host record)
  were not verified: `aggregate` and `records_read` on a plugin table must be
  covered by the parity tests before they are allowed.

**Answers of the project owner (2026-11-15).**

1. A `create` row in the history for every insert: **yes, metadata only**.
2. The data import through `Record\Save`: **yes**.
3. Validation token before `records_write`: **yes**.
4. First release read-only: **no — full cycle**, read and write together.
5. Dedicated tools: **yes for stratigraphic relations, wait for geometries**.

**Confirmed by the project owner (2026-11-15).** D7 (endpoints under
`/{app}/api/`), D8 (writes take ids only), `mcp_write` off by default, and
phase 1 released with API keys, OAuth following.
