---
title: Bibliography (Zotero)
---

# Bibliography (Zotero)

BraDypUS integrates with [Zotero](https://www.zotero.org/) to attach bibliographic
citations to individual records. A **Zotero** section appears at the bottom of
RecordView for any record in an application that has at least one Zotero library configured.

## Configuration (admin)

An admin must first connect a Zotero library. Open **Config → Zotero Libraries**
from the sidebar.

![ZoteroLibsPanel showing a list of configured libraries with library type and citation style](/images/v5/usage/zotero-libraries.png)

Click **Add library** and fill in:

| Field | Description |
|---|---|
| **Library type** | `user` or `group` |
| **Library ID** | Your Zotero user or group ID (found in Zotero settings) |
| **API key** | A read-only Zotero API key |
| **Citation style** | CSL style for formatted citations (default: `chicago-author-date`) |

The API key is stored server-side and never exposed to the browser.

## Attaching citations to a record

Open a record in RecordView. Scroll to the **Bibliography** section.

![ZoteroSection inside RecordView showing attached citations with formatted bibliography strings and a search panel](/images/v5/usage/zotero-record.png)

To add a citation:
1. Click **Search** and type a title, author or keyword.
2. Select the desired item from the results.
3. Optionally add page numbers or notes.
4. Click **Add**.

The citation appears in the list with its formatted bibliography string.

## Managing citations

- **Reorder** citations by dragging them.
- **Edit** page/notes by clicking the edit icon.
- **Remove** a citation with the delete icon.

## Sync

Zotero library data is cached locally. Click **Sync** on a record to refresh
the cached metadata for that record's citations. Admins can run a **Sync all**
from the Zotero Libraries panel to refresh all citations across all records.

A citation marked as **detached** means the original item was deleted or moved
in Zotero. The cached data is still displayed but the link to Zotero is broken.
