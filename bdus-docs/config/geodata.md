---
title: File di configurazione della tabella files
---


- Posizione: `projecs/test/cfg/geodata.json`
- Descrizione: Configurazione **costante** della tabella di sistema `geodata`
che registra le informaioni sui dati geografici da connettere ai record.
Questa configurazione segue la [sintassi della descrizione delle singole tabelle di dati](/config/data-tables-syntax).
- Contenuto

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
        "name": "geometry",
        "label": "Geometria WKT",
        "type": "text",
        "help": "https://it.wikipedia.org/wiki/Well-Known_Text"
    }
]
```