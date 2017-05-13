# BraDypUS API

BraDypUS has a JSON API that permits users to remotely query the database, extract data or get information about the database structure.

The API endpoint is available at the `api/` relative URL, eg.:
`http://db.bradypus.net/api/`.

### The API function must be activated in the main app configuration file in order for the API to work. The API should run as a specific user of the database

Foreach API call an **application** and a **reference table**  and a set of **parameters** should be provided in the URL in the form: `http://{base-url}/api/{app-name}/{table-name}/parameters`, eg.: `http://db.bradypus.net/api/ghazni/finds/test`

### Available parameters:
- id

- records_per_page (default: 30)
- total_rows
- page (default: 1)

- type (all, recent, fast, sqlExpert, id_array)
- limit
- string
- querytext
- id
  - encoded
    - q_encoded
  - advanced
    - adv


## Search
Several types of search are available, all controlled by `type` parameter

### Get all records
To retrieve all records `all` value should be used for `type` parameter, eg.:
`http://db.bradypus.net/api/ghazni/finds/type=all`

### Get recently added records
To retrieve recently added (not edited) records `recent` value should be used for `type` parameter. Also the `limit` parameter, holding the number of records to retrieve, is required.

`http://db.bradypus.net/api/ghazni/finds/type=recent&limit=10`

### Get records by string
To filter records by a string (search a string in all fields using SQL `LIKE` operator) `fast` value should be used for `type` parameter. Also the `string` parameter, holding the string to search, is required.

`http://db.bradypus.net/api/ghazni/finds/type=fast&string=panel`

### Get records by executing a SQL query
To run a SQL query on the database the `sqlExpert` value should be used for `type` parameter. Also the `querytext` parameter, holding the SQL string to execute, is required.

**Warning: string containing on of the following statements will be rejected: update, delete, truncate, ;, insert, insert, update, create, drop, file, index, alter, alter routine, create routine, execute!**

**Only the value following the `WHERE` part of the query should be provided**

`http://db.bradypus.net/api/ghazni/finds/type=sqlExpert&querytext=1`

### Get records by a list of ids
To get a list of record from a list of ids `id_array` value should be used for `type` parameter. Also the `id` parameter as an arra, is required.

`http://db.bradypus.net/api/ghazni/finds/type=id_array&id[]=1&id[]=2`

## Get full record by id
To get full information for a record the **id** parameter should be used, eg:
`http://db.bradypus.net/api/ghazni/finds/id=1` will return a JSON object containing all information about record `#1` in table `finds` of app `ghazni`.


## Response JSON data structure

### List of results structure

### Single record structure
    {
      "fields": {
        // a json object with full list of fields: field name will be used as key and field label as value

        "field_id": "field_label",
        ...
      },
      {
        "core": {
          // a json object with full list/values of core table: field names will be used as key and cell values as value

          "field_id": "field_value"
        },

        "coreLinks": [
          // an array of JSON objects with automatic (system) links to other tables


        ],

        "fullFiles":[
          // array with JSON object for each linked file
          {
            "id": "file_record_id",
            "creator": "file_record_creator_id",
            "ext": "file_extension",
            "keywords": "file_keywords",
            "description": "file_description",
            "printable": "boolean: is file printable?",
            "filename": "file_name",
            "linkid": "id_of_link"
          },
          {
            ...
          }
        ],
        "geodata": [
          // Array of geodata
        ],
        "userLinks": [
          {
            // Array of links to other tables manually added by users

            "id": "link_record_id",
            "tb": "linked_table_name",
            "ref_id": "linked_record_id"
          },
          {
            ...
          },
        ]
      }
    }
