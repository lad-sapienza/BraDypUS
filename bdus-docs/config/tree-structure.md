---
title: Struttura (albero) di file e cartelle
---

A questo punto è oppurtuno offrire una panoramica generale della struttura di file e cartelle
di una installazione Bradypus completa, in modo da avere una chiara panoramica di quello
che passo-passo si andrà a creare.

Chiamaremo la cartella iniziale che tutto contiene (la cartella radice, ovvero `root`) `bdus`
e diamo per scontato che si trovi in una posizione del filesystem accessibile al server web.

```plaintext
.
├── LICENSE                 : file di sistema
├── README.md               : file di sistema
├── cache/                  : cartella di sistema
├── controller.php          : file di sistema
├── css/                    : cartella di sistema
├── css-less/               : cartella di sistema
├── docs/                   : cartella di sistema
├── fonts/                  : cartella di sistema
├── img/                    : cartella di sistema
├── index.php               : file di sistema
├── js/                     : cartella di sistema
├── js-sources/             : cartella di sistema
├── lib/                    : cartella di sistema
├── locale/                 : cartella di sistema
├── logs/                   : cartella di sistema. Contiene i log generali degli utenti e degli errori
├── modules/                : cartella di sistema
├── node_modules/           : cartella di sistema
├── package.json            : file di sistema
├── projects                : questa cartella contiene i singoli progetti ed è l'unica cartella che possiamo/dobbiamo modificare
│   ├── test                : questa cartella contiene tutto il nostro progetto di test
│   │   ├── backups         : in questa cartella verranno salvati eventuali backup che faremo attraverso Bradypus
│   │   ├── cfg             : questa cartella contiene tutti i file di configurazione del progetto test
│   │   │   ├── app_data.json
│   │   │   ├── bibliografia.json
│   │   │   ├── files.json
│   │   │   ├── geodata.json
│   │   │   ├── m_biblio.json
│   │   │   ├── m_campioni.json
│   │   │   ├── siti.json
│   │   │   ├── tables.json
│   │   │   ├── us.json
│   │   ├── db              : questa cartella contiene il database, in caso si sia optato per usare sqlite e anche il db di metadati
│   │   │   ├── bdus.sqlite : in caso si sia optato per Sqlite come motore database questo è il file che conterrà tutti i dati
│   │   │   └── meta.sqlite : questo file contiene la storia di tutte le modifiche, i log di errore dell'applicazione e i record versionati
│   │   ├── error.log       : vecchio file di log degli errori. Nelle nuove versioni non viene più creato e gli errori vengono salvati in db/meta.sqlite
│   │   ├── export          : in questa cartella verranno salvati eventuali esportazioni in vari formati che faremo attraverso Bradypus
│   │   ├── files           : in questa cartella verranno salvati tutti i file (immagini e documenti) che vengono caricati e collegati ai record
│   │   ├── geodata         : in questa cartella possono essere salvati dati geografici da usare con l'interfaccia geografica integrata (geoface)
│   │   ├── history.log     : vecchio file di log della stria delle modifiche. Nelle nuove versioni non viene più creata e la storia delle modifiche viene in db/meta.sqlite
│   │   ├── templates       : per ogni tabella di sistema è possibile definire delle maschere personalizateper l'inserimenti dei dati. Queste machere, chiamate template, vengono salvate in questa cartella
│   │   ├── tmp             : cartella che contiene file temporanei e che può essere svuotata senza problemi
│   │   └── welcome.html    : si tratta di un file HTML che può essere modificato in maniera libera per includere descrizioni, immagini, ecc. per personalizzare la pagina principale dell'installazione. Può esere modificata anche dall'interno del sistema.
├── sessions/               : cartella di sistema
└── version                 : file di sistema
```


Com'è abbastanza chiaro dalla struttura sopra l'unca cartella sulla quale dobbiamo intervenire e quella `projects`, 
dentro la quale vengono create le cartelle dei singoli progetti, nel nostro caso `test`.

Per ora, concentriamoci sulla sola cartella di configurazione (`project/test/cfg/`), rimandando la creazione di tutti gli altri file e 
cartelle al processo di installazione.