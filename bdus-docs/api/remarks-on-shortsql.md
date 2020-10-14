---
title: Remarks on ShortSql
---


ShortSQL tries to return dialect-agnostic SQL, which can be run on the main database engine supported by BraDypUS,
SQLite, MySQL/MariaDB and PostgreSQL. This means that:
- no backticks (**`**) are used to mark table or column names. In general, table ane column names **are not** marked;
- column aliases are marked by double quotes (**"**). This is supported in all the mentioned database engines;
- offset is clearly stated in limit statement and is never ommitted, whitch is a MySQL-limited feature.

## Caveat
In order to use the ShortSQL string in a query string it must be url-encoded, e.g.:
```js
// javascript
const urisafe = encodeURIComponent(shortSql);
```
or
```php
// php
$urisafe = urlencode($shortSql);
```
