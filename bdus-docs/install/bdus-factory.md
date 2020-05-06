# Installazione usando BDUS Factory

BDUS Factory è un'applicazione [web open source](https://github.com/bdus-db/bdus-factory)
raggiungibile all'indirizzo [bdus-factory.bradypus.net](https://bdus-factory.bradypus.net/)
che permette di creare in maniera del tutto automatica una applicazione
Bradypus a partira dai file principali di configurazione.

Si può pensare a BDUS Factory come una interfaccia a [bdus-cli](https://github.com/bdus-db/bdus-cli).

Come anticipato per questa opzione velocissima si ha bisogno di:
- Una connesione internet
- Un server web funzionante
- Infine, naturalmente, PHP.

Per facilità usero come file di configurazione i file
dell'applicazione test che sono stati pubblicati a questo indirizzo:
[https://github.com/bdus-db/test-cfg](https://github.com/bdus-db/test-cfg).
Si tratta degli stessi file descritti finora.

## Installazione

Siamo pronti per cominciare

1. Creiamo la cartella `testdb` all'interno della cartella web del mio server
2. Ci spostiamo al suo interno
3. Recupero il software bradypus dalla repository ufficiale:
    - andando all'indirizzo
    [https://github.com/bdus-db/BraDypUS](https://github.com/bdus-db/BraDypUS)
    - cliccando sul bottone verde **Clone or download**
    - Selezionando l'opzione **Download ZIP**
    - Salvarlo nella cartella testdb
    - Estrarre il file zip
    - Cancellare il file zip non più necessario
4. Creo la cartella `projects` dentrola cartella Bradypus
5. Recupero i file di configurazione di test
    - andando all'indirizzo
    [https://bdus-factory.bradypus.net](https://bdus-factory.bradypus.net)
    - Modifico sul browser il contenuto dei singoli file
    - Quando la modifica è completa click sul bottone **Send**
    - Dopo una breve attesa viene visualizato un breve report e il link per scaricare 
    l'appilicazione in formato ZIP. Se i file di configurazione avevano qualche problema
    il report fornisce informazioni sull'errore
    - Scaricare ed estrarre il file zip nella cartella `BradypUS/projects/test`
    - Cancellare il file zip scaricato

A questo punto possiamo aprire il browser all'indirizzo 
[http://localhost/testdb/BraDypUS/](http://localhost/testdb/BraDypUS/) ed entrare nell'applicazione con:
- username: test@bradypus.net
- password: test
