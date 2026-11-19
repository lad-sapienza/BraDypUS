---
title: Vocabularies
---

# Vocabularies <Badge type="tip" text="v5.0.0" />

A vocabulary is a controlled list of values used to populate `select`,
`multi_select` and `combo_select` fields.

Open **Vocabularies** from the sidebar navigation (not from Config).

![VocabulariesView showing a list selector and the values for the selected list](/images/v5/setup/vocabularies.png)

## Structure

Each vocabulary belongs to a named **list**. The list name is what you set in
the **Vocabulary** property of a field in Config → Fields.

Example: a field `periodo` of type `select` with vocabulary `periodi` will show
the values from the `periodi` list.

## Adding values

1. Select the vocabulary list from the left panel (or create a new one with **Add list**).
2. Click **Add value** and type the new entry.
3. The value is immediately available in the field dropdown.

## Labels: a stored code and a longer name <Badge type="tip" text="v5.26.0" />

An entry can have an optional **label**: the **value** is what is stored in the record
(for example `CER`), the label is what people read (`Ceramica`). Double-click the *Label*
column of a value, or fill it in when adding the value; leave it empty to have none.

- In forms the drop-down shows the label and saves the value; in the record view the label
  is shown in place of the value (for `multi_select`, one label per value).
- An entry **without** a label behaves exactly as before: the value is shown and stored.
  Existing vocabularies need no change, and no stored or exported value changes.
- Lists of results, filters, charts and exports still show the stored **value**; the label
  is for forms and the record view.
- The MCP `vocabulary` tool returns `{ value, label }` pairs (the label equals the value when
  there is none); the REST `POST /api/vocabularies` and `PATCH /api/vocabulary/{id}` accept
  an optional `label`.

## Editing and deleting values

Click any value to edit its text inline. Use the delete icon to remove it.

::: warning
Deleting a vocabulary value does not update existing records that already use it.
Records will retain the old value; they will just not have it available in the dropdown
for future edits.
:::

## Reordering values

Drag rows to change the display order in dropdowns.

## `combo_select` fields

A `combo_select` field shows vocabulary values as autocomplete suggestions but also
allows free-text input. This is useful when you want guidance without strict enforcement.
