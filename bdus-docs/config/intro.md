# Configurazione generale del sistema Bradyus

Il sistema Bradypus è gestito da un insieme di file di configurazione in formato 
[JSON](https://www.json.org/) un formato molto semplice di registrazione di dati,
facile da leggere e da scrivere sia da umani che da macchine.

Il sistema Bradypus prevede la creazione di un quattro file di configuazione fissi
e di un file di configurazione per ogni tabella di dati, sia che si tratti 
di un tabella principale che una tabella `plugin`.

I file  di configurazioni di sistema sono:
- `app_data.json`: continete le informazioni gerenali del sistema
- `tables.json`: contiene l'elenco delle tabelle del sistema, alcune configurazioni generali 
per ogni tabella e soprattutto la rete delle relazioni che collega insieme le varie tabelle

**Il contenuto di questi file può essere cambiato anche da interfaccia grafica,
all'interno del programma, una volta che l'installazione è stata completata.**