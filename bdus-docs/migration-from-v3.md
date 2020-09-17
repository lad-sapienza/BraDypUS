# Migration from v3

## TL;DR
- `db_engine` value in app_data.json is now required, should be set to "sqlite" for previous applications
- Rename column `charts.query` to `charts.sql`
- Rename column `queries.table` to `queries.tb`
- Add column `queries.vals TEXT`
- Optionally create table `versions`

---

## Introduction

Bradypus v4 is a progressive rewrite and refactor of the previous v3, 
that at presenent regards involves only in a minimal degree the graphical user interface.

Core libraries and modules are being totally rewritten, maintaining the current funcionality.

Yet, some important changes in overall functionality have been put in place, with some scarcely used
functions that have been dropped.

---

## Dropped features

#### Search: most recent records
The functionality of searching `n most recent records` is easily replaceble with the `search all`
and sorting by `id` field in inverse order (DESC) by simply clickin in the table header.


### Sync
The sync function was used by online applications and permitted to synchonize the actual 
database applcation with the online version. The synchronozation process was performed via FTP
and a special FTP account should have been available on the remote server. This is a highly
**insecure** feature and totally relied in external settings (FTP account) that are not always
available out-of-the-box. No GUI alternative is available.

### Deprecated print.sqlSum
The function `print.sqlSum` in the templates has been deprecated and no replacement has been provided.
The funcion is scarcely (if never) use.d

---

## Database schema

No changes in the database structure have been introduced for the data tables, but some minor changes
regard system tables, and are thoroghly descibed below.

<p class="bg-warning p-3">In the following detailed description the prefix has been dropped form the table name for the sake
of clarity. No change has put in place regarding the useof appication prefix.</p>

### Renamed column charts.query

The column `charts.query` has been renamed to `charts.sql`, since `query` is a SQL reserved keyword.
No changes in field type has been made.

To upgrade the database, please run on of the following statemente, depending on the database engine used.  
**Remember to change the table prefix**:

- **sqlite**:
```sql
ALTER TABLE prefix__charts RENAME COLUMN query TO sql;
```
- **mysql**:
```sql
ALTER TABLE prefix__charts RENAME COLUMN query TO sql TEXT;
```
- **pgsql**: 
```sql
ALTER TABLE prefix__charts RENAME COLUMN query TO sql;
```

### Renamed column queries.table

The column `queries.table` has been renamed to `queries.tb`, since `table` is a SQL reserved keyword.
No changes in field type has been made.

To upgrade the database, please run on of the following statemente, depending on the database engine used.  
**Remember to change the table prefix**:

- **sqlite**: 
```sql
ALTER TABLE prefix__queries RENAME COLUMN table TO tb;
```
- **mysql**: 
```sql
ALTER TABLE prefix__queries RENAME COLUMN table TO tb TEXT;
```
- **pgsql**: 
```sql
ALTER TABLE prefix__queries RENAME COLUMN table TO tb;
```

### Added column queries.vals

The column `queries.vals` has been added and was not available in v3.

To upgrade the database, please run on of the following statemente, depending on the database engine used.  
**Remember to change the table prefix**:

- **sqlite**: 
```sql
ALTER TABLE prefix__queries ADD COLUMN vals TEXT;
```
- **mysql**: 
```sql
ALTER TABLE prefix__queries ADD vals TEXT;
```
- **pgsql**: 
```sql
ALTER TABLE prefix__queries RENAME COLUMN vals TEXT;
```

### Added table logs

In v3 system errors, infos and warnings where logged to a dedicated SQLite database
named meta.sqlite and saved in the `db` directory, the same directory where the main database
(`bdus.sqlite`) was located.

Since v4 this information is saved within the main database in a dedicated table
called `logs`. This change does not affect in anyhow the user experience and **does
not require** any upgrade action from system administrators. Bradypus will automatically
create the logs table, if it does not exist on initialization.

If no hybrid usage of v3 and v4 is planned, it is safe to remove `db/meta.sqlite`.

### Added table versions

In v3 previous snapshots of each edited record where saved to a dedicated SQLite database
named meta.sqlite and saved in the `db` directory, the same directory where the main database
(`bdus.sqlite`) was located.

Since v4 this information is saved within the main database in a dedicated table
called `versions`. This change does not affect in anyhow the user experience, but 
**requires** some work by system administrators, that **must manually create** the table,
if they want to activate versioning. Otherwize, no error will be triggerd, but versions will not be available.



If no hybrid usage of v3 and v4 is planned, it is safe to remove `db/meta.sqlite`.

- **sqlite**
```sql
CREATE TABLE prefix__versions (
    id         INTEGER            PRIMARY KEY AUTOINCREMENT,
    user       INTEGER            NOT NULL,
    time       [INTEGER UNSIGNED] NOT NULL,
    tb         TEXT               NOT NULL,
    rowid      INTEGER            NOT NULL,
    content    TEXT               NOT NULL,
    editsql    TEXT               NOT NULL,
    editvalues TEXT
);
```
- **mysql**
```sql
CREATE TABLE prefix__versions (
    id         INTEGER            PRIMARY KEY AUTO_INCREMENT,
    user       INTEGER            NOT NULL,
    time       INTEGER UNSIGNED   NOT NULL,
    tb         TEXT               NOT NULL,
    rowid      INTEGER            NOT NULL,
    content    TEXT               NOT NULL,
    editsql    TEXT               NOT NULL,
    editvalues TEXT
);
```
- **pgsql**
```sql
CREATE TABLE prefix__versions (
    id         SERIAL,
    user       INTEGER            NOT NULL,
    time       INTEGER UNSIGNED   NOT NULL,
    tb         TEXT               NOT NULL,
    rowid      INTEGER            NOT NULL,
    content    TEXT               NOT NULL,
    editsql    TEXT               NOT NULL,
    editvalues TEXT
);
```

### Configuration changes

The db_engine key of the app_data.json congiguration file is not optional anymore,
since SQLite is not the only supported database engine.
For applications migrating from v3, if not already set, should be set as follows:

```json
"db_engine": "sqlite"
```