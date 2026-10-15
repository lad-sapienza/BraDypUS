---
title: Template reference
---

# Template reference <Badge type="tip" text="v5.0.0" />

Templates are JSON documents. This page documents all available properties.

## Top level

The template is a JSON **object** with a `sections` array. Sections are
rendered in the order they are listed. Fields not listed in the template are
not shown.

```json
{ "sections": [ ...section objects... ] }
```

## Section object

```json
{
  "label":       "Section heading",
  "collapsible": true,
  "collapsed":   false,
  "content":     [ ...items... ]
}
```

| Property | Type | Description |
|---|---|---|
| `label` | string | Section heading shown in RecordView |
| `content` | array | **Required** (may be empty). Items depend on the section kind, see below |
| `collapsible` | boolean | `true` to allow collapsing the section (not used with accordion) |
| `collapsed` | boolean | `true` to render it collapsed by default (needs `collapsible`) |
| `plugin` | string | Plugin table name: the whole section shows that plugin's rows |
| `type` | string | `"accordion"` to render `content` as sub-panels |

## Section kinds

### Core section (default)

`content` is a list of items, laid out on a 12-column grid:

| Item | Description |
|---|---|
| `{ "field": "name", "width": "1/2" }` | A core field |
| `{ "plugin": "tb", "field": "*", "width": "1/1" }` | All fields of a plugin, as a multi-row table |
| `{ "plugin": "tb", "field": "name", "width": "1/2" }` | One plugin field (consecutive items of the same plugin form one table) <Badge type="tip" text="v5.15.1" /> |

### Plugin section

With `"plugin": "table_name"` the section shows the rows of that sub-table.
`content` is a list of `{ field, width }` of plugin fields; an **empty list
shows all plugin fields**.

### Accordion section <Badge type="tip" text="v5.1.0" />

With `"type": "accordion"`, `content` is a list of panels:

| Panel property | Type | Description |
|---|---|---|
| `label` | string | Panel heading |
| `open` | boolean | `false` to render the panel collapsed (default `true`) |
| `fields` | array | `{ field, width }` items (core fields only) |

## Field widths

| Value | Grid columns (of 12) |
|---|---|
| `"1/1"` | 12 |
| `"3/4"` | 9 |
| `"2/3"` | 8 |
| `"1/2"` | 6 |
| `"1/3"` | 4 |
| `"1/4"` | 3 |

Any other value is rejected on save. Fields flow left to right and wrap when a
row is full; there is no way to stack fields in a column next to others.

## Validation

On save the server checks that every `field` exists in the table (or plugin),
that every `plugin` is a plugin of the table, that `width` is one of the values
above, and that `content` is present on every section. Errors are listed above
the editor and nothing is saved.

## Notes

- A template applies to both read and edit modes in RecordView.
- Images, links and bibliography are handled by RecordView itself and are not
  part of the template.
