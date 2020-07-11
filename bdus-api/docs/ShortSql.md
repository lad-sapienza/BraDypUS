# ShortSql

ShortSql is an easy to use syntax to compile plain to complez SQL staments that can be safely sent as a query parameter and safely parse and validated on the server.

ShortSql is based on blocks, separated by tildes (~). The order of the blocks is not important. The first character of a block marks the block type.


## Syntax
- `@table:alias`  
    **Required**  
    Alias is not supported yet (04.02.2020)

- `[field:Alias,fieldn:AliasN`  
    **Optional**, Default: `*`. 
    List of fields to fetch, separated by commas. Each field can be followed by an optional alias, separated by a colon (:).

- `+tbname:Alias||onStatement`  
    **Optional**.  
    **Multiple**.  
    Join statement. Each statement if made of two parts separated by a double pipe (`||`). The first part is the table name to be joined optinally followed by an alias (separated by a colon `:`). Alias is not supported yet (04.02.2020).  
    The second part is the On statement encoded as a WHERE statement (see below).

- `?where`  
    **Optional**
    The different parts of the WHERE are separated by double pipes (`||`). The first part is made of three elements (field name, operator, reference value) separated by single pipes (`|`); other parts are made of four elements, having a connector as first element.  
    - Field names may be provided as `field`, `field:alias`, `table.field` or `table.field:alias`
    - If value starts with a caret (`^`), the value will not binded nor escaped by quotes as string: it is assumed to be a table field name.

- `>field:order`  
    **Optional**.  
    **Multiple**.  
    `ASC` || `DESC` (case insensitive)
    Sorting statement, colon separated. 
    - The first element is the field name. 
    - The second element is the sorting direction and is optional, having as a default: `ASC`. `ASC` or DESC (case insensitive)

- `-tot:offset`  
    **Optional**
    Limit statement, colon separated.  
    - First element is the total numer of rows to fetch. Must be a numeral
    - Second element, optional, is the offset
 - `*field1,fieldn`  
    **Optional**.  
    Group statement. Comma separated list of fields to use for grouping.  
    Each field shoul be a valid field and may be provided as `table.field` or `field`

## Examples

### Simple example

The string:

```
@places
```

is parsed as 

```SQL
SELECT * FROM `paths__places`  WHERE 1
```

### Complex example
The string:
```
@places~[id,name:Nome,tmgeo,geodata.geometry~+geodata||places.id|=|^paths__geodata.id_link||and|geodata.table_link|=|paths__places~?name|like|%Magna%||and|name|like|H%~>paths__places.id:desc~*id,name
```
is parsed as:
```SQL
SELECT 
    `paths__places`.`id`, `paths__places`.`name` AS Nome, 
    `paths__places`.`tmgeo`, 
    `paths__geodata`.`geometry` 
FROM `paths__places` 
    JOIN `paths__geodata` ON  
        id_link = `paths__geodata`.`id_link`
        and 
        `paths__geodata`.`table_link` = 'paths__places' 
WHERE  
    `paths__places`.`name` like ? 
    and 
    `paths__places`.`name` like ?  
GROUP BY `paths__places`.`id`, `paths__places`.`name`  
ORDER BY `paths__places`.`id`  DESC
```
with values:
```js
[ '%Magna%', 'H%']
```

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
