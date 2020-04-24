# Sintassi dei file di configurazione della tabella


- Posizione: `projecs/test/cfg/*.json`
- Descrizione: Configurazione delle singole tabelle di dati, comprese le tabelle [plugin](/voc#plugin).
Per ogni tabella elencata in `tables.json` deve essere presente un file di configurazione che  
segue la [sintassi della descrizione delle singole tabelle di dati](/config/data-tables-syntax).

Per convenzione è scluso dal nome del file il prefisso dell'applicazione, nel caso corrente `test__`.

Di seguito vengono riportati i contenuti delle tabelle di dati dell'applicazione di test,
in ordine alfabetico.

`projects/test/cfg/bibliografia.json`
```json
[
    {
        "name": "id",
        "label": "ID",
        "type": "text",
        "readonly": true,
        "disabled": true
    },
    {
        "name": "breve",
        "label": "Breve",
        "type": "text"
    },
    {
        "name": "autori",
        "label": "Autori",
        "type": "text"
    },
    {
        "name": "anno",
        "label": "Anno",
        "type": "text"
    },
    {
        "name": "descrizione",
        "label": "Descrizione",
        "type": "long_text"
    }
]
```

---

`projects/test/cfg/m_biblio.json`
```json
[
    {
        "name": "id",
        "label": "ID",
        "type": "text",
        "readonly": true,
        "disabled": true
    },
    {
        "name": "breve",
        "label": "Breve",
        "type": "select",
        "id_from_tb": "test__bibliografia"
    },
    {
        "name": "pp",
        "label": "pp",
        "type": "text"
    }
]
``` 

---

`projects/test/cfg/m_campioni.json`
```json
[
    {
        "name": "id",
        "label": "ID",
        "type": "text",
        "readonly": true,
        "disabled": true
    },
    {
        "name": "dataprelievo",
        "label": "Data di prelievo",
        "type": "text"
    },
    {
        "name": "tipoanalisi",
        "label": "Tipo di analisi",
        "type": "text"
    },
    {
        "name": "note",
        "label": "Note",
        "type": "long_text"
    }
]
``` 

---

`projects/test/cfg/siti.json`
```json
[
    {
        "name": "id",
        "label": "ID",
        "type": "text",
        "readonly": true,
        "disabled": true
    },
    {
        "name": "nome",
        "label": "Nome sito",
        "type": "text",
        "check": [
          "no_dupl"
        ]
    },
    {
        "name": "tipologia",
        "label": "Tipologia",
        "type": "select",
        "vocabulary_set": "tipo_siti"
    },
    {
        "name": "cronologia",
        "label": "Cronologia",
        "type": "multi_select",
        "vocabulary_set": "crono_siti"
    },
    {
        "name": "descrizione",
        "label": "Descrizione",
        "type": "long_text"
    }
]
``` 

---

`projects/test/cfg/us.json`
```json
[
    {
        "name": "id",
        "label": "ID",
        "type": "text",
        "readonly": true,
        "disabled": true
    },
    {
        "name": "sito",
        "label": "Sito",
        "type": "select",
        "get_values_from_table": "test__siti:nome"
    },
    {
        "name": "nome",
        "label": "Nome US",
        "type": "text",
        "check": [
          "no_dupl"
        ]
    },
    {
        "name": "tipo",
        "label": "Tipologia",
        "type": "select",
        "vocabulary_set": "tipo_us"
    },
    {
        "name": "descrizione",
        "label": "Descrizione",
        "type": "long_text"
    }
]
``` 
