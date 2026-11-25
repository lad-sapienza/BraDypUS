---
title: MCP server
---

# MCP server <Badge type="warning" text="in development" />

BraDypUS has a [Model Context Protocol](https://modelcontextprotocol.io) endpoint
per application: `POST /{app}/api/mcp`. An AI client (Claude Code, Claude
Desktop, Cursor…) connects to it and works on the database through a small set
of **generic tools, parameterised by table** — never one tool per table.

This page describes what exists in the code. The design, the decisions behind it
and what is still to come (write tools, history, OAuth) are in the
[MCP & OAuth plan](./mcp-oauth-plan).

| Built | Not yet |
|---|---|
| Protocol layer, settings (and their page: system configuration → MCP, `McpPanel.vue`), the fourteen tools: `guide`, `schema`, `vocabulary`, `records_read`, `aggregate`, `resolve`, `history`, `rs_read`, `bibliography_search`, `records_validate`, `records_write`, `rs_validate`, `rs_write`, `records_delete`, `prompts/list` and `prompts/get`, the REST endpoints and the page that edit prompts (system configuration → MCP, `McpPromptsEditor.vue`, with a button that adds two example prompts in the interface language) | OAuth |

## Request flow

```
POST /{app}/api/mcp
  Router      → Bdus\Controllers\Mcp::handle        ('read', in GROUP_KEY_ROUTES)
  Dispatcher  → principal (JWT or API key), rate limit
  Controllers\Mcp  reads body + headers, checks the MCP switch and Origin
  Mcp\Server::handle($raw, $headers) → [HTTP status, body]
        └─ Mcp\Tool::call($args, Mcp\Context)  → structured result
```

`Bdus\Controllers\Mcp` is the only class that touches the HTTP request.
`Mcp\Server` takes a string and an array of headers and returns a status and an
array: it is tested without a web server.

**Answers are not the API envelope.** MCP clients expect HTTP status codes and
JSON-RPC bodies, so this endpoint does not use `returnJson()` or the
`status` / `code` envelope of the rest of the API.

## Settings

Four columns of `bdus_cfg_app` (M052), read by `Config\AppSettings::getMcp()`:

| Setting | Default | Meaning |
|---|---|---|
| `enabled` | off | The endpoint answers; otherwise `404` |
| `write` | off | `records_write` may be called; otherwise `mcp_write_disabled` |
| `delete` | off | `records_delete` may be called; otherwise `mcp_delete_disabled` |
| `guide` | empty | Text of the administrator, returned by the `guide` tool |

They are edited in the interface at system configuration → *MCP (AI assistants)* (`bdus-app/src/components/config/McpPanel.vue`) and saved with `PUT /api/config/app` and `{ "mcp": { … } }`; the panel sends only that key, and `ConfigAppForm.vue` leaves `mcp` out of its own payload so it cannot overwrite it. Sent alone,
only the MCP settings change: `Config::setMain()` falls back to defaults for the
settings it is not given, so it is not called for an `mcp`-only payload.

## Protocol layer (`Mcp\Server`)

One JSON-RPC message per request, one JSON answer. No session, no stream, no
message initiated by the server: it fits one PHP request and needs nothing from
Apache beyond passing the `Authorization` header.

Two generations of clients are served on the same endpoint:

| | Revision `2026-07-28` | Earlier (`2025-11-25`, `2025-06-18`, `2025-03-26`) |
|---|---|---|
| Recognised by | `params._meta["io.modelcontextprotocol/protocolVersion"]` | no such key, or method `initialize` |
| Opening | none; `server/discover` describes the server | `initialize` (answered without a session; no `Mcp-Session-Id` is ever issued) |
| Headers | `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` on `tools/call` must equal the body: else `400`, `-32020` | `MCP-Protocol-Version`, when present, must be a known revision |
| Required `_meta` | protocol version and `clientCapabilities` (`400`, `-32602`) | — |
| Results | carry `resultType: "complete"` and the server info in `_meta` | plain |
| Unknown method | `404`, `-32601` | `200`, `-32601` |
| `ping` | removed from the protocol | answered |

Common to both: a notification (no `id`) answers `202` with no body; a body that
is not JSON answers `400`, `-32700`; a batch (JSON array) answers `400`,
`-32600`; an unknown tool is a protocol error (`-32602`).

The supported revisions are the constants `Server::MODERN` and `Server::LEGACY`.
Adding a revision means adding it there, a branch where the wire format differs,
and tests in `McpServerTest`.

## Tools

A tool implements `Mcp\Tool`:

```php
interface Tool
{
    public function name(): string;           // snake_case, stable
    public function definition(): array;      // title, description, inputSchema, annotations
    public function needs(): ?string;         // null | 'write' | 'delete': the switch it needs; non-null counts as a write for rate limits
    public function call(array $args, Context $ctx): array;   // structured result; throws ToolError
}
```

and is registered in `Server::standardTools()`. `Mcp\Context` gives it the
database, the configuration, the `Auth\Access` of the principal and the MCP
settings; it never learns how the principal was authenticated.

- **A refusal is a result, not an HTTP error.** A tool throws
  `Mcp\ToolError($code, $detail)`; the server answers `200` with
  `isError: true` and `{ status: "error", code, detail }` — the code strings of
  the REST API where one exists — so the model can read it and correct.
- **Results are returned twice**, as `structuredContent` and as the same JSON in
  a text block, for clients that read only `content`.
- **`Tools\Base::table()`** is the one gate on table names: the table must be in
  the configuration, not a system table, and readable by the principal
  (`Authorization::can('read', table:)`). A table out of reach is reported as
  `unknown_table`, exactly like one that does not exist. Plugin tables are
  refused as the subject of a read (their rows are read nested in their record)
  and accepted only where a tool asks for it (`vocabulary`), judged by the table
  they belong to.

| Tool | Built on | Limits |
|---|---|---|
| `guide` | conventions (constant) + `mcp.guide` | — |
| `schema` | `Config\TableSchema`, `Authorization::tableAbilities()` | — |
| `vocabulary` | `Record\FieldOptions` (readable rows only) | 200 values, 1,000 at most |
| `records_read` | `SQL\QueryFromRequest`, `Record\Expand` — the classes behind `GET /api/records/{tb}`; `bibliography: true` adds `Zotero\Links::forRecords()` | 25 rows, 200 at most |
| `aggregate` | `SQL\Aggregate` (also behind the charts) on the rows of a `QueryFromRequest` request | 2 grouping fields, 6 metrics, 200 groups, 1,000 at most |
| `resolve` | `SQL\QueryFromRequest` on the key field | 100 values, 5 matches each |
| `history` | `bdus_versions`, with the row rule of the REST history | read-only: no restore |
| `rs_read` | `Record\Read::getRs()`, relations to records the principal cannot read left out | — |
| `bibliography_search` | `Zotero\Links::libs()` / `search()` (the logic of `GET /api/zotero/libs` and `/search`); needs `edit` on some table | 100 items |
| `records_validate` | `Record\Save::check()` + `Mcp\Tools\Bibliography::check()` | 50 records |
| `records_write` | `Record\Save::save()` + `Bibliography::apply()` in one transaction; needs `write` | 50 records, 20 link changes per record |
| `rs_validate` | `Record\Rs::checkAdd()` / `checkRemove()` | 50 relations (adds + removals) |
| `rs_write` | `Record\Rs::add()` / `remove()` in one transaction; needs `write` | 50 relations |
| `records_delete` | `Record\Delete` in one transaction; needs `delete` | 50 ids |

Because the read tools build their queries with `QueryFromRequest`, row-level
rules of groups apply exactly as in the REST list.
`McpServerTest::testRecordsReadSameTotalAsTheRestList` compares the two, and
`testAggregateSameCountAsRecordsRead` ties `aggregate` to `records_read`.

`SQL\Aggregate` puts field names into SQL (`GROUP BY`, aggregate functions): each
one is checked against the table configuration first, the function against a
fixed list, and the alias of a metric is built by the tool, never taken from the
caller.

## Writing

Writes go through `Record\Save` and `Record\Delete`, the classes behind
`POST /api/record/{tb}` and `DELETE /api/record/{tb}/{id}`: same access gate,
same validation, same history. Three rules are added for a client that is a model:

1. **Validate first.** `records_validate` runs `Record\Save::check()` on every
   record and, when all pass, returns a `validation_token`. `records_write`
   refuses a call without the token of exactly that payload
   (`validation_token_invalid`). The token is `expires.hmac`: an HMAC-SHA256 of
   the expiry, the principal (user id or API key id) and the canonical JSON of
   `table` + `records` (object keys sorted), signed with a key derived from the
   application secret (`JwtManager::deriveKey(APP, 'mcp-validation')`). It is
   valid 15 minutes and nothing is stored. It proves the payload was validated,
   **not** that the user agreed: that is the client's confirmation, which the
   `destructiveHint` annotation asks for. **It is not single-use** (decision of the
   project owner, 2026-11-15, over a stored nonce): within its 15 minutes the same call
   can be sent again and writes again. An update replays harmlessly (same values); a
   creation is repeated unless a `no_dupl` field refuses the second one — the case to
   expect is a client that retries after losing the answer. The 500-records-per-hour
   budget bounds the damage. If duplicates show up in real use, the way out is a store of
   used tokens in the cache directory (like `Auth\RateLimiter`, no schema, failing open),
   answering `validation_token_used`. Pinned by
   `McpWriteToolsTest::testTheTokenIsNotSingleUseSoOnlyAUniqueFieldStopsAReplay`.
2. **All or nothing.** A call is one transaction (see *Nested transactions* in
   [Database layer](./database)): if a record is refused, nothing is written and
   the answer names the failing index (`write_refused`) or id (`delete_refused`).
   Records valid on their own can still clash together (two new records with the
   same `no_dupl` value): the second fails at write time and the first is rolled back.
3. **Ids only.** Records are addressed by `id`; `resolve` turns a name into ids.
   A key field is not guaranteed unique, so there is no upsert by name.

Every change is recorded in `bdus_versions` with `channel = 'mcp'`
(`Controllers\Mcp` calls `Auth\Channel::set('mcp')`) and, for an API key, its
`key_id`. With an API key there is no user: `creator` is null and `userid` 0.

Plugin tables are refused as the `table` of a write: their rows are written
inside their record, in `plugins`. The tool describes `{ "fields": { … } }` as "add a row"
and `{ "id": 7, … }` as "change it", but `Record\Save` only adds a row to an *existing*
record when the row carries `_isNew`; `RecordsValidate::pluginRows()` therefore sets the flag
on every row without an `id`, and removes one a client supplies, so the dry run shows what
will be written and a row that names an id never turns into an insert. A row id that does not
belong to the record is ignored (`Record\Edit` works on the loaded record).

To write, the principal must also be able to **read** the table: every tool goes through
`Tools\Base::table()`. The REST API lets a group update without reading; MCP is stricter on
purpose (`McpWriteRulesTest::testWritingNeedsToBeAbleToReadTheTable`).

## Stratigraphic relations

`Record\Rs` is the one place that adds and removes relations (`bdus_rs`: `tb`, `first`, `second`,
`relation`, read "first `<relation>` second"); the REST endpoints `POST /api/record/{tb}/rs` and
`DELETE /api/rs/{id}` and the MCP tools share it. It is a pure move of what the controller did,
plus the checks the controller never made, now enforced for both entry points:

| Code | Refused when |
|---|---|
| `rs_not_configured` | the table is unknown or does not have the `rs` plugin |
| `invalid_relation` | the code is not 1–10 (`Rs::RELATIONS`; 1↔5, 2↔6, 3↔7, 4↔8, 9 and 10 symmetric) |
| `relation_with_itself` | `first` = `second` |
| `not_enough_privilege` | the principal may not change **both** records (level, group rules, row scope, locks) |
| `record_not_found` | a record does not exist (after the rights check: existence is not told to someone who may not change it) |
| `relation_already_exist` | the same fact is stored, **in either direction** (`Rs::findSame()`) |

`rs_validate` / `rs_write` take `add: [{ first, relation, second }]` and `remove: [relation ids]`
(the ids `rs_read` lists; a relation of another table counts as `not_found`). Removals are done
before additions, so a call can swap a relation; the same fact twice in one call is caught at
validation. The token is `ValidationToken` with scope `rs` (scope `records` for records: one
cannot be used for the other). `schema` of a table with relations adds `stratigraphy`: the ten
codes, their names and inverses, how to read them. `rs_read` is judged like `history`: the record
must be readable, and a relation whose other record is not readable is left out. Labels in the
dry run (`"3 covers 4"`) are shown only for readable records.

**Relations are not versioned.** Neither the REST endpoints nor `Record\Persist` write a
snapshot for them, and restoring a version does not bring them back: this is not specific to
MCP. A removed relation is gone. Tracked in
[issue #78](https://github.com/lad-sapienza/BraDypUS/issues/78).

Tests: `RecordRsTest`, `RecordCtrlRsTest` (REST refusals), `McpRsTest` (tools, rights, hidden
records, token, budget), hurl phase 55.

## Manual links (bdus_userlinks)

`Record\ManualLinks` holds the logic that does not depend on HTTP, as `Record\Rs` does for
relations; the REST endpoints `POST /api/manual-link` and `DELETE /api/manual-link/{id}` delegate to it
(their answers are unchanged). It gates **both** records with `WriteGate::mayChange('update')`,
looks for a duplicate in both directions, and `forRecords()` reads the links of many records in
few queries (files, `tb = 'bdus_files'`, are not manual links). The MCP side is `Mcp\Tools\Links`:

- A record in `records_validate` / `records_write` may carry `links: { add, remove }` (`add`:
  `{ table, id, label?, sort? }`; `remove`: link ids; at most 20 items per record), part of the
  payload the `validation_token` signs. Unknown keys are `invalid_parameters`.
- The other table is resolved with `Base::table()` (`unknown_table` for an unknown, `bdus_*` or plugin
  table, or one the key cannot read; `view_read_only` for a read-only view). Refusals beyond the REST
  ones, MCP only: `record_not_found` (asked after the rights, as `Record\Rs`, so existence of a
  record is not told to someone who may not change it) and `link_with_itself`. The others are
  `link_not_found` (a link that is not one of this record's), `link_already_exists` (also twice in one
  call; removing and re-adding the same pair in one call is fine), `not_enough_privilege`.
- Rights: the record is changed first (`Record\Save`), then both ends of each link go through the
  gate; removing a link needs the right on the other record too. A links-only update (`core: {}`)
  is allowed. Deleting a record already drops its links in both directions (`Record\Persist`).
- Reading: `records_read` with `links: true` (any table; links to tables the key cannot read are
  left out, since the label is a value of the other record).
- Links are not in the version history, as relations.

## Bibliography (Zotero links)

The logic that does not depend on HTTP lives in `Zotero\Links` (libraries, search, citation
cache, add / edit / delete of a link); the REST controller `Zotero` delegates to it, so both entry
points write links the same way. The MCP side is `Mcp\Tools\Bibliography`:

- A record in `records_validate` / `records_write` may carry `bibliography: { add, edit, remove }`
  (`add`: `{ lib_id, zotero_key, pages?, notes?, sort? }`; `edit`: `{ id, pages?, notes?, sort? }`;
  `remove`: link ids; at most 20 items per record). It is part of the payload the
  `validation_token` signs. Unknown keys are `invalid_parameters` (a client cannot forge
  `full_citation`).
- Refusals are codes: `zotero_not_configured` (table without `zotero`), `lib_not_found`,
  `link_not_found` (a link that is not one of this record's), `link_already_exists` (same library and
  key, also twice in one call), `not_enough_privilege`, `invalid_parameters`. In the dry run they
  come per record; in the write as `write_refused` with the code in `detail`.
- Rights: the record is changed first (`Record\Save`, so tier, group rules, row scope and locks
  apply), then `WriteGate::mayChange('update')` is asked again for the links, as the REST routes do.
  A bibliography-only update (`core: {}`) is allowed.
- The citations are fetched from Zotero **before** the transaction opens (`Bibliography::prefetch()`),
  so the network is not waited for with a lock held; if Zotero does not answer the link is stored
  without a cache and the next sync fills it (as in the REST `POST /api/zotero/link`). The dry run
  never asks Zotero.
- Reading: `records_read` with `bibliography: true` (refused with `zotero_not_configured` on other
  tables); `bibliography_search` without `query` lists the libraries (no API key), with `query`
  searches one.

## Prompts

`prompts/list` and `prompts/get` serve the text templates an administrator wrote
for the application (`bdus_mcp_prompts`, M054) — "end-of-season report", "context
sheet entry". `Mcp\Prompts` is the store: validation on write, order, rendering.

- **A prompt is text, not a permission.** It is the same for every principal and
  grants nothing; what the model can then do is still decided by the tools and the
  principal's rights. Prompts are **read-only from MCP**: only an administrator edits
  them (a model that could write its own prompts would have a permanent injection channel).
- **Template.** `{{argument}}` placeholders; every placeholder must be a declared
  argument (refused when saved). Rendering is one pass over the template: a value
  containing `{{x}}` is not expanded again. An optional argument left out becomes empty.
- **Limits.** 50 prompts, 10 arguments each, title 100, description 500, template
  20,000 characters, an argument value 5,000; name `[a-z0-9][a-z0-9_-]{0,63}`, unique.
- **Protocol.** `capabilities.prompts = { listChanged: false }` (no notifications).
  The list is not paginated (50 at most), carries `ttlMs` 300000 and `cacheScope: public`
  in revision `2026-07-28`, where `prompts/get` also needs the `Mcp-Name` header equal to
  `params.name` (`400`, `-32020` otherwise). An unknown prompt, a missing required argument,
  an undeclared argument or a value that is not text answer `-32602`.
- **Before M054** `prompts/list` answers an empty list instead of failing.
- **Administration.** Super admin only, through `Bdus\Controllers\McpPrompts`:
  `GET|POST /api/mcp-prompts`, `PUT /api/mcp-prompts/order`,
  `PUT|DELETE /api/mcp-prompt/{id}`. A refusal is `200` with a code
  (`invalid_prompt_name`, `invalid_prompt_template`, `invalid_prompt_arguments`,
  `undeclared_prompt_placeholder`, `prompt_name_taken`, `too_many_prompts`,
  `prompt_not_found`). Tests: `McpPromptsTest`, `McpPromptsCtrlTest`, hurl phase 56.

## Access and rate limits

- The route is `read` and listed in `Router::GROUP_KEY_ROUTES`: any principal
  that may read reaches it, including a level-39 API key scoped by a group.
- Every MCP call is an HTTP `POST`, so the Dispatcher asks
  `Mcp\Server::isWriteCall()` whether the body calls a write tool, and counts
  the request against `KEY_READ` or `KEY_WRITE` accordingly.
- Records are counted too: `records_write` and `records_delete` add the number of
  records of the call to the `MCP_WRITE_ROWS` budget of the API key (default 500 per
  hour, `BRADYPUS_RATE_MCP_WRITE_ROWS`, `0` = off). Over budget the call is refused
  before anything changes (`too_many_requests`, `retry_after`); a refused call is not
  counted. People signed in with a JWT are not limited.
- A request body over 1 MB (`Server::MAX_BODY`) is refused with `413` before it is parsed.
- `Origin`, when a browser sends it, must be the host itself or one of
  `BRADYPUS_CORS_ORIGIN`; otherwise `403`.

## Tests

- `tests/Integration/McpServerTest.php` — protocol (both generations), the read
  tools, refusals, the switches, the route gate, the settings.
- `tests/Integration/McpWriteToolsTest.php` — validation, the token (payload,
  principal, expiry), all-or-nothing writes and deletes, what is left in the
  history, nested transactions.
- `tests/Integration/McpReadParityTest.php` — every read tool against the REST API for a
  group-scoped user, a group key, a key with no group and a reader: row rules, tables with no
  rule, plugin tables, nested rows, history, vocabulary, schema.
- `tests/Integration/McpWriteRulesTest.php` — locks, tiers, self writer, group rules (create,
  update, delete scoped separately), pinned values, group keys and their trace in the history,
  plugin rows (add, change, remove, a client's own `_isNew`), `creator`/`id` not client fields.
- `tests/Integration/McpBibliographyTest.php` — reading links, searching, add / edit / remove inside the record's transaction, creating a record with links, every refusal code, the token, atomicity, self writer.
- `tests/Integration/McpLinksTest.php` — reading links from both sides, add / remove inside the record's transaction, another table, every refusal code, the token, atomicity, both records gated (self writer, reader).
- `tests/Integration/McpViewsTest.php` — read-only and writable SQL views.
- `tests/api/55_mcp.hurl` — the endpoint over HTTP: 401, 404 while off, 405 on
  GET, handshake, modern headers, `Origin`, prompts without any prompt written.
- `tests/api/60_mcp_bibliography.hurl` — Zotero links over HTTP on every engine: create a record with a link, read it with `bibliography: true`, edit, add, remove, a foreign link refused, checked also through `GET /api/zotero/links`.
- `tests/api/61_mcp_links.hurl` — manual links over HTTP on every engine: add with a record, read from both sides, refusals, REST `POST/DELETE /api/manual-link` cross-checked.
- `tests/api/56_mcp_prompts.hurl` — prompts written through the REST endpoints and read
  back as an MCP client reads them.
