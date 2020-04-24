---
title: File generale di configurazione dell'applicazione
---


- Posizione: `projecs/test/cfg/app_data.json`
- Descrizione: Questo file JSON contiene poche, semplici ed essezioni informazioni dull'applicazione / progetto che sta sta definendo.
- Contenuto
```json
{
    "lang": "it",
    "name": "test",
    "definition": "A BraDypUS database",
    "status": "on"
}
```

La chiave `lang` contiene in una stringa di due lettere la lingua di default dell'applicazione. Bradypus è disponibile 
in italiano e in inglese, ma è semplice aggiungere altre traduzioni (**TODO**: come tradurre bradypus).
Questa chiave può dunque contenere il valore "it" o "en". Ciascun utente potrà comunque  personalizzare la propria
esperienza selezionando una propria lingua di utilizzo, diversa da questa di default.

La chiave `name` è sicuramete la più importante e contiene il nome / idetificativo dell'applicazione. 
La stringa deve contenere caratteri minuscoli, solo lettere, niente numeri o caratteri di interpunzione, spazi, 
caratteri accentati e altri diacritici. Questa stringa sarà anche usato come prefisso per il nome di tutte le tabella.


La chiave `definition` è una descrizione testuale della banca dati che si sta creando. Può servire a dare agli utenti
qualche informazione in più o a elencare gli enti coinvolti.

La chiave `status` definisce lo stato dell'applicazione:
- `on` significa che l'applicazione è attiva, funzionante e disponibile agli utenti
- `off` significa che l'applicazione, pur essendo disponibile sul server, è spenta e 
non raggiungibile dagli utenti. Ogni tentativo di login verrà rifiutato
- `frozen` è uno stato di congelamento: l'applicazione è disponibile a tutti gli utenti ma in
modalità di sola lettura. Non sarà possibile mofificare, cancellare o inserire record. Di norma
un'applicazione può essere congelata quando sono in corso lavori di manutenzione e aggiornamento
oppure quando una copia dell'applicazione è attiva offline [**TODO**: possbilità di lavorare temporaneamente offline]