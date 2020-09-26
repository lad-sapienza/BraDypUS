---
title: ShortSql Examples
---


The following examples assume that `test` application is being used, e.g. `/api/v2/test/?verb=search&shortsql=`

---

### Example #1. Minimal case

The string:

```txt
@sites
```

is parsed as 

```SQL
SELECT * FROM test__sites WHERE 1=1
```

If only the table name is provided, it is assumed that all columns and all rows are being retrieved.

---

### Example #2: columns

The fields block can be used to retrieve only some columns:

```txt
@sites~[name,typology
```

is parsed as

```SQL
SELECT test__sites.name, test__sites.typology FROM test__sites WHERE 1=1
```

---

### Example #3: column aliases

Aliases can be provided for column names:

```txt
@sites~[name:Site name,typology:Site typology
```

is parsed as

```SQL
SELECT test__sites.name AS "Site name", test__sites.typology AS "Site typology" FROM test__sites WHERE 1=1
```

---

### Example #4: ordering

Records can be orderd by one column

```txt
@sites~[name:Site name,typology:Site typology~>name:asc
```

is parsed as

```SQL
SELECT 
    test__sites.name AS "Site name", 
    test__sites.typology AS "Site typology" 
  FROM test__sites 
  WHERE 1=1 
  ORDER BY test__sites.name ASC
```

or many columns, in bosth directions (asc and desc):

```txt
@sites~[name:Site name,typology:Site typology~>name:asc,typology:desc
```

is parsed as

```SQL
SELECT 
    test__sites.name AS "Site name", 
    test__sites.typology AS "Site typology" 
  FROM test__sites 
  WHERE 1=1 
  ORDER BY 
    test__sites.name ASC, 
    test__sites.typology DESC
```

---

### Example #5: limit

Results can be limited

```txt
@sites~-30:0
```

is parsed as

```SQL
SELECT test__sites.* FROM test__sites WHERE 1=1 LIMIT 30 OFFSET 0
```

Remember both Limit and Offset must be provided, as MySQL-like statements, such as
`LIMIT 30` ar not supported.

---

### Example #6: grouping

Results can be grouped using one column

```txt
@sites~*typology
```

is parsed as

```SQL
SELECT test__sites.typology FROM test__sites WHERE 1=1 GROUP BY test__sites.typology
```

Or many columns

```txt
@sites~*typology,chronology
```

is parsed as

```SQL
SELECT test__sites.typology, test__sites.chronology FROM test__sites WHERE 1=1 GROUP BY test__sites.typology, test__sites.chronology
```

{: .callout-block .callout-block-warning}
Please note that, as can be observed form the examples above, grouping also sets the column list,
since ANSI SQL does not support columns not used in aggregate functions as `GROUP BY`.
Explicitely provided columns will be thus ignored:  
`@sites~[id,name~*typology`  
will be parsed as  
`SELECT test__sites.typology FROM test__sites WHERE 1=1 GROUP BY test__sites.typology`

---

### Example #7: simple where

```txt
@sites~?name|=|site-01
```

is parsed as

```SQL
SELECT test__sites.* FROM test__sites WHERE test__sites.name = 'site-01'
```

---

### Example #8: simple where using like and wildcard

```txt
@sites~?name|like|site-%
```

is parsed as

```SQL
SELECT 
    test__sites.* 
  FROM test__sites 
  WHERE 
    test__sites.name LIKE 'site-%'
```

---

### Example #9: where using more statements

```txt
@sites~?name|like|site-%||and|typology|=|large settlement
```

is parsed as

```SQL
SELECT 
    test__sites.* 
  FROM test__sites 
  WHERE 
    test__sites.name LIKE 'site-%' 
    AND 
    test__sites.typology = 'large settlement'
```

---

### Example #10: searching in plugins / autojoin

```txt
@sites~?test__m_citations.short|=|Doe 2020
```

is parsed as

```SQL
SELECT test__sites.*
  FROM test__sites
       JOIN
       test__m_citations ON test__m_citations.table_link = 'test__sites' AND 
                            test__m_citations.id_link = test__sites.id
       JOIN
       test__bibliography AS test__bibliography5f6f891a1ec6d ON test__m_citations.short = test__bibliography5f6f891a1ec6d.id
 WHERE test__bibliography5f6f891a1ec6d.short = 'Doe 2020';
```

Lots of things are magically happening here. Let's explain the result SQL.

The first two lines are expected and contain the list of columns to retrive, 
all of them since no column is explicitly listed (`test__sites.*`) and the main table (`test__sites`).

But, since we are quering a plugin column (`test__m_citations.short`: please note that the full name **must**
be provided as it is very hard to guess, since tablea might have many plugins with similar column names), it
must be explictly joined. Join information come from main configuration files. These are lines 3-5.

Furthermore, the column we are quering, ie. `short` happens to be a foreign key, since the policy
for its compilations is set to `id_from_tb` ([documentation here](/setup/adding-columns#id_from_tb)).
This means it contains only the refence to the table where the actual string is saved, and if
we want to search a string this second table must be joined. These are lines 6-7. 

The joined table is aliased with a random string postfix, since it might happen to be joined more than one time.

{: .callout-block .callout-block-success}
Finally, you do not really need to know the complexity behind this simple ShortSQL statement. 
You just need to know that you **can query also plugin columns** and you do **not have to worry** about
values stored in the database

---

### Example #11: Autojoin by requesting plugin column

```txt
@sites~[id,name,test__geodata.geometry
```

is parsed as

```SQL
SELECT test__sites.id,
       test__sites.name,
       test__geodata.geometry
  FROM test__sites
       JOIN
       test__geodata AS test__geodata ON table_link = 'test__sites' AND 
                                         id_link = test__sites.id
 WHERE 1 = 1;
```

The table `test__geodata` is joined automatically since at least one of its columns
is mentioned in the column list. Unique postfixes are automatically set.

---

### Example #12: Joins

```txt
@su~[su.*,test__sites.*~+sites||id|=|^su.site
```

is parsed as

```SQL
SELECT 
    test__su.*, 
    test__sites.* 
  FROM test__su 
  JOIN test__sites ON test__sites.id = test__su.site
  WHERE 1=1
```

Please note that caret used before the value in WHERE (and ON) statement 
(`^su.sites`) indicates that the following value must not be interpreted 
as string (wrapped in single quotes). In this case it is a column name.

---



## Caveat
In order to use the string in a query string it must be url-encoded, es:
```js
// javascript
const urisafe = encodeURIComponent(shortSql);
```
or
```php
// php
$urisafe = urlencode($shortSql);
```
