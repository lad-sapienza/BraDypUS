---
title: Backup
---

{: .callout-block .callout-block-warning }
Backups can be created by edit and above users;  
backups can be deleted by admin and above users;  
backups can be resored by super-admin users.

Backups are a very important maintainance and security feature of Bradypus
database.
The backup system has been re-writen from scratch in v.4 and is still going 
to be implemennted in the future.

At present, all three main database engines, SQLite, MySQL/MariaDB and PostreSQL
are supported, via `mysqldump`, `pg_dump` and `sqlite3` executables that **must**
be installed.

Backups are gzipped SQL files containing structure and data, and are engine-dependent.
This means that you cannot use this feature for migrations, ie. you **can not** import
into MySQL a SQLite backup.

{: .callout-block .callout-block-danger }
At present (v. 4.0.0-alpha.174) PostgreSQL backup cannot be restored by 
one-click procedure, like MySQL and SQLite ones.


![screenshot](../../images/usage/backups.png "Backups")
*Backups*

