---
title: Find & replace
---

# Find & replace

The **Find & replace** module lets you bulk-update a field across all records
that match a search value. It is available from the sidebar (admin privilege required).

![SearchReplaceView with table selector, field selector, and find/replace fields](/images/v5/usage/find-replace.png)

## How to use

1. Select the **Table** from the first dropdown.
2. Select the **Field** to operate on from the second dropdown (the list updates based on the selected table).
3. Enter the value to **Find**.
4. Enter the **Replacement** value.
5. Click **Preview** to see how many records will be affected before committing.
6. Click **Replace** to apply the change.

## Case sensitivity

The search is exact (case-sensitive) by default. Partial matches within a field
value are replaced — i.e. if the field contains `"Early medieval"` and you search
for `"medieval"`, the entire occurrence will be replaced.

::: warning
Find & replace is a bulk operation and cannot be undone through the UI.
Create a [backup](/guide/usage/backup) before running large replacements.
:::
