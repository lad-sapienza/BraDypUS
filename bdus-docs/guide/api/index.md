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

Each key has a maximum privilege level. A key with privilege 30 (reader) can only
read data; a key with privilege 20 (editor) can also write.

### JWT (user session)

The same JWT issued at login can be used in API calls:

```
Authorization: Bearer <jwt_token>
```

## Base URL

All API endpoints are under `/api/`. The application name is NOT part of the path
(unlike older v3/v4 versions). The active app is determined from the token.

## Listing records

```
GET /api/records/{table}
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

## Filtering records

Use the Directus-style `filter` parameter. It accepts either bracket notation
(GET-friendly) or a JSON body (POST).

### Bracket notation (GET)

```
GET /api/records/us?filter[periodo][_eq]=Basso+Medioevo
```

Multiple conditions:

```
GET /api/records/us?filter[periodo][_eq]=Basso+Medioevo&filter[sigla][_icontains]=US
```

### JSON body (POST)

```http
POST /api/records/us
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
| `_eq` | Equal |
| `_neq` | Not equal |
| `_icontains` | Case-insensitive contains |
| `_ncontains` | Does not contain |
| `_starts_with` | Starts with |
| `_ends_with` | Ends with |
| `_gt` | Greater than |
| `_lt` | Less than |
| `_gte` | Greater than or equal |
| `_lte` | Less than or equal |
| `_empty` | Is NULL or empty string |
| `_nempty` | Is not NULL and not empty |
| `_in` | Value is in the list |
| `_nin` | Value is not in the list |

## Reading a single record

```
GET /api/record/{table}/{id}
```

Returns the full record with all fields, plugin sub-tables, file list, and
associated RS data.

## Interactive API reference

See the [API Reference](/api-reference/) section for an interactive OpenAPI
explorer with all available endpoints.
