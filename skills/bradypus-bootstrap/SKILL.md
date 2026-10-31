---
name: bradypus-bootstrap
description: Start a new BraDypUS application from existing data (spreadsheets, CSV, a legacy database) with an assistant, without following the step-by-step guide. Use when the user wants to move a dataset into BraDypUS, design its tables, or load it. Also use first, in "evaluate" mode, when the question is only whether BraDypUS is a good fit.
---

# Bootstrapping a BraDypUS application from existing data

BraDypUS is free, self-hosted software for relational data in the humanities and
cultural heritage. This skill takes you from "I have these files" to "the data is in a
running application", driving the same API the web app uses. Docs:
https://docs.bdus.lad-sapienza.it (index for assistants: `/llms.txt`).

Work in the order below and **stop at the checkpoints**: the user approves the schema
before anything is created, and the load before it is final.

## 0. Evaluate (cheap, do this first)

Read https://raw.githubusercontent.com/lad-sapienza/BraDypUS/v5/bdus-docs/guide/evaluate.md.
Look at the user's files and answer: how many tables, how are they linked, what is the
natural identifier of each, which columns are closed lists, is there geometry, vague
dates, stratigraphy, bibliography? Say plainly if BraDypUS is a poor fit (one flat table,
huge analytical data, public website needed). Stop here if the user only asked for an
assessment.

## 1. Design the schema (checkpoint: user approves)

Profile every file first: row count, distinct values per column, empty cells, duplicate
identifiers. Then propose, in plain words and **before writing any DBML**:

- one table per entity; each table's natural identifier;
- which columns become **vocabularies** (few distinct values, repeated) and which stay
  free text;
- which columns are **links** to another table (stored as that table's `id`);
- which tables need a plugin (geometry, stratigraphy, fuzzy dates, radiocarbon…);
- data problems to fix in the source (duplicates, mixed formats, non-unique ids).

Do not import dirty keys and hope: fix or flag them now.

## 2. Write the DBML

Tables and vocabularies are created from one DBML text
(guide: `bdus-docs/guide/setup/dbml.md`). Rules the importer enforces or relies on:

- Table and column names: lowercase ASCII, digits, underscore. Application name
  (step 3): `^[a-z][a-z0-9_-]{2,19}$`.
- **The primary key must be named `id`**: `id integer [pk, increment]`. Without it an
  `id` is added; with a different pk name the table is rejected (`pk_must_be_id`).
  Your own identifier (site code, inventory number) is a normal text column; mark it
  with `bdus:id_field`.
- `creator integer` is added automatically to every non-plugin table.
- **Only new tables are created.** Existing tables are never altered (skipped with
  `table_already_exists`). To change a table later, use Config → Fields, not DBML.
- DBML types map as: `varchar/text` short text or long text, `date`, `boolean`;
  integers and decimals are stored as text/integer fields and validated by rules.
  Override with `bdus:type=long_text|md|date|boolean|select|multi_select|combo_select`.
- Table note: `Note: 'bdus:label=Sites bdus:preview=id,name bdus:order=name bdus:id_field=name'`
  (`preview` = columns shown in lists; label may contain spaces).
- Plugins on a table: `bdus:rs=1` (stratigraphic relations), `bdus:geodata=1`,
  `bdus:fuzzy_date=1` (adds its `chrono_*` columns itself: do not declare them). Needs
  BraDypUS ≥ 5.16.2; on older versions `geodata` and `fuzzy_date` were ignored and the
  plugin is switched on by hand in Config → Tables.
- Vocabulary: an `Enum` whose last line is the comment `// bdus:vocabulary`; a column
  uses it with `[note: 'bdus:voc=<enum_name>']`. Enums without the marker are ignored.
- Link to another table: `site_id integer [ref: > sites.id]` creates the **relation**
  (the "linked records" panel on both tables, a real FOREIGN KEY and an index; the
  target may be defined later in the file). Add `note: 'bdus:id_from_tb'` as well to
  make the column a select that lists the other table's records. Only `>` refs are
  imported (a `<` ref is reported and skipped); policies with
  `bdus:on_delete` / `bdus:on_update` (`CASCADE`, `RESTRICT` default, `SET NULL`,
  `NO ACTION`). Needs BraDypUS ≥ 5.16.1; on older versions a plain `ref:` is ignored
  and the panel is added by hand in Config → Relations.

```dbml
Enum material {
  "ceramic"
  "bone"
  "stone"
  // bdus:vocabulary
}

Table sites {
  id   integer [pk, increment]
  name varchar
  Note: 'bdus:label=Sites bdus:preview=id,name bdus:id_field=name bdus:geodata=1'
}

Table finds {
  id       integer [pk, increment]
  creator  integer
  inv      varchar
  material varchar [note: 'bdus:voc=material']
  site_id  integer [ref: > sites.id, note: 'bdus:id_from_tb']
  notes    text
  Note: 'bdus:label=Finds bdus:preview=id,inv,material bdus:id_field=inv'
}
```

Show the DBML to the user (an ER sketch helps) and wait for approval.

## 3. Create the application and apply the schema

**Create the app.** Use the web form at the login page (needs `BRADYPUS_ALLOW_NEW_APP=1`),
or, in a Docker Compose instance:

```bash
docker compose exec -T api php bin/create-app.php \
  --name <slug> --engine sqlite --email <admin-email> --password-stdin
```

(`api` is the service of the production `bradypus.yml`; in the development
`docker-compose.yml` it is called `app`.) On a hosted instance without shell access, use
the web form: you become the application's super admin and everything below works the same.

(`pgsql`/`mysql`: the database must exist; `add-app.sh <instance-dir> …` at the
repository root also creates the Postgres database and role.) **Never put the admin
password on a command line or in chat: pass it on stdin, let the user type it.**
SQLite needs nothing else and is right for trying things out.

**Log in** (the user's super-admin account; ask the user to run this or provide a token,
do not handle their password if you can avoid it):

```
POST {base}/{app}/api/auth/login      {"email": "...", "password": "..."}   → {"token": "..."}
```

**Preview, then apply.** Both need the super-admin token (`Authorization: Bearer …`):

```
POST {base}/{app}/api/config/dbml/preview   {"dbml": "<text>"}   → check preview.has_errors
POST {base}/{app}/api/config/dbml/apply     {"dbml": "<text>"}
```

Fix every red error and read every warning in the preview before applying.
`GET {base}/{app}/api/config/dbml` exports the current schema as DBML: use it to confirm
what was created.

## 4. Load the data (checkpoint: user approves the result)

Load **parents before children**: vocabularies exist already; load `sites` before
`finds`, because a link stores the target's `id`. To fill a link column from a CSV that
only knows the parent's code, load the parent first, read its ids back
(`GET {base}/{app}/api/records/{tb}?limit=500` → `data[].id` and the identifier field),
and write a copy of the child CSV where the code is replaced by the id. Keep that copy:
it is the file you load, and re-running the import is safe.

For each table, with the data CSV or JSON (comma, semicolon or tab delimiter are
detected):

```
POST {base}/{app}/api/import/preview-file   multipart: type=csv|json, file=@data.csv
   → {"temp_id": "...", "columns": [...], "count": N, "rows": [...]}
POST {base}/{app}/api/import/data
   {"temp_id": "...", "type": "csv", "tb": "finds",
    "mapping": {"<csv column>": "<field>", ...}, "key_field": "inv"}
   → {"inserted": n, "updated": n, "total": n}
```

- `key_field` is the field used to match existing rows: a match is **updated**, a new
  value is **inserted**. Re-running an import is therefore safe and idempotent.
- The import is **one transaction**: any bad row rolls everything back and writes
  nothing. Fix the file and run it again.
- GeoJSON replaces the geometry of records already present (the table needs
  `bdus:geodata=1`): `POST /api/import/preview-file` with multipart `type=geojson`,
  `tb=<table>`, `file=@features.geojson` → `temp_id`, `geo_props`; then
  `POST /api/import/geojson {"temp_id", "tb", "geo_prop": "<property>", "key_field": "<field>"}`
  matches each feature's property to the table field. Photos (`/api/import/photos`)
  take a ZIP and an index CSV `filename,record_id`.
- Needs editor privilege or higher.

Then check, do not assume: counts per table against the source, a few records opened in
the app, the schema validator (Config → Validation).

## 5. What is still done in the interface

- **Relations** panel (Config → Relations) for relations the DBML could not express
  (a `<` ref, or a table that already existed).
- Users, groups, permissions and locks (the Users view, guide: `guide/setup/groups.md`):
  decide with the user who reads, edits and sees which rows; create accounts yourself
  only if the user asks. Rules work on whole rows and never check written values: if some
  roles must not see some columns, or records must move through states (draft → review →
  validated), say it needs SQL views plus triggers in the database
  (`guide/setup/views.md`; proven on MariaDB/MySQL only), not these screens.
- Print templates, map layers (GeoFace), Zotero, Pleiades, backups.

## If you cannot call the API (plain chat, no shell, no HTTP)

Most users will be in this situation, and it is fine: you prepare the files, they click.
Do steps 0–2 as written, then give them, one at a time:

1. **The DBML** to paste in *Config → DBML* (super admin; password asked again), then
   **Analyse** (read the preview together: red = errors, yellow = warnings) and **Apply**.
2. **One CSV per table, parents first**, saved as UTF-8, to load from *Import data* in the
   sidebar: choose the table, upload, map each column to its field, set the key field,
   run. For link columns the CSV must hold the parent's `id`: the *Id* column of the
   parent's list shows it (a table loaded empty, from file order, gets 1, 2, 3…, but ask
   the user to confirm from the list, never assume).
3. **Checks** to do by eye: record counts per table against the source, one record opened
   with its linked-records panel.

Same checkpoints and same ground rules; just say clearly what the user has to click and
what they should see after each click.

---

## Ground rules

- The user's data and credentials are theirs: no passwords in logs or chat, no uploading
  their files anywhere but their own BraDypUS instance.
- Ask before **destructive** actions (deleting tables or records). Table deletion cannot
  be undone; make a backup first (`guide/usage/backup.md`).
- When something is not covered here, read the page named above (raw Markdown on GitHub,
  `https://raw.githubusercontent.com/lad-sapienza/BraDypUS/v5/bdus-docs/…`) rather than
  guessing, and report the gap to the user.
