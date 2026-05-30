---
title: Vocabulary
---

# Vocabulary

Key terms used throughout this documentation.

---

### Application (app)

A self-contained BraDypUS database project. Multiple applications can coexist
on the same server, each with its own data, users, and configuration.

---

### Controller

A PHP class in `controllers/` (namespace `Bdus\Controllers\*`) that handles
HTTP requests and returns JSON responses. All controllers extend `Bdus\Controller`.

---

### Filter (Directus-style)

The search format used by the API: `{ "field": { "_operator": "value" } }`.
Supports `_eq`, `_icontains`, `_gt`, `_empty`, `_and`, `_or`, and more.
Replaces the legacy ShortSQL format removed in v5.

---

### GeoFace

The geographic map view, powered by MapLibre GL JS. Displays records that
have geodata (WKT geometries) on an interactive map. Supports WMS, WFS and
local GeoJSON/KML layers.

---

### JWT (JSON Web Token)

The authentication mechanism used by BraDypUS v5. A signed token issued at
login is sent with every request in the `Authorization: Bearer` header.
Tokens expire after 8 hours. No PHP sessions are used.

---

### Migration

A one-time DB schema change tracked in `bdus_migrations`. Migrations run
automatically on login and are idempotent (safe to apply multiple times).

---

### Plugin table

A sub-table attached to a regular table, showing a one-to-many group of
related data inside a parent record (e.g. `finds` inside a `context`).
Plugin tables require `table_link` (TEXT) and `id_link` (INT) columns.

---

### Preview fields

The columns displayed in the record list (DataView) and searched by fast search.
Configured per-table in Config → Tables.

---

### RS (Rapporti Stratigrafici)

The stratigraphic relations module. Manages directed relationships between
stratigraphic units (covers, cuts, abuts, fills) and renders them as a
Harris Matrix graph.

---

### Super-admin

The highest privilege level (1). Can access Config (schema changes), user
management, backups, and all data. Required for structural operations.

---

### System table

A database table managed by BraDypUS (prefixed `bdus_`). Stores users, logs,
versions, files, geodata, vocabulary items, etc. Structure is fixed.

---

### Template (print template)

A JSON document defining a custom display layout for a record type. Created
and edited in the Templates view. Used to control the visual order and grouping
of fields in RecordView.

---

### Vocabulary (controlled list)

A named list of allowed values for `select`, `multi_select` and `combo_select`
fields. Managed in the Vocabularies view.

---

### Widget

A per-application JavaScript ES module (`projects/{app}/widgets/{name}.js`)
that provides a custom display component for a field. Loaded dynamically with
JWT auth.
