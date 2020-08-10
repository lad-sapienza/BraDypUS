# ShortSql Examples


## Only table parameter available

`@places`

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places

```SQL
SELECT 
    `paths__places`.* 
FROM 
    `paths__places`  
WHERE 1=1
```

---

## Table name and with field list

```
@places~
[id:PAThs%20Place%20Id,name,tmgeo,pleiades
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~[id:PAThs%20Place%20Id,name,tmgeo,pleiades

```SQL
SELECT 
    `paths__places`.`id`, 
    `paths__places`.`name`, 
    `paths__places`.`tmgeo`, 
    `paths__places`.`pleiades` 
FROM 
    `paths__places`  
WHERE 1=1
```

# Table with field list with aliases

```
places~
[id:PAThs Place Id,name,tmgeo,pleiades
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~[id:PAThs%20Place%20Id,name,tmgeo,pleiades

```SQL
SELECT
    `paths__places`.`id` AS `PAThs Place Id`, 
    `paths__places`.`name`, 
    `paths__places`.`tmgeo`, 
    `paths__places`.`pleiades` 
FROM 
    `paths__places`
WHERE 1=1
```

# Sorting

```
@places~
[id:PAThs Place Id,name,tmgeo,pleiades~
>tmgeo:asc
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~[id:PAThs%20Place%20Id,name,tmgeo,pleiades~>tmgeo:asc

```SQL
SELECT 
    `paths__places`.`id` AS `PAThs Place Id`, 
    `paths__places`.`name`, 
    `paths__places`.`tmgeo`, 
    `paths__places`.`pleiades` 
FROM 
    `paths__places`  
WHERE 1=1
ORDER BY `paths__places`.`tmgeo` ASC
```

# More sorting

```
@places~
[id:PAThs Place Id,name,tmgeo,pleiades~
>tmgeo:asc~
>pleiades:asc
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~[id:PAThs%20Place%20Id,name,tmgeo,pleiades~>tmgeo:asc~>pleiades:asc

```SQL
SELECT 
    `paths__places`.`id` AS `PAThs Place Id`, 
    `paths__places`.`name`, 
    `paths__places`.`tmgeo`, 
    `paths__places`.`pleiades` 
FROM 
    `paths__places`  
WHERE 1=1
ORDER BY 
    `paths__places`.`tmgeo` ASC,
    `paths__places`.`pleiades` ASC
```

# Limiting

```
@places~
[id:PAThs Place Id,name,tmgeo,pleiades~
>tmgeo:asc~
-10:0
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~[id:PAThs%20Place%20Id,name,tmgeo,pleiades~>tmgeo:asc~-10:0

```SQL
SELECT 
    `paths__places`.`id` AS `PAThs Place Id`, 
    `paths__places`.`name`, 
    `paths__places`.`tmgeo`, 
    `paths__places`.`pleiades` 
FROM 
    `paths__places`  
WHERE 1=1
ORDER BY `paths__places`.`tmgeo` ASC
LIMIT 10 OFFSET 0
```

# Grouping

```
@places~
[id,name,tmgeo,pleiades~
*tmgeo
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~[id,name,tmgeo,pleiades~*tmgeo

```SQL
SELECT 
    `paths__places`.`id`, 
    `paths__places`.`name`, 
    `paths__places`.`tmgeo`, 
    `paths__places`.`pleiades` 
FROM 
    `paths__places`  
WHERE 1=1
GROUP BY `paths__places`.`tmgeo`
```

# More grouping

```
@places~
[id,name,tmgeo,pleiades~
*tmgeo,pleiades
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~[id,name,tmgeo,pleiades~*tmgeo,pleiades

```SQL
SELECT 
    `paths__places`.`id`, 
    `paths__places`.`name`, 
    `paths__places`.`tmgeo`, 
    `paths__places`.`pleiades` 
FROM 
    `paths__places`  
WHERE 1=1
GROUP BY
    `paths__places`.`tmgeo`,
    `paths__places`.`pleiades`
```


# JOIN

```
@places~
[id,name,tmgeo,pleiades,geodata.geometry~
+geodata||places.id|=|^paths__geodata.id_link||and|geodata.table_link|=|paths__places
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~[id,name,tmgeo,pleiades,geodata.geometry~%2Bgeodata||places.id|=|^paths__geodata.id_link||and|geodata.table_link|=|paths__places

```SQL
SELECT 
    `paths__places`.`id`, 
    `paths__places`.`name`, 
    `paths__places`.`tmgeo`, 
    `paths__places`.`pleiades`, 
    `paths__geodata`.`geometry` 
FROM 
    `paths__places` 
    JOIN 
    `paths__geodata`  ON  `paths__places`.`id` = `paths__geodata`.`id_link`  AND 
                          `paths__geodata`.`table_link` = 'paths__places' 
WHERE 1=1
LIMIT 30 OFFSET 0
```


# Implicit JOIN 1: search in sub-tables

```
@places~
?m_toponyms.toponym|=|Dayr%20Katreh
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~?m_toponyms.toponym|=|Dayr%20Katreh

```SQL
SELECT 
    `paths__places`.* 
FROM 
    `paths__places` 
    JOIN 
    `paths__m_toponyms` ON  `paths__m_toponyms`.`table_link` = 'paths__places' AND 
                            `paths__m_toponyms`.`id_link` = `paths__places`.`id` 
WHERE  
    `paths__m_toponyms`.`toponym` = ?
```
`[ "Dayr Katreh" ]`


# Implicit JOIN 2: search in fields holding FK

```
@places~
?toporeferredto|=|Hamuli/Monastery of the Archangel Michael at Phantoou
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@places~?toporeferredto|=|Hamuli/Monastery%20of%20the%20Archangel%20Michael%20at%20Phantoou

```SQL
SELECT 
    `paths__places`.* 
FROM 
    `paths__places` 
    JOIN
    `paths__places` AS `paths__places5f1c7eafdac10` ON `paths__places`.`toporeferredto` = `paths__places5f1c7eafdac10`.`id` 
WHERE 
    `paths__places5f1c7eafdac10`.`name` = ?",
```
`[ "Hamuli/Monastery of the Archangel Michael at Phantoou" ]`


# Implicit JOIN 3: search in sub-tables, in fields holding FK

```
@works~
?m_wkauthors.author|=|Acacius of Caesarea
```

https://db.bradypus.net/api/v2/paths?pretty=1&verb=search&shortsql=@works~?m_wkauthors.author|=|Acacius%20of%20Caesarea

```SQL
SELECT
    `paths__works`.* 
FROM 
    `paths__works` 
    JOIN 
    `paths__m_wkauthors` ON `paths__m_wkauthors`.`table_link` = 'paths__works' AND
    `paths__m_wkauthors`.`id_link` = `paths__works`.`id` 
    JOIN 
    `paths__authors` AS `paths__authors5f1c7f9497cf2` ON `paths__m_wkauthors`.`author` = `paths__authors5f1c7f9497cf2`.`id` 
WHERE  
    `paths__authors5f1c7f9497cf2`.`name` = ?
```
`[ "Acacius of Caesarea" ]`