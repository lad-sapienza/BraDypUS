---
title: SQL layer
---

# SQL layer

BraDypUS never concatenates user input into SQL strings. All data queries go
through `SQL\QueryFromRequest`, which converts a structured PHP `$request` array
into a safe PDO-prepared statement.

```
$request array  →  QueryFromRequest  →  getQuery(true)  →  DB\DB::query()
  (PHP array)       (builds SQL)         [sql, values]      (executes)
                         │
                    SQL\Filter\JsonFilter   (for type='filter')
```

---

## `SQL\QueryFromRequest` — the main query class

`QueryFromRequest` is the single entry point for all data queries. It receives a
structured request array, builds the `SELECT` statement, and provides methods to
execute or inspect it.

### Constructor

```php
use SQL\QueryFromRequest;

$q = new QueryFromRequest(
    db:          $this->db,     // DB\DBInterface
    cfg:         $this->cfg,    // Config\Config
    request:     $request,      // array — see types below
    use_preview: false          // true: SELECT only config preview fields
);
```

### Request structure

The `$request` array must always include `tb` (table name) and `type`. The
remaining keys depend on the type:

| `type` | Extra keys | Effect |
|---|---|---|
| `'all'` | — | `WHERE 1=1` — all records |
| `'fast'` | `string` | `WHERE field1 LIKE '%q%' OR field2 LIKE '%q%'` across all fields |
| `'sqlExpert'` | `querytext`, `join?` | Raw SQL WHERE predicate (DDL stripped) |
| `'filter'` | `filter` | Directus-style nested filter array or URL bracket notation |

### Builder methods

```php
$q->setOrder('name', 'asc');    // ORDER BY tb.name ASC (default: from config)
$q->setLimit(0, 25);            // OFFSET 0 LIMIT 25
$q->setFields(false, ['id', 'name']); // override selected columns
```

### Execution methods

```php
// Returns [sql_string, values_array] for use with DB\DB::query()
[$sql, $vals] = $q->getQuery(true);
$rows = $db->query($sql, $vals, 'read');

// Convenience wrappers
$rows  = $q->getResults();   // executes and returns rows
$total = $q->getTotal();     // COUNT(*) of matching rows

// WHERE predicate only (useful for sub-queries in charts, geodata, …)
[$where, $vals] = $q->getWhereClause();
```

---

## Search types in detail

### `all` — no filter

```php
$q = new QueryFromRequest($db, $cfg, ['tb' => 'sites', 'type' => 'all']);
$q->setOrder()->setLimit(0, 25);
$rows = $q->getResults();
```

### `fast` — full-text LIKE across all fields

```php
$q = new QueryFromRequest($db, $cfg, [
    'tb'     => 'sites',
    'type'   => 'fast',
    'string' => 'pompeii',
]);
```

Generates `WHERE (sites.name LIKE '%pompeii%' OR sites.description LIKE '%pompeii%' …)`.

### `sqlExpert` — user-supplied WHERE clause

```php
$q = new QueryFromRequest($db, $cfg, [
    'tb'        => 'sites',
    'type'      => 'sqlExpert',
    'querytext' => 'typology = "villa" AND period > 200',
    'join'      => '',  // optional extra JOIN clause
]);
```

The `querytext` value is stripped of DDL keywords (`DROP`, `DELETE`, `INSERT`,
`ALTER`, …) by `QueryFromRequest::makeSafeStatement()` before being placed in
the WHERE clause. It is not further parameterised — this mode is only exposed
to super-admin users.

### `filter` — Directus-style structured filter

See [`SQL\Filter\JsonFilter`](#sqlfilterjsonfilter) below.

```php
$q = new QueryFromRequest($db, $cfg, [
    'tb'     => 'sites',
    'type'   => 'filter',
    'filter' => ['typology' => ['_eq' => 'villa'], 'name' => ['_icontains' => 'roma']],
]);
```

---

## `SQL\Filter\JsonFilter` — Directus-style filter

`JsonFilter` translates a nested PHP array into a SQL `WHERE` clause and a
bound-values array. Field names are validated against the table config
allow-list before entering the SQL.

### Operators

| Operator | SQL equivalent |
|---|---|
| `_eq` | `= ?` |
| `_neq` | `!= ?` |
| `_lt` / `_lte` / `_gt` / `_gte` | `< ? ` / `<= ?` / `> ?` / `>= ?` |
| `_contains` | `LIKE '%?%'` (case-sensitive) |
| `_icontains` | `LIKE '%?%'` (case-insensitive) |
| `_ncontains` | `NOT LIKE '%?%'` |
| `_starts_with` / `_ends_with` | `LIKE '?%'` / `LIKE '%?'` |
| `_in` / `_nin` | `IN (…)` / `NOT IN (…)` |
| `_null` / `_nnull` | `IS NULL` / `IS NOT NULL` |
| `_empty` / `_nempty` | `IS NULL OR = ''` / `IS NOT NULL AND != ''` |
| `_between` | `BETWEEN ? AND ?` (value: `[low, high]`) |

### Logical grouping

```php
// Implicit AND at the top level:
['typology' => ['_eq' => 'villa'], 'period' => ['_gt' => 200]]
// → typology = ? AND period > ?

// Explicit OR:
['_or' => [
    ['typology' => ['_eq' => 'villa']],
    ['typology' => ['_eq' => 'farm']],
]]
// → (typology = ? OR typology = ?)
```

### Cross-table (plugin) conditions

```php
['photos' => ['description' => ['_icontains' => 'amphora']]]
// → id IN (SELECT id_link FROM photos WHERE description LIKE ?)
```

### URL bracket notation (GET params)

PHP natively parses `?filter[typology][_eq]=villa` into the same nested array
structure, so GET and POST requests are handled identically.

A `filter` GET parameter whose value is a Base64-encoded JSON string is also
accepted (used for URL persistence in the Vue frontend):

```
?filter=eyJ0eXBvbG9neSI6eyJfZXEiOiJ2aWxsYSJ9fQ==
```

---

## `SQL\QueryObject` — internal query builder

`QueryObject` is the low-level fluent builder that `QueryFromRequest` uses
internally. It is not called directly by application code, but it remains
available for low-level use:

```php
$qo = new \SQL\QueryObject($cfg);
$qo->setTb('sites')
   ->setField('*')
   ->setWherePart(null, null, 'sites.typology', '=', '?', null)
   ->setWhereValues(['villa'])
   ->setOrderFld('sites.name', 'ASC')
   ->setLimit(25, 0);

[$sql, $vals] = $qo->getSql();
$rows = $db->query($sql, $vals, 'read');
```

When a `Config` object is supplied, `QueryObject` runs field and operator
validation via `SQL\Validator` inside `getSql()`.

---

## `SQL\Validator` — query safety

`SQL\Validator::validateQueryObject(QueryObject $qo)` checks:

- Table name is in the app config or is a `bdus_*` system table.
- Field names exist in the table's configured field list.
- WHERE operators are in the allow-list.
- Connectors are `and` or `or`.
- Aggregate functions are in the allow-list.

A failed check throws `SQL\SqlException`.

---

## End-to-end example

```php
// Record list with filter (typical controller code)
$qRequest = [
    'tb'     => $tb,
    'type'   => $this->request['type'] ?? 'all',  // from HTTP request
    'filter' => $this->request['filter'] ?? [],
    'string' => $this->request['string'] ?? '',
];

$q = new \SQL\QueryFromRequest($this->db, $this->cfg, $qRequest, true);
$q->setOrder($this->request['order_by'] ?? null)
  ->setLimit($this->request['offset'] ?? 0, $this->request['limit'] ?? 25);

$rows  = $q->getResults();
$total = $q->getTotal();
```
