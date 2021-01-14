---
title: ShortSql
---

ShortSql is a conventional encoding system that compiles to plain to plain SQL 
and has been conceived to be used aa a very elastic querying system, with security in mind. 

The parsing library implements a detialed validation of the 
input data, which grants to the developer a detailed control over the request.

ShortSql is based on indipendent blocks, separated by tildes (~). 
Each block is identified by the first character it uses.  
The order of the blocks is not important, and except for the table block, they are all optional.


## Syntax
- **@**`table:alias`  
    **Required**  
    Alias is not supported yet (04.02.2020)

- **[**`field:Alias,fieldn:AliasN`  
    **Optional**, Default: `*`.  
    List of fields to fetch, separated by commas. Each field can be followed by an optional alias, separated by a colon (:).

- **+**`tbname:Alias||onStatement`  
    **Optional**.  
    **Multiple**.  
    Join statement. Each statement if made of two parts separated by a double pipe (`||`). The first part is the table name to be joined optinally followed by an alias (separated by a colon `:`). Alias is not supported yet (04.02.2020).  
    The second part is the On statement encoded as a WHERE statement (see below).

- **?**`where`  
    **Optional**
    The different parts of the WHERE are separated by double pipes (`||`). The first part is made of three elements (field name, operator, reference value) separated by single pipes (`|`); other parts are made of four elements, having a connector as first element.  
    Brackets might be used to logically group statement parts. In that case opening bracket must placed before the field name and separad by the usual pipe (`|`). The closing bracket must be placed after the value, pipe-separated.  
    - Field names may be provided as `field`, `field:alias`, `table.field` or `table.field:alias`
    - If value starts with a caret (`^`), the value will not binded nor escaped by quotes as string: it is assumed to be a table field name.

- **>**`field:order`  
    **Optional**.  
    **Multiple**.  
    `ASC` || `DESC` (case insensitive)
    Sorting statement, colon separated. 
    - The first element is the field name. 
    - The second element is the sorting direction and is optional, having as a default: `ASC`. `ASC` or DESC (case insensitive)

- **-**`tot:offset`  
    **Optional**
    Limit statement, colon separated.  
    - First element is the total numer of rows to fetch. Must be a numeral
    - Second element, optional, is the offset
 - **\***`field1,fieldn`  
    **Optional**.  
    Group statement. Comma separated list of fields to use for grouping.  
    Each field shoul be a valid field and may be provided as `table.field` or `field`