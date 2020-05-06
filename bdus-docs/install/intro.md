# Introduzione al processo di installazione

Per la creazione della cartella di progetto è possibile
seguire strade diverse, che in parte si incrociano.

## 1. Interfaccia web BDUS Factory 
Per questa via piuttosto semplice è necessario disporre:
- Un browser web
- Una connessione internet

Internamente questo strumento fa uso di [bdus-cli](#bdus-cli).

[Vai alla guida](bdus-factory)

## 2. Percorso pro: terminale
Si tratta del modo più veloce in assolute e presuppone:
- Una connesione internet
- [Git](https://git-scm.com/)
- Terminale / Riga di commando
- Ambiente *nix (Unix, MacOS o Linux)

Verrà fatto uso di una applicazione appositamente creata 
([bdus-cli](#bdus-cli)) per avviare il sistema Bradypus.

[Vai alla guida](cli-pro)


## 3. La via intermedia
La terza via prevede lo scarcamento manuale (non più attraverso git)
delle varie risorse.

Verrà fatto uso di una applicazione appositamente creata 
([bdus-cli](#bdus-cli)) per avviare il sistema Bradypus

[Vai alla guida](cli-minimal)

## 4. La via lunga
La creazione del tutto manuale di file, cartelle e database.

[Vai alla guida](manual)

## bdus-cli
Per le opzioni 1, 2 e 3 si farà uso di uno strumento
open source, abbastanza semplice da usare, liberamente disponibile
all'indirizzo [https://github.com/bdus-db/bdus-cli](https://github.com/bdus-db/bdus-cli)
e chiamato `bdus-cli`, dove cli sta per _command line interface_ e rimarcare
subito che si tratta di un programma da eseguire da linea di commando.

Essendo un programma scritto in PHP funziona su qualsiasi sistema operativo che
disponga da PHP.

Il programma permette di creare una applicazione Bradypus a partira da una semplice cartella
dove sono presenti unicamente i file di configurazione fin qui descritti.
Il programma crea l'albero di file e cartelle necessario per il progetto e crea
il database Sqlite, con titte le tabelle necessarie, come da file di configurazione.
Di certo è il modo più veloce per installare Bradypus.