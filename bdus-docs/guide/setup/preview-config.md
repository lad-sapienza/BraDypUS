---
title: Field preview
---

# Field preview

**Preview fields** control which columns appear in the record list (DataView) and
which fields are searched by the fast-search box.

## Setting preview fields

In **Config → Tables**, select a table and edit the **Preview fields** property.
Enter a comma-separated list of field names in the order you want them to appear:

```
sigla, descrizione, periodo
```

The record list will show exactly these columns, in this order.
The first preview field is also used as the record's display label in cross-table
dropdowns and in the Harris Matrix node labels.

![DataView showing a record list with the configured preview columns](/images/v5/setup/preview-fields-result.png)

## Column toggler

In DataView the user can show or hide individual columns using the **column toggler**
button in the toolbar. The toggler starts with the preview fields visible but the
user's selection is remembered per session.

## Fast search scope

The fast-search input searches all preview fields using a case-insensitive `LIKE`
query. Fields not in the preview list are not searched by fast search (but are
searchable in the advanced search panel).
