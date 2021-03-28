```json
"metadata": {
    "app": (app name),
    "tb_id": (table name),
    "rec_id": (int),
    "tb_stripped":,
    "tb_label":
},
"core": {
    "id": {
        "name": (field id),
        "label": (field label),
        "val": (value),
        "val_label": (if available — id_from_table fields — value label),
        "_val": (if set, the new value to be written in the database)
    },
   "other_fld_name": { ... },
},

"plugins": {
    "plugin_1_full_name": {
        "metadata": {
            "tb_id": (referenced plugin table full name),
            "tb_stripped": (referenced plugin table name without prefix),
            "tb_label": (referenced plugin table label),
            "tot": (total number of items found)
        },
        "data": [
            "id_row": {
                "id": {
                    "name": (field id),
                    "label": (field label),
                    "val": (value),
                    "val_label": (if available — id_from_table fields — value label),
                    "_val": (if set, the new value to be written in the database),
                    "_delete": (if true, the record is market to be deleted)
                },
                "other_fld_name": { ... },
            },
            "id_row": {...}
        ]
    },
    "plugin_n__full_name": { ... }
},

"links": {
    "referenced_table_1_full_name": {
        "tb_id": (referenced table full name),
        "tb_stripped": (referenced table name without prefix),
        "tb_label": (referenced table label),
        "tot": (total number of links found),
        "where": (SQL where statement to fetch records)
    },
    "referenced_table_n_full_name": { ... }
},

"backlinks": {
    "referenced_table_1_full_name": {
        "tb_id": (referenced table full name),
        "tb_stripped": (referenced table name without prefix),
        "tb_label": (referenced table Label),
        "tot": (total number of links found),
        "data": [
            {
                "id": (int),
                "label": (string)
            },
            { ... }
    },
    "referenced_table_1_full_name": {}
},

"manualLinks": {
    "link_id" => {
        "key": (int),
        "tb_id": (referenced table full name),
        "tb_stripped": (referenced table name without prefix),
        "tb_label": (referenced table Label),
        "ref_id": (int),
        "ref_label": (string|int),
        "sort": (int),
        "_sort": (int),
        "_tb_id": (referenced table full name),
        "_ref_id": (int),
        "_delete": (boolean)

    },
    { ... }
},

"files": [
    {
        "id": (int),
        "creator": (int),
        "ext": (string),
        "keywords": (string),
        "description": (string),
        "printable": (boolean),
        "filename": (string)
    },
    { ... }
],

"geodata": {
    "geodata_id" => {
        "id": (int),
        "geometry": (string, wkt),
        "geojson": (string, geojson),
        "_geometry": (string, wkt),
    },
    { ... }
},

"rs": [
    {
        "id": "1",
        "first": (int),
        "second": (int),
        "relation": (int)
    },
    { ... }
]



```