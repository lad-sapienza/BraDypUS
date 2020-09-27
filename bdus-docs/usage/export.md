---
title: Data export
---

You can use BraDypus to export your data in various format. At present 
(v. 4.0.0-alpha.174), the following formats are available:
- JSON
- XLS
- SQL (insert statements)
- CSV
- HTML
- XML

Also you can choose to export the whole table, using the **Export** button
in the main page:

![screenshot](../../images/usage/export-all.png "Export all")
*Export all*

Or you can export a subset of the table, ie. the results of a query:

![screenshot](../../images/usage/export-query.png "Export query")
*Export query*

Exported files are saved in the `export/` folder of the project
folder and can be viewed, downloaded or erased from the dashboard, by clicking
on **Available exported files**:

![screenshot](../../images/usage/export-list.png "Available exports")
*Available exports*


{: .callout-block .callout-block-danger }
The Export feature will export **only the information contained in the data-tables**.
Linked plugin data will not be exported.


