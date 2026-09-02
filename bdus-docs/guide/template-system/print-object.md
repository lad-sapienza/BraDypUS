---
title: Template reference
---

# Template reference <Badge type="tip" text="v5.0.0" />

Templates are JSON documents. This page documents all available properties.

## Top level

The template is a JSON **array** of section objects.

## Section object

```json
{
  "label":    "Section heading",
  "plugin":   null,
  "collapse": false,
  "rows":     [ ...row objects... ]
}
```

| Property | Type | Description |
|---|---|---|
| `label` | string | Section heading shown in RecordView |
| `plugin` | string \| null | If set, the section displays rows from this plugin table instead of the main table |
| `collapse` | boolean | `true` to render the section collapsed by default |
| `rows` | array | Array of row objects |

## Row object

```json
{
  "fields": [ ...field objects... ]
}
```

A row is rendered as a horizontal group of fields. Field widths in a row
should sum to 12.

## Field object

```json
{
  "field": "field_name",
  "width": 6
}
```

| Property | Type | Description |
|---|---|---|
| `field` | string | The internal field name as defined in Config → Fields |
| `width` | integer | Display width on a 12-column grid (1–12) |

## Notes

- Fields not listed in the template are hidden.
- Fields listed but not present in the table config are silently ignored.
- Plugin sections can only reference fields from the named plugin table.
- A template applies to both read and edit modes in RecordView.
