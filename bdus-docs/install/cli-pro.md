# Installazione da riga di commando

Come anticipato per questa opzione velocissima si ha bisogno di:
- Una connesione internet
- Git disponibile nel sistema operativo
- Terminale / Riga di commando
- Ambiente Unix (e quindi anche MacOS) o Linux  
    A cominciare dall’Anniversary Update anche 
    su Windows 10 è possibile attivare la shell bash di Linux Ubuntu.
    Per informazioni su come attivarla: 
    [https://www.html.it/guide/bash-su-windows-la-guida/](https://www.html.it/guide/bash-su-windows-la-guida/)
- Infine, naturalmente, PHP.

Per facilità usero come file di configurazione i file
dell'applicazione test che sono stati pubblicati a questo indirizzo:
[https://github.com/jbogdani/bdus-test-cfg](https://github.com/jbogdani/bdus-test-cfg).
Si tratta degli stessi file descritti finora.

## Verifica dell'ambiente di lavoro

Per testare l'ambiente di lavoro aprire il terminale e digitare

```bash
php -v
```

In risposta dovremmo avere qualcosa simile a (la versione sarà certlamente diversa, come alcune altre info):

```bash
PHP 7.3.11 (cli) (built: Feb 29 2020 02:50:36) ( NTS )
Copyright (c) 1997-2018 The PHP Group
Zend Engine v3.3.11, Copyright (c) 1998-2018 Zend Technologies
```

Verifichiamo di avere anche php_pdo per sqlite
```bash
php -i | grep pdo_sqlite 
```

In risposta dovremmo avere:

```bash
pdo_sqlite
```

Verifichiamo di avere installato git
```bash
git --version
```

In risposta dovremmo avere qualcosa simile a (la versione sarà certlamente diversa, come alcune altre info):

```bash
git version 2.24.2 (Apple Git-127)
```

## Installazione

Siamo pronti per cominciare

1. Creiamo la cartella `testdb` dove tutto si svolgerà

    ```bash
    mkdir testdb
    ```

2. Ci spostiamo al suo interno

    ```bash
    cd testdb
    ```

3. Recupero il software bradypus dalla repository ufficiale usando git
    ```bash
    git clone git@github.com:bdus-db/BraDypUS.git
    ```
    Git creera la cartella `BraDypUS` e ci metterà dentro i file scaricati dalla repository ufficiale

4. Recupero il software bdus-cli dalla repository ufficiale usando git
    ```bash
    git clone https://github.com/jbogdani/bdus-cli.git
    ```
    Git creera la cartella `bdus-cli` e ci metterà dentro i file scaricati dalla repository ufficiale

5. Recupero i file di configurazione di test
    ```bash
    git clone git@github.com:jbogdani/bdus-test-cfg.git
    ```
    Git creera la cartella `bdus-test-cfg` e ci metterà dentro i file scaricati dalla repository ufficiale.
    **Attenzione**: questo passaggio è opzionale in quanto il bdus-cli può lavorare 
    anche con i file sul server remoto, senza necessariamente averli scaricati prima

6. Creo la cartella projects dentro la cartella Bradypus
    ```bash
    mkdir BraDypUS/projects
    ```

7. Entro nella cartella bdus-cli
    ```bash
    cd bdus-cli
    ```

8. Valido i file di config e mi assicuro che non ci siano errori
    ```bash
    php bdus.php validate ../bdus-test-cfg
    ```
    Per l'utilizzo di bdus-cli si prega di fare riferimento alla guida
    [https://github.com/jbogdani/bdus-cli](https://github.com/jbogdani/bdus-cli)

    Nel caso in cui nel passaggio 5 si fosse optati per non scaricare i file sul sistema locale
    il commando per la validazione dei file sarebbe stato:
    ```bash
    php bdus.php validate https://raw.githubusercontent.com/jbogdani/bdus-test-cfg/master
    ```

9. Se tutto è andato bene con la validazione, creo finalmente il progetto
    ```bash
    php bdus.php create ../bdus-test-cfg ../BraDypUS/projects
    ```

    Nel caso in cui nel passaggio 5 si fosse optati per non scaricare i file sul sistema locale
    il commando perla creazione del progetto sarebbe stato:
    ```bash
    php bdus.php create https://raw.githubusercontent.com/jbogdani/bdus-test-cfg/master ../BraDypUS/projects
    ```

10. Vado dentro la cartella del progetto
    ```bash
    cd ../BraDypUS
    ```
11. Rimuovo file e cartelle che non servono più:
    ```bash
    rm -rf ../bdus-cli ../bdus-test-cfg
    ```

12. Avvio il web server incluso in php. Finito!
    ```bash
    php -S localhost:8000
    ```

A questo punto possiamo aprire il browser all'indirizzo 
[http://localhost:8000/](http://localhost:8000/) ed entrare nell'applicazione con:
- username: test@bradypus.net
- password: test


## In breve, in 11 passaggi

```bash
mkdir testdb
cd testdb
git clone git@github.com:bdus-db/BraDypUS.git
git clone https://github.com/jbogdani/bdus-cli.git
mkdir BraDypUS/projects
cd bdus-cli
php bdus.php validate https://raw.githubusercontent.com/jbogdani/bdus-test-cfg/master
php bdus.php create https://raw.githubusercontent.com/jbogdani/bdus-test-cfg/master ../BraDypUS/projects
cd ../BraDypUS
rm -rf ../bdus-cli ../bdus-test-cfg
php -S localhost:8000
```
