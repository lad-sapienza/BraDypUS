---
title: Validation
---

# Validation

The **Validation** panel checks whether your application's DB schema matches its
configuration. Run it after any structural change (add/remove table or field) to
catch problems before they affect users.

Open it from **Config → Validation**.

![Validation panel showing a list of checks with green/red status badges](/images/v5/setup/validation.png)

## What it checks

| Check | Description |
|---|---|
| **Filesystem** | Verifies that `config.json` and `.jwt_secret` are not publicly web-accessible |
| **Missing tables** | Config references a table that does not exist in the DB |
| **Missing columns** | Config references a field that does not exist in a DB table |
| **Orphan FK** | A `select` field with **ID from table** references a table that does not exist |
| **Missing vocabulary** | A `select` field references a vocabulary list that is empty or missing |
| **Plugin references** | Plugin tables listed in a parent table's config actually exist |

## Fix button

Most issues have a **Fix** button next to them. Clicking it applies the recommended
correction automatically (e.g. creating the missing DB column or table).

::: tip
Always run Validation after importing data from an older version of BraDypUS, after
restoring a backup, or after editing config files manually.
:::
