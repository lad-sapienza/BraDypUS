# BraDypUS API v2

BraDypUS has a JSON API that permits users to remotely query the database,
extract data or get information about the database structure.

The API endpoint is available at the `api/` relative URL, eg.:
`https://db.bradypus.net/api/v2/`.

**The API is by default disabled and must be activated in the main app configuration file. The API should run as a specific user of the database**

Foreach API call an **application** and a set of **parameters** should be provided in the URL in the form: `https://{base-url}/api/{app-name}?parameters`, eg.: `https://db.bradypus.net/api/paths/places?param1=val1&param2=val2`

### General parameters:
- `app` (GET, string, **required**): a valid app
- `verb` (GET, string, **required**): The action that the API should run. The list of the available verbs will be extended each time a new feature is added. The following verbs are available and each verb has its own optional and required parameters (see below for a full description):
  - getChart
  - getUniqueVal
  - getVocabulary
  - inspect
  - read
  - search
- `pretty` (GET, boolean, optional, default: false): if true the output JSON will be indented

### Verb details

#### getChart

Returns single chart data or data for all available charts.
For each chart the name, the id and tha data will be returned.
- `id` (GET, int|string, required): Numeric id of the chart to retrive.
If **all** is provided as id value, the list of the available charts will 
be returned.

Eg.
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=getChart&id=all
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=getChart&id=1

---

#### getUniqueVal

Returns list of unique values used in a specific field of a specific table

- `tb` (GET, string, **required**): Reference table name (not label)
- `fld` (GET, string, **required**): Field name (not label) to search in
- `s` (GET, string, optional, default: false): If provided, will be used as filter to return only a subset of the available values
- `w` (GET, string, optional, default: false): ShortSql where text to be used as filter to return only a subset of the available values


eg.
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=getUniqueVal&tb=places&fld=nomos
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=getUniqueVal&tb=places&fld=nomos&s=polit
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=getUniqueVal&tb=places&fld=nomos&w=id|>|100

Please note that the following two examples are equivalent, the first being a shorthand of the second
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=getUniqueVal&tb=places&fld=nomos&s=polit
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=getUniqueVal&tb=places&fld=nomos&w=nomos|like|%polit%

---

#### getVocabulary

Returns list (first 500 records) of items of a vocabulary

- `voc` (GET, string, **required**): name of the vocabulary to retrieve


Eg.
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=getVocabulary&voc=place_types

---

#### inspect

Returns full configuration options for a specific table or for all database

- `tb` (GET, string, optional, default: false): name of table to inspect


Eg.
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=inspect
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=inspect&tb=places

---

#### read

Returns full information about the record

- `tb` (GET, string, **required**): name of referenced table
- `id` (GET, integer, **required**): Id (PK) of the record to read

Eg.
- https://db.bradypus.net/api/v2/paths?pretty=1&verb=read&tb=places&id=1

The response structure is similar to the following simplified example:

```json
{
    "metadata": {
        "tb_id": "paths__places",
        "tb_stripped": "places",
        "tb_label": "Places"
    },
    "core": {
        "id": {
            "name": "id",
            "label": "PAThs ID",
            "val": "1"
        },
        "creator": {
            "name": "creator",
            "label": "Creator",
            "val": null
        },
        "name": {
            "name": "name",
            "label": "Site name",
            "val": "Memphis"
        },
        ...
    },
    "plugins": {
        "paths__m_biblio": {
            "metadata": {
                "tb_id": "paths__m_biblio",
                "tb_stripped": "m_biblio",
                "tb_label": "Bibliography",
                "tot": 1
            },
            "data": [
                {
                    "id": {
                        "name": "id",
                        "label": "ID",
                        "val": "130"
                    },
                    "table_link": {
                        "name": "table_link",
                        "label": null,
                        "val": "paths__places"
                    },
                    "id_link": {
                        "name": "id_link",
                        "label": null,
                        "val": "1"
                    },
                    "short": {
                        "name": "short",
                        "label": "Short reference",
                        "val": "Timm 1984-1992",
                        "val_label": "Timm 1984-1992"
                    },
                    ...
                }
            ]
        },
        "paths__m_toponyms": {
            
            ...
            
        }
    },
    "links": {
        "paths__authors": {
            "tb_id": "paths__authors",
            "tb_stripped": "authors",
            "tb_label": "Authors",
            "tot": "0",
            "where": " `episcopalsee` = '1'"
        },
        "paths__colophons": {
            "tb_id": "paths__colophons",
            "tb_stripped": "colophons",
            "tb_label": "Colophons",
            "tot": "0",
            "where": " `istitutionplace` = '1'"
        }
    },
    "backlinks": [],
    "manualLinks": [],
    "files": [],
    "geodata": [
        {
            "id": "1",
            "geometry": "POINT (31.255061 29.849491)",
            "geo_el_elips": null,
            "geo_el_asl": null,
            "geojson": "{\"type\":\"Point\",\"coordinates\":[31.255061,29.849491]}"
        }
    ],
    "rs": false
}
```

---

#### search

Performs a searche in thedatabase using ShortSql and return results

- `shortsql` (GET, string, **required**): ShortSql text of the query to perform. For more information, see [the ShortSql guide](ShortSql.md) and [some working examples](ShortSQL-Examples.md).
- `total_rows` (GET, int, optional, default: false): Used in navigation, if not provided will be calculated
- `page` (GET, int, optional, default: false): Current page, for paginated results
- `records_per_page`  (GET, int, optional, default: false): Number of records per page, for paginated results
- `geojson` (GET, boolean, optional, default: false): If true and if records contain coordnates (use of GeoFace) the output will be valid GeoJSON
- `full_records`  (GET, int, optional, default: false): If true for each record the full informaton will be returned.