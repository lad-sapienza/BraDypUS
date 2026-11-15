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
| Protocol layer, settings, `guide`, `schema`, `vocabulary`, `records_read`, `resolve` | `aggregate`, `history`, `records_validate`, `records_write`, `records_delete`, prompts, settings page in the interface, OAuth |

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
| `write` | off | Tools whose `isWrite()` is true may be called; otherwise `mcp_write_disabled` |
| `delete` | off | Reserved for `records_delete` |
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
    public function isWrite(): bool;          // needs the `write` switch; counts as a write for rate limits
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
| `resolve` | `SQL\QueryFromRequest` on the key field | 100 values, 5 matches each |

Because the read tools build their queries with `QueryFromRequest`, row-level
rules of groups apply exactly as in the REST list.
`McpServerTest::testRecordsReadSameTotalAsTheRestList` compares the two.

## Access and rate limits

- The route is `read` and listed in `Router::GROUP_KEY_ROUTES`: any principal
  that may read reaches it, including a level-39 API key scoped by a group.
- Every MCP call is an HTTP `POST`, so the Dispatcher asks
  `Mcp\Server::isWriteCall()` whether the body calls a write tool, and counts
  the request against `KEY_READ` or `KEY_WRITE` accordingly.
- `Origin`, when a browser sends it, must be the host itself or one of
  `BRADYPUS_CORS_ORIGIN`; otherwise `403`.

## Tests

- `tests/Integration/McpServerTest.php` — protocol (both generations), each
  tool, refusals, the write switch, the route gate, the settings.
- `tests/api/55_mcp.hurl` — the endpoint over HTTP: 401, 404 while off, 405 on
  GET, handshake, modern headers, `Origin`.
