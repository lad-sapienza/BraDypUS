---
title: Is BraDypUS right for your data?
---

# Is BraDypUS right for your data?

A short checklist to decide whether a dataset or a project is worth moving into
BraDypUS, written for people and for the assistants (LLMs, agents) that help them.
Each section ends with a link to the page that goes deeper.

## What BraDypUS is

A web application for **relational data about things that are described, related and
located**: archaeological contexts and finds, museum or archive records, bibliographic
or prosopographic data, field surveys. You define the tables and fields yourself; the
application provides forms, lists, search, import/export, users and permissions,
versions of every record, files, maps and print templates on top of them.

- Free software ([AGPL-3.0](https://github.com/lad-sapienza/BraDypUS/blob/v5/bdus-api/LICENSE)),
  self-hosted: your server, your database. No hosted service.
- PHP API + Vue single-page app; runs on **SQLite**, **MySQL/MariaDB** or **PostgreSQL**,
  normally from Docker images ([requirements](/guide/environment/system-requirements)).
- Several independent applications (databases) can live on one installation.

## It is a good fit when

- Your data are **several tables linked to each other** (a context has many finds, a
  find belongs to a site, a record cites sources), possibly now living in spreadsheets
  or an Access/FileMaker file.
- You need a **shared database with accounts**: people who only read, people who edit,
  reviewers who see only a slice, and records that must be **frozen** once published
  ([groups & locks](/guide/setup/groups)).
- You want **controlled vocabularies**, validation (not empty, unique, range, regex) and
  a **history of every change** with the possibility to restore a previous version
  ([versions](/guide/usage/versions)).
- Some records have **geometry** (points, lines, polygons) to show on a map, or a
  **chronology** (also vague: "c4l BCE", "post quem") to put on a timeline
  ([geodata](/guide/system-plugins/geodata), [fuzzy dates](/guide/system-plugins/fuzzy-date)).
- You work with **stratigraphy**, radiocarbon dates, skeletal inventories, Zotero
  bibliographies or Pleiades places: these are built in
  ([system plugins](/guide/system-plugins/)).
- Files (photos, documents) have to be **attached to records**, in bulk if needed
  ([files](/guide/usage/files), [import](/guide/usage/import)).

## It is probably not the right tool when

- The data are **one flat table** nobody else edits: a spreadsheet is simpler.
- You need **analytics over very large, append-only data** (millions of rows to
  aggregate): use a data warehouse or a GIS database; BraDypUS can hold the
  descriptive records next to it.
- You need a **public, hosted website** out of the box: BraDypUS is the editing and
  research database; publishing is done through its [API](/guide/api/) or exports.
- You need **permissions per field** or a **workflow engine** (draft → review →
  publish states with notifications): rules are per table and per row, and locks freeze
  rows; there is nothing finer.
- You cannot host and back up a small web application.

## What your data must look like

| BraDypUS expects | What it means for your data |
|---|---|
| A table per kind of thing | One sheet or file per entity; mixed sheets must be split first |
| Primary key `id` on every table | Created automatically if missing; your own identifier becomes a normal field |
| Relations between tables | Stored as an `id` of the other table; a `ref:` in the DBML (or **Config → Relations**) creates the foreign key and the linked-records panel on both sides, and a *select* field "from table" lists the other table's records |
| Controlled values | Vocabularies (lists) for `select` / `multi_select` / `combo_select` fields |
| Text, long text, Markdown, date, yes/no, select | The field types; numbers are stored as text or integer and validated with rules, not as a separate numeric type |
| Geometry as WKT / GeoJSON | Enabled per table with the Geodata plugin; GeoJSON is imported onto existing records |

Checklist before deciding, answer these about your data:

1. How many tables, and how are they linked? (Draw it: boxes and arrows.)
2. Is there a natural identifier per table? Is it unique?
3. Which columns have a closed list of values?
4. Are there geometries, uncertain dates, stratigraphic relations, bibliographic references?
5. Who edits, who only reads, who must see only part of the data?
6. Do some records have to become read-only once validated?
7. Where do files live, and how are they named so they can be linked to records?

## How much work is a move

Typical order, from cheapest to most expensive step:

1. **Describe the schema** in DBML (a plain-text format) and import it in one go:
   tables, labels, vocabularies and foreign keys are created together
   ([DBML import](/guide/setup/dbml)). This is the step an assistant does well.
2. **Import the rows** from CSV or JSON, with a mapping from columns to fields and a key
   field for update-or-insert; the whole import is one transaction, so it either
   succeeds or changes nothing ([import](/guide/usage/import)).
3. **Check the result** with the schema validator and a few records opened by hand
   ([validation](/guide/setup/finalizing-setup)).
4. Users, groups and print templates, which depend on how you work, not on the data.

For a handful of well-formed tables this is an afternoon; the real cost is almost
always cleaning the source data (duplicates, free text that should be a vocabulary,
identifiers that are not unique), not BraDypUS itself.

## Go deeper

- [Creating an application](/guide/create-app/) and [Setup](/guide/setup/) — step by step
- [Search and filters](/guide/usage/search), [export](/guide/usage/export), [API](/guide/api/)
- [Developer documentation](/dev/) — architecture, database, record lifecycle
- For an assistant that has to **start a new application from existing data**, the
  repository ships a ready-made skill: `skills/bradypus-bootstrap` (see
  [llms.txt](https://docs.bdus.lad-sapienza.it/llms.txt)).
