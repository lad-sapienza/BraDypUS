# Introduzione al processo di installazione

Per la creazione della cartella di progetto seguiremo
in questa guida due strade diverse.

## 1. La via brevissima
La prima e più veloce in assolute presuppone:
- Una connesione internet
- Git disponibile nel sistema operativo
- Terminale / Riga di commando
- Ambiente Unix (e quindi anche MacOS) o Linux

Verrà fatto uso di una applicazione appositamente creata 
per avviare il sistema Bradypus


## 2. La via media
La seconda via è più lunga in quanto prevede lo scarcamento manuale 
delle varie risorse.

Verrà fatto uso di una applicazione appositamente creata 
per avviare il sistema Bradypus

## 3. La via lunga
La creazione del tutto manuale di file, cartelle e database.

## bdus-cli
Per la via _brevissima_ e quella _media_ si farà uso di uno strumento
open source, abbastanza semplice da usare, liberamente disponibile
all'indirizzo [https://github.com/jbogdani/bdus-cli](https://github.com/jbogdani/bdus-cli)
e chiamato `bdus-cli`, dove cli sta per _command line interface_ e rimarcare
subito che si tratta di un programma da eseguire da linea di commando.

Essendo un programma scritto in PHP funziona su qualsiasi sistema operativo che
disponga da PHP.

Il programma permette di creare una applicazione Bradypus a partira da una semplice cartella
dove sono presenti unicamente i file di configurazione fin qui descritti.
Il programma crea l'albero di file e cartelle necessario per il progetto e crea
il database Sqlite, con titte le tabelle necessarie, come da file di configurazione.
Di certo è il modo più veloce per installare Bradypus.