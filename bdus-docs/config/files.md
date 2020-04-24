---
title: File di configurazione della tabella files
---


- Posizione: `projecs/test/cfg/files.json`
- Descrizione: Configurazione **costante** della tabella di sistema `files`
che registra le informaioni sui file caricati, immagini o altri tipi di documenti.
Questa configurazione segue la [sintassi della descrizione delle singole tabelle di dati](/config/data-tables-syntax).
- Contenuto

```json
[
    {
        "name":"id",
        "label":"ID",
        "type":"text",
        "readonly":true
    },
    {
        "name":"ext",
        "label":"Extension",
        "type":"text",
        "check":[
            "not_empty"
        ],
        "readonly":true
    },
    {
        "name":"filename",
        "label":"Filename",
        "type":"text",
        "check":[
            "not_empty"
        ],
        "readonly":true
    },
    {
        "name":"keywords",
        "label":"Keywords",
        "type":"text"
    },
    {
        "name":"description",
        "label":"Description",
        "type":"long_text"
    },
    {
        "name":"printable",
        "label":"Printable",
        "type":"boolean"
    }
]
```