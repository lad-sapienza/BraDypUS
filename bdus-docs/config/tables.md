---
title: File di elenco e configurazione delle tabelle
---


- Posizione: `projecs/test/cfg/tables.json`
- Descrizione: Questo file JSON contiene la lista completa di tutte le tabelle dell'applicazione, 
con informazioni dettagliate sulla loro descrizone rete di collegamenti
- Contenuto

```json
{
    "tables": [
        {
            "name": "test__siti",
            "label": "Siti",
            "order": "nome",
            "id_field": "nome",
            "preview": [
                "nome",
                "tipologia",
                "cronologia"
            ],
            "plugin": [
                "test__m_biblio"
            ]
        },
        {
            "name": "test__us",
            "label": "Unità stratigrafiche",
            "order": "nome",
            "id_field": "nome",
            "preview": [
                "nome",
                "tipo"
            ],
            "plugin": [
                "test__m_campioni"
            ]
        },
        {
            "name": "test__bibliografia",
            "label": "Bibliografia",
            "order": "breve",
            "id_field": "breve",
            "preview": [
                "breve",
                "autori",
                "anno"
            ]
        },
        {
            "name": "test__files",
            "label": "Allegati",
            "order": "id",
            "id_field": "id",
            "preview": [
                "id",
                "filename",
                "ext",
                "description"
            ]
        },
        {
            "name": "test__m_biblio",
            "label": "Bibliografia",
            "is_plugin": "1"
        },
        {
            "name": "test__m_campioni",
            "label": "Campioni",
            "is_plugin": "1"
        }
      ]
}
```

Come è chiaro dall'esempio sopra il file `tables.json` contiene un oggetto con chiave `tables` e come valore 
una lista (array) di oggetti, ciscuno dei quali descrive una delle tabelle dell'applicazione.

### Impostazioni di base
Per ogni tabella, vanno specificate le seguenti chiavi:
- `name` è il nome identificativo completo di prefisso della tabella, es: **test__siti**.
- `label` è l'etichetta della tabella che verra mostrato agli utenti, i quali non vendranno mail il `name`, es. **Siti**.

---

- `is_plugin` se presente (valorizzato `1`, `"1"` o `true`) allora la tabella è considerata come plugin;
Se questa chiave è fornita, le **chiavi successive non devono essere compilate**.

---

- `order` è il nome di un campo della tabella che verrà usato come opzione di default per l'ordinamento 
dei risultati di una ricerca; questa opzione può essere personalizzata dai singoli utenti.
- `id_field` è il nome di un campo della tabella che verrà usato come valore identificativo. 
Il database usa sempre il campo `id` come chiave primaria, ma è spesso utile per gli utenti avere un 
campo identificativo che viene gestito manualmente. Si può cnsiderare come una "finta" chiave primaria.
Nel caso dei siti può essere il nome del sito e nel caso delle us il numero di us. Ovviamente può anche
essere il campo `id`.
- `preview` va valorizzato con una lista (array) di nomi di campi che di default verranno visualizzati
nei risultati di cisacuna ricerca; questa opzione può essere personalizzata dai singoli utenti.
- `plugin` si tratta di una lista (array) di nomi di [tabelle plugin](/voc#plugin) riferite alla presente.

---

### Impostazioni più avanzate
Si consiglia di non compilare manualmente le impostazioni avanzate, ma di
usare la funziona interna alla bancha dati, che permette una loro compilazione
facilitata e attraverso interfacce grafiche che consetono una migliore gestione 
della sintassi.

- `tmpl_read`: il nome di un [template](/voc#template) specifico da usare per la visualizzazione in lettura dei dati della tabella
- `tmpl_edit`: il nome di un [template](/voc#template) specifico da usare per la visualizzazione in scrittura/inserimento dei dati della tabella
- `rs`: il nome di un campo della tabella che verrà usato per il funzionamento del plugin speciale dedicato alle relazioni stratigrafiche [**TODO**: fare la descrizione di questo plugin]
- `backlinks`: una lista... [**TODO**: completare]
- `links`: una lista di configurazioni di collegamenti tra tabelle [**TODO**: completare]
- `geoface`: configurazioni avanzate per la gestione dell'interfaccia geografica [**TODO**: completare]