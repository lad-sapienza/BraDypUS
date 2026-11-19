---
title: Accents, case and indexes in text search
---

# Accents, case and indexes in text search

Text search (the box in the query bar, the **contains / starts with / ends with**
operators of **Filters**, the record and file pickers) is a `LIKE '%text%'`
comparison on the database. What it ignores depends on the engine and, on large tables,
it scans every row. Accent folding is **off by default** and can be switched on for the
whole installation (below). This page says what to expect on each engine and what to do
when it is not enough.

## Ignoring accents on every engine <Badge type="tip" text="v5.26.0" />

Set the environment variable `BRADYPUS_ACCENT_INSENSITIVE=1` and the box in the query
bar and the **contains / starts with / ends with** operators of **Filters** ignore
accents and case, whatever the script: `Σερηνε` finds `Σερῆνε`, `perche` finds `perché`.
Equality (`is`) stays exact.

| Engine | How | What it needs |
|---|---|---|
| **SQLite** | a function that decomposes the text (Unicode NFD), drops the combining marks and lower-cases it | PHP `intl` (included in the official image) |
| **PostgreSQL** | the `unaccent` extension | `CREATE EXTENSION unaccent;` once per database (the standard modules ship it) |
| **MySQL / MariaDB** | nothing changes: it follows the collation of the column | a `…_ci` collation (see below) |

If the switch is on but the engine cannot fold (no `intl`, extension not installed), searches
silently keep the plain behaviour of the table below.

**Cost.** Comparing through a function means the database cannot use an index for the
condition, and every row is processed. A `LIKE '%text%'` already scans the table, so the
difference is a constant factor, but on tables with hundreds of thousands of rows measure it
before turning it on; this is why it is opt-in. On PostgreSQL, the indexed approach described
further down is still the way to go for very large tables.

Not covered: the suggestion lists of select fields and the record and file pickers keep the
plain behaviour of the table below.

## What a search ignores (switch off, or an engine that cannot fold)

| | Upper / lower case | Accents (`citta` finds `Città`) |
|---|---|---|
| **SQLite** | Only for ASCII letters: `città` does **not** match `CITTÀ` | No |
| **MySQL / MariaDB** | Yes | Yes with the usual `…_ci` collations (the default of MariaDB 10.11 is `utf8mb4_general_ci`); **no** with a `…_bin` collation |
| **PostgreSQL** | Yes (BraDypUS uses `ILIKE`) | No, unless you add `unaccent` (below) |

On MySQL / MariaDB the behaviour is that of the **collation of the column**. Check
it with `SHOW FULL COLUMNS FROM your_table;` and change it with
`ALTER TABLE … MODIFY col … COLLATE utf8mb4_general_ci` if a search that should
ignore accents does not.

## Accent-insensitive search on PostgreSQL

Install the extension once per database (it ships with PostgreSQL's standard
contribution modules), wrap it in an `IMMUTABLE` function — `unaccent()` itself is
not, so it cannot be indexed directly — and expose the folded text through a
[SQL view registered as a table](./views):

```sql
CREATE EXTENSION IF NOT EXISTS unaccent;

CREATE FUNCTION f_unaccent(text) RETURNS text
  AS $$ SELECT public.unaccent('public.unaccent', $1) $$
  LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT;

CREATE VIEW sites_search AS
  SELECT s.*, f_unaccent(s.description) AS description_plain
  FROM sites s;
```

Register `sites_search` as a table, put `description_plain` among its preview
fields or filter on it: `citta` then finds `Città di Pietra`. The view is read-only
for searching; edit records in the real table.

## Indexes for long texts

`LIKE '%text%'` starts with a wildcard, so an ordinary B-tree index cannot be used
and every row is read. That is invisible on a few thousand records and slow on
hundreds of thousands of long texts.

- **PostgreSQL** — a trigram GIN index serves `ILIKE '%text%'`, also on the folded
  expression above:

  ```sql
  CREATE EXTENSION IF NOT EXISTS pg_trgm;
  CREATE INDEX sites_description_trgm ON sites USING gin (description gin_trgm_ops);
  -- for the accent-insensitive column of the view:
  CREATE INDEX sites_description_plain_trgm
    ON sites USING gin (f_unaccent(description) gin_trgm_ops);
  ```

  Checked with `EXPLAIN` on a 50,000-row table: both indexes are used by
  `ILIKE '%text%'` (and by the `f_unaccent(...) ILIKE` condition).
- **MySQL / MariaDB** — a `FULLTEXT` index does **not** help: it serves
  `MATCH … AGAINST`, which BraDypUS does not issue. Keep the columns that are
  searched short (`VARCHAR`, not `TEXT`) and use the preview fields wisely, since
  the quick search looks only at them.
- **SQLite** — no trigram support; keep the number of searched fields small.

Create these objects in the database yourself: BraDypUS does not create indexes
for text search.
