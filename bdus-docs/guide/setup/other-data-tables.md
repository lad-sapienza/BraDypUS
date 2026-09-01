---
title: Other data tables
---

# Other data tables

An application can have as many tables as needed. Each table is created in
**Config → Tables** following the same workflow described in [Tables](/guide/setup/create-table-sites).

## Table types

### Main tables

Regular top-level tables that appear in the sidebar navigation. Each main table
has its own record list (DataView) accessible from the sidebar.

### Plugin tables

A plugin table is a sub-table attached to a parent. Plugin rows appear as an
inline editable grid inside the parent record's RecordView.

To make a table a plugin:
1. In **Config → Tables**, enable **Is plugin** when creating or editing the table.
2. In the parent table's settings, add the plugin table to **Plugin tables**.

A plugin table is never shown directly in the sidebar — it is only accessible
through its parent records.

### Example

An archaeological application might have:
- `contexts` — main table, visible in navigation
- `finds` — plugin table attached to `contexts`
- `samples` — plugin table attached to `contexts`
- `periods` — lookup table referenced by `select` (ID from table) fields in `contexts`
