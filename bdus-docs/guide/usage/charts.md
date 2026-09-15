---
title: Charts
---

# Charts <Badge type="tip" text="v5.0.0" />

BraDypUS can generate charts from the data in any table. Charts are private
to their creator by default and can be shared with all users of the
application (see [Sharing and access](#sharing-and-access)).

Open the **Charts** entry in the main menu to see every chart available to
you, run one, or create a new one — it's a full page of its own, not a panel
tucked inside a table view.

![Full-page list of saved charts, with a "New chart" button and per-chart edit/share/delete actions](/images/v5/usage/chart-list.png)

Click a chart's **name** to run it full-width. Owners get three extra
actions on their own charts: edit, share/unshare, and delete.

## Creating a chart

Click **New chart** and pick a table, then configure the chart itself:

![The new-chart wizard: table picker, chart type/fields/function, and a live preview once run](/images/v5/usage/chart-wizard.png)

| Field | Description |
|---|---|
| **Chart type** | `bar`, `line`, `pie`, `doughnut`, or `metric` (single number) |
| **Group by field** | The field to group by (horizontal axis / pie segments) — hidden for `metric` |
| **Value field** | The field to aggregate |
| **Function** | Aggregation: `COUNT`, `COUNT DISTINCT`, `SUM`, `AVG`, `MIN`, `MAX` |

**Style options** (collapsible) let you set a custom color, legend position,
horizontal bars, Y-axis min/max, and decimal places.

Click **Run** to preview the chart against the live data, then give it a
name and click **Save**.

## Filtering a chart

The wizard itself doesn't build filters — a chart's table is fixed once
created, and picking a filter from scratch there would just duplicate
DataView's own search UI. Instead, build the filter where you already build
every other search: open the table in **Data management**, run a fast,
advanced, or SQL-expert search, then click the chart icon
(**Create chart from this view**) in the search toolbar.

![The wizard opened from a search: the table is locked and a note confirms the chart uses that search's filter](/images/v5/usage/chart-from-search.png)

The wizard opens pre-filled: the table is locked (it's implied by the search
you came from) and a note confirms the chart will run against that same
filter. Everything else — type, fields, style — works exactly as for an
unfiltered chart. A chart created this way keeps its filter for good; to
change it, create a new chart from a different search rather than editing
the filter in place.

A chart created without an active search (or via the plain **New chart**
button) runs over every row of its table.

## Viewing a chart

![A rendered pie chart, opened full-width from the charts list](/images/v5/usage/chart-view.png)

The chart reflects the live data and its own filter (if any) every time it
runs — nothing is cached from when it was saved.

## Sharing and access

A new chart is **private**: only its creator sees it in the list. The owner
can share it with all users of the application (star icon) and unshare it at
any time. Shared charts can be run by anyone but edited or deleted only by
their owner.

## Editing and deleting a chart

Click the pencil icon on a chart you own to reopen the wizard, pre-filled
with its current name, type, fields, and style — everything except the
table and filter, which stay fixed from creation. Click **Delete** to remove
a chart's definition; no record data is ever affected.
