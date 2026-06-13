---
title: Charts
---

# Charts

BraDypUS can generate charts from the data in any table. Charts are private
to their creator by default and can be shared with all users of the
application (see [Sharing and access](#sharing-and-access)).

Open a chart from the **Charts** panel inside RecordView, or from the chart
icon in the sidebar if a standalone chart view is available.

## Creating a chart

Inside any table's DataView, click the **Charts** button in the toolbar to
open the chart panel. Click **New chart** and configure:

![TODO_SCREENSHOT: Chart configuration form showing table, chart type, and axis field selectors](/images/v5/usage/chart-config.png)

| Field | Description |
|---|---|
| **Name** | Label for this chart |
| **Chart type** | `bar`, `line`, `pie`, `doughnut`, or `metric` (single number) |
| **X field** | The field to group by (horizontal axis / pie segments) |
| **Y field** | The field to aggregate |
| **Y function** | Aggregation: `COUNT`, `SUM`, `AVG`, `MIN`, `MAX` |

Click **Save** to create the chart. It appears in the chart list and can be
opened by any user.

## Viewing a chart

![TODO_SCREENSHOT: A rendered pie chart showing the distribution of records by period](/images/v5/usage/chart-view.png)

The chart updates in real-time from the current data. Use the **Filter** toggle
to apply the active DataView search filter to the chart data.

The saved-charts list shows **all** your charts, including those created on
other tables: they appear dimmed, with the name of their table next to them.
Running one executes it against its own table — the table currently open in
DataView does not matter.

## Sharing and access

A new chart is **private**: only its creator sees it in the list. The owner
can share it with all users of the application (star icon) and unshare it at
any time. Shared charts can be run by anyone but edited or deleted only by
their owner.

## Deleting a chart

Click **Delete** in the chart list. Only the chart definition is deleted — no
record data is affected.
