# BraDypUS API

BraDypUS has a JSON API that permits users to remotely query the database,
extract data or get information about the database structure.

The API endpoint is available at the `api/` relative URL, eg.:
`http://db.bradypus.net/api/`.

### The API function must be activated in the main app configuration file in order for the API to work. The API should run as a specific user of the database

Foreach API call an **application** and a **reference table**  and a set of **parameters** should be provided in the URL in the form: `http://{base-url}/api/{app-name}/{table-name}?parameters`, eg.: `http://db.bradypus.net/api/ghazni/finds?parameters`

### Available parameters:
- `verb` (GET, string, required): the action the API should run.
At present the following values are available: **read**: will return full structured data for a record,
 and **search**: will return a search result. Each `verb` value requires one or more additional parameters.
- `id` (GET, int, required for `verb` **read**): the database id of the record to be rendered.
- `type` (GET, string, required for `verb` **search**): type of search to perform.
The available types are: **all**, **recent**, **fast**, **id_array**, **encoded**.
- `limit`(GET, int, optional for `type` **recent**, default: 20): number of total most recent records to return
- `string` (GET, string, required for `type` **fast**): string to search in the database
- `records_per_page` (GET, int, optional for `type` **search**, default: 30): maximum number of records per page
- `page` (GET, int, optional for all values of `type`)



## Examples

- Show record with id #1: http://db.bradypus.net/api/ghazni/finds?verb=read&id=1
- Get all records from database:
  - first page (no page parameter): http://db.bradypus.net/api/ghazni/finds?verb=search&type=all
  - first page (with page parameter): http://db.bradypus.net/api/ghazni/finds?verb=search&type=all&page=1
  - third page: http://db.bradypus.net/api/ghazni/finds?verb=search&type=all&page=3
- Get most recently entered records:
  - default number (20) of records: http://db.bradypus.net/api/ghazni/finds?verb=search&type=recent
  - custom number (eg. 30) of records: http://db.bradypus.net/api/ghazni/finds?verb=search&type=recent&limit=30
  - custom number (eg. 30) of records, page 2: http://db.bradypus.net/api/ghazni/finds?verb=search&type=recent&limit=30&page=2
- Search a string in anywhere:
  - Search for word **Figurine**: http://db.bradypus.net/api/ghazni/finds?verb=search&type=fast&string=Figurine
  - Search for word **Figurine** and get the second page: http://db.bradypus.net/api/ghazni/finds?verb=search&type=fast&string=Figurine&page=2
- Get a list of records by listing their ids: http://db.bradypus.net/api/ghazni/finds?verb=search&type=id_array&id[]=1&id[]=2
- Execute a custom defined SQL query on the server (all edit actions will be rejected), eg. get records with inventory number  bigger than 10 > SQL: `` `inv_no` > 10 `` > base64_encode'd: `YGludl9ub2A+MTA=` > Url encoded: `YGludl9ub2A%2BMTA%3D` > URl: http://db.bradypus.net/api/ghazni/finds?verb=search&type=encoded&q_encoded=YGludl9ub2A%2BMTA%3D


## Response JSON data structure

### JSON structure of for `verb` **search**
```javascript
{
    "head": { // head part of the document containig metadata
        "query_arrived": "", // (string) Full SQL text of the built query
        "query_encoded": "", // (string) base64_ecìncoded version of the executed query for further use
        "total_rows": "", // (int) total number of records found
        "page": "", // (int) current page number
        "total_pages": "", // (int) Total number of pages found
        "table": "", // (string) Full form of the queried table
        "stripped_table": "", // (string) Cleaned form (no app name and prefix) of queried table
        "no_records_shown": "", // (int) Number of records shown in the current page
        "query_executed": "", // (string) Full SQL text of the executed query (with pagination limits and sorting)
        "fields": {} // (object) associative object list of fields with field id as key and field label as value
        }
    },
    "records": [ // array of objects records
        {
            "core": { }, // (object) associative object list with fieldname as key and field value as value
            "coreLinks": [], // (array of objects) array with associative object list with coreLinks
            "allPlugins": [], // (array of objects) array with associative object list with plugin data
            "fullFiles": [], // (array of objects) array with associative object list with file data
            "geodata": [], // (array of objects) array with associative object list with geo data
            "userLinks": [] // (array of objects) array with associative object list with user defined links
        },
        {...}
    ]
}
```

### Single record structure
```javascript
{
  "fields": {}, // (object) associative object list of fields with field id as key and field label as value
  "core": { }, // (object) associative object list with fieldname as key and field value as value
  "coreLinks": [], // (array of objects) array with associative object list with coreLinks
  "allPlugins": [], // (array of objects) array with associative object list with plugin data
  "fullFiles": [], // (array of objects) array with associative object list with file data
  "geodata": [], // (array of objects) array with associative object list with geo data
  "userLinks": [] // (array of objects) array with associative object list with user defined links
}
```
