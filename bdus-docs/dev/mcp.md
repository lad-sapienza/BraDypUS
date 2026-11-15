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
| Protocol layer, settings, the ten tools: `guide`, `schema`, `vocabulary`, `records_read`, `aggregate`, `resolve`, `history`, `records_validate`, `records_write`, `records_delete` | Settings page in the interface, prompts, a tool for stratigraphic relations, OAuth |

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

They are saved with `PUT /api/config/app` and `{ "mcp": { … } }`. Sent alone,
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
| `records_read` | `SQL\QueryFromRequest`, `Record\Expand` — the classes behind `GET /api/records/{tb}` | 25 rows, 200 at most |
| `aggregate` | `SQL\Aggregate` (also behind the charts) on the rows of a `QueryFromRequest` request | 2 grouping fields, 6 metrics, 200 groups, 1,000 at most |
| `resolve` | `SQL\QueryFromRequest` on the key field | 100 values, 5 matches each |
| `history` | `bdus_versions`, with the row rule of the REST history | read-only: no restore |
| `records_validate` | `Record\Save::check()` | 50 records |
| `records_write` | `Record\Save::save()` in one transaction; needs `write` | 50 records |
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
   `destructiveHint` annotation asks for.
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
inside their record, in `plugins`.

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
- `Origin`, when a browser sends it, must be the host itself or one of
  `BRADYPUS_CORS_ORIGIN`; otherwise `403`.

## Tests

- `tests/Integration/McpServerTest.php` — protocol (both generations), the read
  tools, refusals, the switches, the route gate, the settings.
- `tests/Integration/McpWriteToolsTest.php` — validation, the token (payload,
  principal, expiry), all-or-nothing writes and deletes, what is left in the
  history, nested transactions.
- `tests/api/55_mcp.hurl` — the endpoint over HTTP: 401, 404 while off, 405 on
  GET, handshake, modern headers, `Origin`.
