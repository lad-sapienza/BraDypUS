---
title: REST API
---

# REST API <Badge type="tip" text="v5.0.0" />

BraDypUS exposes a read-only public REST API that allows external applications to
query records without a full authentication session.

## Authentication

Two authentication methods are supported:

### API key (recommended for automated access)

An API key is a long random token that acts like a service account. Keys are
managed by admins in **Config → API Keys**.

Pass the key in the `Authorization` header:

```
Authorization: Bearer bdus_<key>
```

The header is the **only** way to send a key. A `?api_key=` query parameter is not
accepted (it answers `401`): a key in a URL ends up in web server, proxy and
browser logs.

#### Request limits <Badge type="tip" text="new" />

API-key traffic is rate-limited (people signed in with a user account are not).
Defaults: 60 writes and 300 reads per minute for each key, 120 requests per
minute from one client address, 60 rejected credentials per minute from one
address. Over the limit the API answers HTTP `429` with a `Retry-After` header
(seconds) and `{"status": "error", "code": "too_many_requests"}`: wait and retry.
Failed logins are limited per address too (30 per 15 minutes), on top of the
per-account lockout. Administrators tune the limits with `BRADYPUS_RATE_*`
([deploy guide](../deploy/#environment-variables)); a public site that calls the
API from its own server should keep its key there and apply its own limit per visitor.

Each key has a privilege level: 30 (reader) keys can only read data, 25 keys can
also create and write, 10 keys are admin keys. A key can also belong to a
[group](../setup/groups) — see below.

::: warning A level 30 key reads everything
A reader key (30) reads **every row of every table** of the application, drafts and
unpublished records included. To give a website or a script access to only some tables
or rows, do not use level 30: create a key at level **Enter (39)** tied to a
[group](../setup/groups) whose rules grant `read` on those tables, with a row filter if needed.
See [API keys with a group](#api-keys-with-a-group) below and the recipe in
[Views and public data](../setup/views).
:::

#### Changing, disabling and revoking a key <Badge type="tip" text="next release" />

In **Config → API Keys** a key is not fixed once created:

- **Edit** changes its name, privilege level and group without recreating it. The secret stays
  the same and the change applies from the key's **next request** (the rights are read from the
  database every time), so you can narrow what a key reaches the moment you decide to. The rules
  are those of creation: a level **Enter** key needs a group, an **Admin** key takes none.
- **Disable** switches a key off until you **enable** it again: requests with it are refused
  (`401`). Use it to pause a key you will need again.
- **Revoke** is final: the key can no longer be enabled or edited (it can only be deleted). Use it
  when a key may have leaked, and make a new one.

Through the API (administrators only; see the [API reference](/api-reference/)). Mind the
**singular** `api-key` in the routes that act on one key; only the list and the creation use `api-keys`:

| Action | Request |
|---|---|
| List / create | `GET` / `POST /{app}/api/api-keys` |
| Edit | `PUT /{app}/api/api-key/{id}` |
| Disable / enable | `POST /{app}/api/api-key/{id}/disable` · `…/enable` |
| Revoke (final) | `POST /{app}/api/api-key/{id}/revoke` |
| Delete | `DELETE /{app}/api/api-key/{id}` | Apps that were created before
this release need the minor update that adds the `disabled_at` column
([Updating](../install/update)) before keys can be disabled.

#### AI assistants <Badge type="tip" text="next release" />

An API key can also be given to an AI assistant through the MCP endpoint
`POST /{app}/api/mcp` (off until an administrator switches it on): the assistant sees
and changes what the key may, and nothing else. See [AI assistants (MCP)](../setup/mcp).

#### API keys with a group <Badge type="tip" text="new" />

A key at level **Enter** (39) has no access of its own; its **group** decides
what it can reach: which tables, and which rows of them (for example only the
records whose `status` is `published`), and whether it can write. A key at level
25 or 30 can also take a group, to be allowed more than its level on specific
tables.

Such a key reaches only the *data* endpoints — records, search, export, charts,
GeoFace, assemblage analysis, chrono, and their writes. Everything else (users,
keys, configuration, backups…) is out of reach whatever the group says.

When the group's rules refuse a request — another table, a row outside the
filter, a write the group does not grant — the response is **HTTP 403** with
`{"status": "error", "code": "not_enough_privilege"}`. Rules and locks are
described in [Groups, permissions & locks](../setup/groups); keys are created
with `POST /{app}/api/api-keys` (`privilege` and `group_id`).

### JWT (user session)

The same JWT issued at login can be used in API calls:

```
Authorization: Bearer <jwt_token>
```

## Base URL <Badge type="tip" text="changed in v5.9.0" />

Data endpoints are **application-scoped**: the application name is the first
path segment.

```
https://your-host/{app}/api/...
```

For example, records of the `paths` application live under
`https://your-host/paths/api/records/{table}`. A key or JWT is bound to one
application; calling another app's URL with it returns `403 app_mismatch`.

The pre-5.9 form (a bare `/api/...` with the app taken from the token or a
`?app=` query parameter) has been **removed** — a bare `/api/records/...` now
returns `404 app_prefix_required`.

Only four instance-level endpoints stay at a bare `/api/...` (no app exists or
is chosen yet): `GET /api/auth/apps`, `POST /api/new-app`,
`GET /api/new-app/status`, `GET /api/info`. Everything else, sign-in included
(`/{app}/api/auth/login`, `/{app}/api/auth/oauth/...`), is app-scoped.

## Listing records

```
GET /{app}/api/records/{table}
```

Returns a paginated JSON response:

```json
{
  "status": "success",
  "total": 42,
  "fields": [{"name": "sigla", "label": "Sigla"}, ...],
  "data": [{"id": 1, "sigla": "US001", ...}, ...]
}
```

### Pagination parameters

| Parameter | Default | Description |
|---|---|---|
| `page` | 1 | Page number |
| `per_page` | 30 | Records per page (max 200) |
| `sort_field` | — | Column to sort by |
| `sort_dir` | `asc` | `asc` or `desc` |
| `sort_nulls` | — | `last` or `first`: where empty (`NULL`) values go in the sort. Without it the database decides (PostgreSQL puts them **first** in a descending sort); `last` keeps records without a value at the end, whatever the direction. Applies to every key of a `sort=a,-b` |

### Directus-style parameters <Badge type="tip" text="v5.23.0" />

These are optional and sit next to the ones above, which keep working unchanged.

| Parameter | Meaning |
|---|---|
| `limit` | Rows per page, when `per_page` is absent. Up to the cap; **`limit=-1` means everything, up to the cap** |
| `offset` | Rows to skip; replaces `page` |
| `sort` | `sort=status,-name`: several fields, `-` = descending. Used when `sort_field` is absent. A lookup field sorts on its label (not on its id as in Directus); unknown fields are ignored |
| `search` | On its own (no `search_type`), a fast text search |
| `meta` | `total_count`, `filter_count` or `*`: adds `meta: {…}` to the response. `filter_count` equals `total`; `total_count` is the whole table, limited to the rows you may read |

The **cap** is 5000 rows per response (`per_page` stays at 200). The administrator can change it with the
environment variable `BRADYPUS_API_MAX_ROWS`. When `limit=-1` (or a `limit` above the cap) matches more rows than
the cap, the response has `truncated: true`: page with `offset`. Memory grows with rows and nested rows
(see `fields`), so prefer narrowing the `filter` to raising the cap.

The response keeps its shape (`status`, `total`, `fields`, `data`, …): `data` is already the array of rows, so a
client that reads `response.data` works as it is.

## Related rows in a list <Badge type="tip" text="v5.23.0" />

`fields` chooses the columns **and nests related rows** in each row, so a list and its
sub-records come in one request (a plugin table has no endpoint of its own):

```
GET /{app}/api/records/complexes?fields=id,complex_id,sites_id.name,summary:finds_in_complexes.*
```

```json
{
  "status": "success",
  "total": 137,
  "data": [
    {
      "id": 1,
      "complex_id": "C-01",
      "sites_id": { "id": 3, "name": "Çuka e Ajtoit" },
      "summary": [ { "id": 10, "id_link": 1, "material_or_class": "Fine ware" } ]
    }
  ]
}
```

| Token | Result |
|---|---|
| `column` | A column of the table (unknown names are ignored, as for `columns`) |
| `rel.*` | Every configured field of the related table, plus `id` |
| `rel.column` | Only that column (repeat the token for more), plus `id` |
| `alias:rel.*` | The same, under the key `alias` |

`rel` is one of:

- a **plugin table** of the table: a list, empty when there are no rows;
- a **lookup field** (`id_from_tb`): the referenced row replaces the id (with an alias the raw id stays too);
- a **table that points at this one** through a declared relation (the inverse of a lookup): the rows that
  reference each record, as a list under the table name. `GET …/records/siti?fields=nome,complessi.nome`
  gives every site with its complexes. If that table points at this one through several columns, name one:
  `complessi~sito_id.*`; a plain `complessi.*` then answers `invalid_parameters` and lists the choices.
  A relation of a table with itself (a record's children) is not offered yet.

The pseudo-field **`geodata`** adds the record's geometries (they live in a system table, not in the table):
`fields=id,complex_id,geodata` gives each record a list of `{id, geometry, geojson}`, the geometry as WKT and
as GeoJSON, `[]` when it has none. `geodata.geojson` or `geodata.geometry` return only that part; an alias works
(`geo:geodata.geojson`). Only for tables with geodata enabled, otherwise `invalid_parameters`; the geometries
follow the record, so a row you cannot read brings none.

The pseudo-field **`files`** adds the files linked to the record, in the order of the record view:
`fields=id,title,files` gives each record a list of `{id, ext, filename, description, keywords, attribution, printable}`;
`files.ext` (repeatable: `files.ext,files.filename`) returns only those parts, always with `id`; an alias works
(`pics:files.ext`). `[]` when the record has none. With the `id` and `ext` of a file you build the image address
([Images in several sizes](#images-in-several-sizes)). The files follow the record: a row you cannot read brings none.

Rules:

- One level of nesting. An unknown relation, a deeper path (`a.b.c`) or an alias that clashes with a
  column answers `invalid_parameters` (HTTP 200, as every API error).
- Related rows are plain values, without the `@field` labels of the main rows.
- **Permissions never widen.** Plugin rows follow the readable records. A lookup is expanded only when
  the user or key may read the referenced table **and that row**; otherwise the raw id stays, exactly as
  in a plain list. An inverse relation lists only the rows the user or key may read, and the key is left
  out when the table is not readable at all. Group rules apply as they do to a direct request.
- One query per relation (not per row). A response is limited to 50 000 nested rows
  (`too_many_nested_rows`): narrow the filter or page the list.
- Without `fields` nothing changes. `fields` replaces `columns` when both are sent.
- `POST` accepts `fields` as a string or an array of tokens.

### Differences from Directus

The record list follows Directus where it can, so a client written for Directus needs few changes:

| Directus | BraDypUS |
|---|---|
| Relation key = the field name of the relation (`summary_of_finds`) | Name the table, and give the key yourself with an alias: `summary_of_finds:finds_in_complexes.*` |
| A relation without `.*` returns the related ids | It returns nothing: ask for `rel.*` or `rel.column` |
| `fields=a.b.c` (any depth) | One level only |
| `deep[rel][_filter]` on the nested rows | Not available; filter the main table, or query the related table |
| `multi_select` fields come back as arrays | They come back as the stored text, comma-separated (`"Ven,Ott"`) |
| `sort=lookup` sorts by the id | It sorts by the label shown on screen |
| Only the fields you ask for | A lookup column in `fields` also comes with its label under `@field` (e.g. `@sites_id`), unless the lookup is expanded |
| Nested rows carry the foreign key (`complexes_id`) | Plugin rows carry `id_link`; rows of an inverse relation carry the pointing column |
| Geometry is a field of the item | Geometries are in a system table: ask for `geodata` |

The response keeps its own envelope (`status`, `total`, `fields`, `data`), and `data` is the array of rows,
so `response.data` works unchanged. Errors are `{ "status": "error", "code": … }` with HTTP 200.

## Filtering records

Use the Directus-style `filter` parameter. It accepts either bracket notation
(GET-friendly) or a JSON body (POST).

### Bracket notation (GET)

```
GET /{app}/api/records/us?filter[periodo][_eq]=Basso+Medioevo
```

Multiple conditions:

```
GET /{app}/api/records/us?filter[periodo][_eq]=Basso+Medioevo&filter[sigla][_icontains]=US
```

### JSON body (POST)

```http
POST /{app}/api/records/us
Content-Type: application/json

{
  "filter": {
    "periodo": { "_eq": "Basso Medioevo" },
    "sigla":   { "_icontains": "US" }
  }
}
```

Multiple conditions are joined with `AND` by default. For `OR`:

```json
{
  "filter": {
    "_or": [
      { "periodo": { "_eq": "Basso Medioevo" } },
      { "periodo": { "_eq": "Alto Medioevo"  } }
    ]
  }
}
```

## Filter operators

| Operator | Meaning |
|---|---|
| `_eq` · `_neq` | Equal · not equal (exact) |
| `_contains` · `_icontains` | Contains (the two are identical, see below) |
| `_ncontains` | Does not contain |
| `_starts_with` · `_nstarts_with` | Starts with · does not start with |
| `_ends_with` · `_nends_with` | Ends with · does not end with |
| `_gt` · `_gte` · `_lt` · `_lte` | Greater / less than (or equal) |
| `_between` | Between two values: `[low, high]` |
| `_in` · `_nin` | Value is / is not in the list (an array) |
| `_null` · `_nnull` | Is NULL / is not NULL (`true` or `false`) |
| `_empty` · `_nempty` | Is NULL or empty string / is neither |
| `_chrono_overlap` | Fuzzy-date range overlap: `[low, high]` years (needs the fuzzy-date plugin) |

An unknown operator answers `invalid_parameters` / `Unknown filter operator`. Conditions on the
same level are joined with `AND`; use `_and` / `_or` (arrays of conditions) to nest.

**Case and accents.** Every text operator in the table (`_contains`, `_icontains`, `_starts_with`,
`_ends_with` and their negations) ignores case on every engine (PostgreSQL uses `ILIKE`), so there is no
separate case-insensitive variant: `_starts_with` already is one. Accents are ignored only when the
administrator switches on `BRADYPUS_ACCENT_INSENSITIVE` (and, on PostgreSQL, installs the `unaccent`
extension): then the same operators and the search box find `perche` for `perché`. See
[Accents, case and indexes in text search](../setup/text-search). `_eq`, `_in` and the comparison
operators are always exact.

## Counting by value

To get "how many records for each value of a field" (a status page, a menu with counts) do not make one
request per value. Ask the chart endpoint for a grouped count, which a key with a group can call on the
tables it may read:

```http
POST /{app}/api/chart/data
Content-Type: application/json

{ "definition": { "tb": "works", "type": "bar", "x_field": "status",
                  "y_field": "id", "y_function": "COUNT",
                  "filter": { "filter": { "published": { "_eq": "yes" } } } } }
```

```json
{ "status": "success", "type": "bar", "labels": ["open", "closed"], "data": [12, 30] }
```

`x_field` is the field to group by; `y_function` is `COUNT`, `COUNT_DISTINCT`, `SUM`, `AVG`, `MIN` or `MAX`
(on `y_field`). The optional `filter` restricts the rows, with the same JSON filter as above. For a single number
(a total) use `"type": "metric"` with `field` and `function`. The MCP tool `aggregate` does the same for assistants.

## Reading a single record

```
GET /{app}/api/record/{table}/{id}
```

Returns the full record with all fields, plugin sub-tables, file list, and
associated RS data.

## Images in several sizes <Badge type="tip" text="v5.18.0" />

For a website or frontend that shows the images, define **presets** in the app settings (name,
width and/or height, *cover* or *inside*, WebP/JPG, quality) and use

```
GET /{app}/api/file/{preset}/{file id}.{webp|jpg}
```

The URL is public (no token), like the original file, and stable per file and preset. The
version is generated on the first request and cached; an unknown preset, a missing file, a wrong
extension or a file that is not a JPG, PNG, GIF or WebP image gives a plain `404`. The response
carries an `ETag`, so clients and CDNs can revalidate. The `file` objects returned with a record
give the file `id` (and `ext`, `attribution`). See [Derived images](../usage/files.md#derived-images).

A preset may include a `watermark` (`{text, position, color, background, opacity, margin, size}`, text with the
placeholder `{{attribution}}`): it is drawn on derived versions only, and the original is not affected.

::: warning Files are public by id
Originals and derived versions are served without authentication and ids are sequential.
:::

## Other useful endpoints

| Request | Returns |
|---|---|
| `GET /{app}/api/vocabularies` | All the vocabularies, grouped by name: `{id, def, label, sort}` for each value |
| `GET /{app}/api/files?search=TEXT` | Library files whose name contains the text, with `id` and `ext`; `page`, `per_page` (5–100, default 25) |
| `GET /{app}/api/record/{table}/{id}` | One record in full, with its files |

## Errors

Errors carry a JSON body `{"status": "error", "code": "…"}` and, by convention, **HTTP 200** when the request
reached its endpoint and was refused for a reason of the data (`invalid_parameters`, `parameter_missing`…).
The HTTP status is used for what happens before: `401` `unauthenticated` (no or bad key), `403`
`not_enough_privilege`, `404` `route_not_found` (no such endpoint) or `app_prefix_required`, `405`
`method_not_allowed` (with the allowed methods), `409` major upgrade pending, `429` `too_many_requests`.
A request that fails inside the server answers `dispatch_error`. A client should therefore read the
`status` field of the body, not only the HTTP code.

## Interactive API reference

See the [API Reference](/api-reference/) section for an interactive OpenAPI
explorer with all available endpoints. The same description is the file
[`bdus-api/openapi.yaml`](https://github.com/lad-sapienza/BraDypUS/blob/v5/bdus-api/openapi.yaml) in the
repository (OpenAPI 3): download it to generate a client. There is no `/api/openapi.json` or `/api/docs` endpoint
on the server.
