---
title: Accents, case and indexes in text search
---

# Accents, case and indexes in text search

Text search (the box in the query bar, the **contains / starts with / ends with**
operators of **Filters**, the record and file pickers) is a `LIKE '%text%'`
comparison on the database. What it ignores depends on the engine — BraDypUS adds
no accent folding of its own — and, on large tables, it scans every row. This page
says what to expect on each engine and what to do when it is not enough.

## What a search ignores

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
