---
title: Advices & hacks
---

# Advices & hacks

Practical tips for designing a robust BraDypUS application.

## Name fields carefully

Internal field names cannot be changed without a DB rename operation. Spend time
choosing clear, lowercase, underscore-separated names before going live.

Good: `site_code`, `discovery_date`, `material_type`  
Avoid: `siteCode`, `date1`, `x`

## Use vocabularies for controlled fields

Even if a field currently has only a handful of values, using a `select` field backed
by a vocabulary makes it easy to add values later and keeps data consistent.

## Preview fields drive usability

Put the most identifying fields first in your **Preview fields** list. The first
preview field appears in FK dropdowns across the app — make it human-readable and
unique (e.g. a site code, not an auto-increment id).

## Plugin tables for repeating groups

If a record can have multiple sub-items of the same type (e.g. a context can have
multiple finds), model the sub-items as a plugin table rather than multiple fields.
Plugin tables are shown as editable inline grids inside RecordView.

## Use foreign key fields to avoid data duplication

Instead of typing a site name in every context record, create a `sites` table and
use a `select` field with **ID from table** set to `sites` in the `contexts` table.
This makes renaming sites trivial and enables cross-table search.

## RS / Harris Matrix requires a `sigla` field

The Harris Matrix module needs a field to use as the node label. This is configured
as the **RS field** in the table settings — typically the stratigraphic unit code.

## Backup before any structural change

Drop a backup from **Backup** in the sidebar before adding, renaming or removing tables
or fields. The validation panel catches some problems, but a backup is always the safest fallback.
