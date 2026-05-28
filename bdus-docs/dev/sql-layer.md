---
title: SQL layer
---

# SQL layer

BraDypUS never concatenates user input into SQL strings. All queries go through
a pipeline of three classes that together guarantee safe, engine-portable SQL:

```
ShortSQL string  ─►  ParseShortSql  ─►  QueryObject  ─►  getSql()  ─►  DB\DB::query()
  (API DSL)             (parser)        (structured            (SQL + params array)
                                         builder)
```

The same `QueryObject` can also be assembled directly in PHP — without going
through the ShortSQL parser — for internal queries that do not originate from
user input.

---

## `SQL\QueryObject` — The query builder

`QueryObject` is a fluent builder that accumulates the parts of a `SELECT`
statement and renders them into a `[sql_string, values_array]` pair safe for
PDO prepared statements.

### Construction

```php
use SQL\QueryObject;
use Config\Config;

// With config (enables field/table validation against the app schema)
$qo = new QueryObject($cfg);

// Without config (no validation; used internally)
$qo = new QueryObject();
```

### Builder methods (fluent interface)

| Method | Effect |
|---|---|
| `setTb(string $tb, ?string $alias)` | Set the main FROM table |
| `setField(string $fld, ?string $alias, ?string $tb, ?string $fn)` | Add a SELECT field |
| `setFieldSubQuery(string $subQuery, ?string $alias, ?array $values)` | Add a sub-query as a field |
| `setWherePart(?string $connector, ?string $openBracket, string $fld, string $op, string $val, ?string $closeBracket)` | Add a WHERE clause |
| `setWhereValues(array $values)` | Append bound parameter values |
| `setJoin(string $tb, ?string $alias, array $on)` | Add a LEFT JOIN |
| `setOrderFld(string $fld, string $dir)` | Add an ORDER BY column |
| `setGroupFld(string $fld)` | Add a GROUP BY column |
| `setLimit(int $tot, int $offset)` | Set LIMIT / OFFSET |
| `setAutoJoin(bool $on)` | Enable/disable auto-join (default on) |

### `getSql(bool $onlyWhere = false): array`

Returns `[$sql_string, $values_array]`. Pass to `DB\DB::query()`:

```php
[$sql, $vals] = $qo->getSql();
$rows = $db->query($sql, $vals, 'read');
```

Pass `true` to get only the `WHERE` clause and its values — used internally
when composing sub-queries.

### Auto-join

When a `Config` object is provided and `auto_join` is enabled (default),
`QueryObject` automatically adds `JOIN` clauses in two situations:

1. **Plugin tables** — if a field or WHERE clause references a table that is
   listed as a plugin of the main table in the config, a join on
   `plugin_table.table_link = 'main_table' AND plugin_table.id_link = main_table.id`
   is added automatically. A `GROUP BY main_table.id` is also added to avoid
   duplicate rows.

2. **`id_from_tb` fields** — if a field has an `id_from_tb` config property,
   a join to the referenced table is added so the WHERE can filter by the
   human-readable value rather than a foreign key integer.

### Supported aggregate functions

`setField()` accepts an optional `$fn` parameter. Supported values:

| `$fn` string | SQL output |
|---|---|
| `count` | `COUNT(tb.fld)` |
| `count_distinct` | `COUNT(DISTINCT tb.fld)` |
| `distinct` | `DISTINCT tb.fld` |
| `avg` | `AVG(tb.fld)` |
| `sum` | `SUM(tb.fld)` |
| `min` | `MIN(tb.fld)` |
| `max` | `MAX(tb.fld)` |
| `group_concat` | `GROUP_CONCAT(tb.fld)` |

---

## `SQL\ShortSql\ParseShortSql` — ShortSQL parser

ShortSQL is the URL-safe query DSL exposed by the public REST API. It lets a
client express a complete `SELECT` statement as a single URL parameter without
risking SQL injection — because the parser converts it into a `QueryObject`,
never into raw SQL.

### Grammar

A ShortSQL string is `~`-delimited clauses. Each clause starts with a
single-character symbol that identifies its role:

| Symbol | Role | Example |
|---|---|---|
| `@` | Table name (required) | `@sites` |
| `[` | Fields to SELECT | `[sites.name,sites.typology` |
| `?` | WHERE conditions | `?sites.typology\|like\|villa` |
| `>` | ORDER BY | `>sites.name:ASC` |
| `-` | LIMIT / OFFSET | `-25:0` |
| `*` | GROUP BY | `*sites.typology` |
| `]` | Explicit JOIN | `]othertable` |

**Full example** — sites named like "villa", ordered by name, page 1:

```
@sites~[sites.name,sites.typology~?sites.name|like|%villa%~>sites.name:ASC~-25:0
```

Clauses are joined with `~`:
- `@sites` — FROM sites
- `[sites.name,sites.typology` — SELECT name, typology
- `?sites.name|like|%villa%` — WHERE name LIKE '%villa%'
- `>sites.name:ASC` — ORDER BY name ASC
- `-25:0` — LIMIT 25 OFFSET 0

### WHERE clause format

Multiple conditions are separated by `||`. Each condition is:

```
[connector.][[open_bracket.]table.field|operator|value[.close_bracket]
```

| Part | Values |
|---|---|
| `connector` | `and`, `or` (omit for first condition) |
| `operator` | `=`, `!=`, `like`, `not like`, `<`, `<=`, `>`, `>=`, `is null`, `is not null`, `in` |
| `value` | Bound parameter value. Prefix with `^` to treat as a literal (field name, integer) |

**Example with two conditions:**

```
?sites.typology|=|villa||and.sites.name|like|%roma%
```

### Sub-queries

Wrap a ShortSQL sub-query in `{...}`. The parser base64-encodes the inner
string and emits it as `< ... >` internally:

```
[{@sites~[count(sites.id)}|total_sites
```

This is valid as a SELECT sub-query field with alias `total_sites`.

Add `!` immediately after `{` to prevent the sub-query value from being
cast to a string: `{!@sites~[count(sites.id)}`.

### Usage

```php
use SQL\ShortSql\ParseShortSql;

$parser = new ParseShortSql($cfg);          // $cfg: Config\Config or null
$parser->parseAll('@sites~[sites.name~?sites.typology|=|villa~-25:0');

[$sql, $values] = $parser->getSql();
$rows = $db->query($sql, $values, 'read');
```

---

## `SQL\ShortSql` clause parsers

`ParseShortSql` delegates each clause to a dedicated static class:

| Class | Handles | Key method |
|---|---|---|
| `SQL\ShortSql\Table` | `@` — table name + alias | `Table::parse(string)` |
| `SQL\ShortSql\Field` | Single field spec (name, alias, fn, subquery) | `Field::parse(string, string $tb)` |
| `SQL\ShortSql\Where` | `?` — WHERE clause list | `Where::parse(Config, string, string $tb, ...)` |
| `SQL\ShortSql\Join` | `]` — explicit JOIN clause | `Join::parse(array, Config, ...)` |
| `SQL\ShortSql\Order` | `>` — ORDER BY | `Order::parse(?string, ?string $tb)` |
| `SQL\ShortSql\Limit` | `-` — LIMIT / OFFSET | `Limit::parse(?string)` |
| `SQL\ShortSql\Group` | `*` — GROUP BY | `Group::parse(?string, string $tb)` |
| `SQL\ShortSql\SubQuery` | `{...}` inline sub-queries | `SubQuery::parse(string)` |

---

## `SQL\Validator` — Query safety checks

When a `Config` object is passed to `QueryObject`, validation runs automatically
inside `getSql()` via `SQL\Validator::validateQueryObject()`.

The validator checks:

- Table name is a known table in the config (or a system `bdus_*` table).
- Field names exist in the table's configured field list.
- WHERE operators are in the allow-list: `=`, `!=`, `like`, `not like`, `<`,
  `<=`, `>`, `>=`, `is null`, `is not null`, `in`.
- Connectors are `and` or `or`.
- Aggregate functions are in the allow-list (see above).

A failed validation throws `SQL\SqlException`.

---

## `SQL\SafeQuery` — Low-level PDO executor

`SafeQuery` is the last step: it receives a SQL string and a values array and
executes them against a PDO connection. `DB\DB::query()` delegates to it.

It is not called directly by application code — always use `DB\DB::query()`.

---

## End-to-end example

```php
// Build a query programmatically (no ShortSQL)
$qo = new \SQL\QueryObject($cfg);
$qo->setTb('sites')
   ->setField('*')
   ->setWherePart(null, null, 'sites.typology', '=', '?', null)
   ->setWhereValues(['villa'])
   ->setOrderFld('sites.name', 'ASC')
   ->setLimit(25, 0);

[$sql, $vals] = $qo->getSql();
// SELECT sites.* FROM sites WHERE sites.typology = ? ORDER BY sites.name ASC LIMIT 25 OFFSET 0

$rows = $db->query($sql, $vals, 'read');
```

```php
// Parse from ShortSQL (API path)
$parser = new \SQL\ShortSql\ParseShortSql($cfg);
$parser->parseAll('@sites~[sites.*~?sites.typology|=|villa~>sites.name:ASC~-25:0');
[$sql, $vals] = $parser->getSql();
$rows = $db->query($sql, $vals, 'read');
```

Both paths produce the same bound prepared statement — user input never reaches
the SQL string.
