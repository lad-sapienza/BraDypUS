# Final appearance of configuration files

If you are not interested on going through the looking glass skip this part.

Follows the full content of the final configuration files, that  can be founf in `cfg` folder

- [app_data.json](#app_datajson)
- [bibliography.json](#bibliographyjson)
- [files.json](#filesjson)
- [geodata.json](#geodatajson)
- [m_citations.json](#m_citationsjson)
- [m_samples.json](#m_samplesjson)
- [sites.json](#sitesjson)
- [su.json](#sujson)
- [tables.json](#tablesjson)

## app_data.json

```json
{
    "lang": "en",
    "name": "test",
    "definition": "This is a test BraDypUS database",
    "status": "on",
    "db_engine": "sqlite",
    "db_host": "",
    "db_port": "",
    "db_name": "",
    "db_username": "",
    "db_password": "",
    "maxImageSize": "1500"
}
```

## bibliography.json
```json
[
    {
        "name": "id",
        "label": "Id",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "creator",
        "label": "Creator",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "short",
        "label": "Short form",
        "type": "text",
        "db_type": "TEXT",
        "check": [
            "no_dupl"
        ]
    },
    {
        "name": "author",
        "label": "Author(s)",
        "type": "text",
        "db_type": "TEXT"
    },
    {
        "name": "title",
        "label": "Title",
        "type": "text",
        "db_type": "TEXT"
    },
    {
        "name": "year",
        "label": "Year of publication",
        "type": "text",
        "db_type": "TEXT"
    },
    {
        "name": "publishedin",
        "label": "Published in",
        "type": "text",
        "db_type": "TEXT"
    }
]
```

## files.json
```json
[
    {
        "name": "id",
        "label": "ID",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "creator",
        "label": "Creator",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "filename",
        "label": "Filename",
        "type": "text",
        "check": [
            "not_empty"
        ],
        "readonly": true
    },
    {
        "name": "ext",
        "label": "Extension",
        "type": "text",
        "check": [
            "not_empty"
        ],
        "readonly": true
    },
    {
        "name": "keywords",
        "label": "Keywords",
        "type": "text"
    },
    {
        "name": "description",
        "label": "Description",
        "type": "long_text"
    },
    {
        "name": "printable",
        "label": "Printable",
        "type": "boolean"
    }
]
```

## geodata.json
```json
[
    {
        "name": "id",
        "label": "ID",
        "type": "text",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "table_link",
        "label": "Linked table",
        "type": "text",
        "db_type": "TEXT",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "id_link",
        "label": "Linked id",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "geometry",
        "label": "Coordinates (WKT format)",
        "type": "text"
    }
]
```

## m_citations.json
```json
[
    {
        "name": "id",
        "label": "Id",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "disabled": "1"
    },
    {
        "name": "creator",
        "label": "Creator",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "short",
        "label": "Short form",
        "type": "select",
        "db_type": "TEXT",
        "id_from_tb": "test__bibliography"
    },
    {
        "name": "pages",
        "label": "pages",
        "type": "text",
        "db_type": "TEXT"
    }
]
```

## m_samples.json
```json
[
    {
        "name": "id",
        "label": "Id",
        "type": "text",
        "db_type": "INTEGER"
    },
    {
        "name": "table_link",
        "label": "Linked table",
        "type": "text"
    },
    {
        "name": "id_link",
        "label": "Linked id",
        "type": "int"
    },
    {
        "name": "datetaken",
        "label": "Date taken",
        "type": "date",
        "db_type": "TEXT"
    },
    {
        "name": "analysis",
        "label": "Analysis",
        "type": "text",
        "db_type": "TEXT"
    },
    {
        "name": "notes",
        "label": "Notes",
        "type": "long_text",
        "db_type": "TEXT"
    }
]
```

## sites.json
```json
[
    {
        "name": "id",
        "label": "Id",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "creator",
        "label": "Creator",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "name",
        "label": "Site name",
        "type": "text",
        "db_type": "TEXT",
        "check": [
            "no_dupl"
        ]
    },
    {
        "name": "typology",
        "label": "Typology",
        "type": "select",
        "db_type": "TEXT",
        "vocabulary_set": "site_typology"
    },
    {
        "name": "chronology",
        "label": "Chronology",
        "type": "multi_select",
        "db_type": "TEXT",
        "vocabulary_set": "site_chronology"
    },
    {
        "name": "description",
        "label": "Description",
        "type": "long_text",
        "db_type": "TEXT"
    }
]
```

## su.json
```json
[
    {
        "name": "id",
        "label": "Id",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "creator",
        "label": "Creator",
        "type": "text",
        "db_type": "INTEGER",
        "readonly": "1",
        "hide": "1"
    },
    {
        "name": "site",
        "label": "Site",
        "type": "select",
        "db_type": "INTEGER",
        "id_from_tb": "test__sites",
        "check": [
            "not_empty"
        ]
    },
    {
        "name": "name",
        "label": "Name",
        "type": "text",
        "db_type": "TEXT",
        "check": [
            "no_dupl"
        ]
    },
    {
        "name": "type",
        "label": "Type",
        "type": "text",
        "db_type": "TEXT",
        "vocabulary_set": "su_type"
    },
    {
        "name": "description",
        "label": "Description",
        "type": "long_text",
        "db_type": "TEXT"
    }
]
```

## tables.json
```json
{
    "tables": [
        {
            "name": "test__sites",
            "label": "Sites",
            "order": "name",
            "id_field": "name",
            "preview": [
                "name",
                "typology",
                "chronology"
            ],
            "plugin": [
                "test__m_citations",
                "test__geodata"
            ],
            "link": [
                {
                    "other_tb": "test__su",
                    "fld": [
                        {
                            "my": "id",
                            "other": "site"
                        }
                    ]
                }
            ]
        },
        {
            "name": "test__su",
            "label": "Stratigraphic units",
            "order": "name",
            "id_field": "name",
            "preview": [
                "site",
                "name",
                "type"
            ],
            "rs": "name",
            "plugin": [
                "test__m_citations",
                "test__m_samples"
            ],
            "link": [
                {
                    "other_tb": "test__sites",
                    "fld": [
                        {
                            "my": "site",
                            "other": "id"
                        }
                    ]
                }
            ]
        },
        {
            "name": "test__bibliography",
            "label": "Bibliographic database",
            "order": "short",
            "id_field": "short",
            "preview": [
                "short",
                "author",
                "year",
                "title"
            ]
        },
        {
            "name": "test__files",
            "label": "Files",
            "order": "id",
            "preview": [
                "id",
                "filename",
                "ext",
                "keywords"
            ],
            "id_field": "id"
        },
        {
            "name": "test__geodata",
            "label": "Geografical coordinates",
            "is_plugin": "1"
        },
        {
            "name": "test__m_samples",
            "label": "Samples",
            "is_plugin": "1"
        },
        {
            "name": "test__m_citations",
            "label": "Bibliographic citations",
            "is_plugin": "1"
        }
    ]
}
```

