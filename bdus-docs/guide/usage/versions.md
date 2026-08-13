---
title: Version history
---

# Version history

BraDypUS keeps an automatic snapshot of every record every time it is saved or
deleted. The **Version history** drawer lets you browse these snapshots, compare
them with the current state, and restore a previous version.

## Opening the version history

Open any record in RecordView and click **Version history** in the record header.

![RecordView header with the Version history button highlighted](/images/v5/usage/versions-button.png)

A drawer slides in from the right listing all snapshots, newest first.

![Version history drawer showing a list of snapshots with operation badge, timestamp and user](/images/v5/usage/versions-list.png)

## Snapshot list

Each entry shows:

- **Operation badge** — `update`, `delete` or `restore`
- **Timestamp** — when the change was made
- **User** — who made the change

## Comparing a snapshot

Click a snapshot to load the diff view. Changed fields are highlighted;
unchanged fields are dimmed.

![Version history diff view showing changed fields in colour and unchanged fields greyed out](/images/v5/usage/versions-diff.png)

Select which fields to restore using the checkboxes, or use **Select all** to
restore the entire record to that snapshot.

## Restoring a snapshot

::: warning Admin required
The restore button is only visible to users with admin privilege or higher.
:::

After selecting fields (or all), click **Restore selected**. A confirmation
dialog appears before the restore is applied.

On success, the record is updated and the version history shows a new
`restore` entry.

## Deleted records

If a record has been soft-deleted, the version history shows its last known
state with a banner indicating it was deleted. Restoring from a deleted record
snapshot recreates the record with all its previous field values.

See also: [Deleted records](/guide/usage/deleted-records).
