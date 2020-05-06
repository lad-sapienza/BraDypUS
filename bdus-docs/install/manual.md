# Installazione completamente manuale

Questa procedura è sostanzialmente uguale alla precedente
solo che invece di usate git, si scaricheranno i pachetti
necessari manualmente

Come anticipato per questa opzione velocissima si ha bisogno di:
- Una connesione internet
- [SQLiteStudio](https://sqlitestudio.pl/)
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
    [https://github.com/bdus-db/test-cfg](https://github.com/bdus-db/test-cfg)
    - cliccando sul bottone verde **Clone or download**
    - Selezionando l'opzione **Download ZIP**
    - Salvarlo nella cartella testdb
    - Estrarre il file zip
    - Creare la cartella BraDypUS/projects/cfg
    - Spostare il contenuto della cartella scaricata in BraDypUS/projects/cfg
6. Creare la cartella BraDypUS/projects/backups
7. Creare la cartella BraDypUS/projects/db
8. Creare la cartella BraDypUS/projects/export
9. Creare la cartella BraDypUS/projects/files
10. Creare la cartella BraDypUS/projects/geodata
11. Creare la cartella BraDypUS/projects/templates
12. Creare la cartella BraDypUS/projects/tmp
13. Creare il file BraDypUS/projects/welcome.html
14. Inserire il seguente testo dentro il file BraDypUS/projects/welcome.html
    ```html
    <h1>TEST</h1>
    <h3>A BraDypUS database</h3>
```
14. Aprire SQLiteStudio
15. Creare il database bdus.sqlite all'interno della cartella BraDypUS/projects/db
16. Eseguire le seguenti porzioni SQL per creare le tabelle necessarie:

    ```sql
    PRAGMA foreign_keys = off;
    BEGIN TRANSACTION;


    CREATE TABLE test__bibliografia (
        id          INTEGER PRIMARY KEY,
        creator     TEXT,
        breve       TEXT,
        autori      TEXT,
        anno        TEXT,
        descrizione TEXT
    );

    CREATE TABLE test__files (
        id          INTEGER PRIMARY KEY,
        creator     TEXT,
        ext         TEXT,
        keywords    TEXT,
        description TEXT,
        printable   INTEGER,
        filename    TEXT
    );

    CREATE TABLE test__geodata (
        id           INTEGER PRIMARY KEY,
        table_link   TEXT    NOT NULL,
        id_link      INTEGER NOT NULL,
        geometry     TEXT    NOT NULL,
        geo_el_elips INTEGER,
        geo_el_asl   INTEGER
    );

    CREATE TABLE test__m_biblio (
        id         INTEGER PRIMARY KEY,
        table_link TEXT,
        id_link    TEXT,
        breve      TEXT,
        pp         TEXT
    );

    CREATE TABLE test__m_campioni (
        id           INTEGER PRIMARY KEY,
        table_link   TEXT,
        id_link      TEXT,
        dataprelievo TEXT,
        tipoanalisi  TEXT,
        note         TEXT
    );

    CREATE TABLE test__queries (
        id        INTEGER PRIMARY KEY,
        user_id   INTEGER,
        date      DATE,
        name      TEXT,
        text      TEXT,
        [table]   TEXT,
        is_global INTEGER
    );

    CREATE TABLE test__rs (
        id       INTEGER PRIMARY KEY,
        tb       TEXT,
        first    TEXT,
        second   TEXT,
        relation INTEGER
    );

    CREATE TABLE test__siti (
        id          INTEGER PRIMARY KEY,
        creator     TEXT,
        nome        TEXT,
        tipologia   TEXT,
        cronologia  TEXT,
        descrizione TEXT
    );

    CREATE TABLE test__us (
        id          INTEGER PRIMARY KEY,
        creator     TEXT,
        sito        TEXT,
        nome        TEXT,
        tipo        TEXT,
        descrizione TEXT
    );

    CREATE TABLE test__userlinks (
        id     INTEGER PRIMARY KEY
                    NOT NULL,
        tb_one TEXT    NOT NULL,
        id_one INTEGER NOT NULL,
        tb_two TEXT    NOT NULL,
        id_two INTEGER NOT NULL,
        sort   INTEGER
    );

    CREATE TABLE test__users (
        id        INTEGER PRIMARY KEY,
        name      TEXT,
        email     TEXT,
        password  TEXT,
        privilege INTEGER,
        settings  TEXT
    );

    CREATE TABLE test__vocabularies (
        id   INTEGER PRIMARY KEY,
        voc  TEXT,
        def  TEXT,
        sort INTEGER
    );

    INSERT INTO test__users (id, name, email, password, privilege, settings) VALUES (1, 'Test User', 'test@bradypus.net', 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3', 1, NULL);

    COMMIT TRANSACTION;
    PRAGMA foreign_keys = on;
    ```

17. Rimuovo le cartelle che non servono più, ovvero test-cfg

A questo punto possiamo aprire il browser all'indirizzo 
[http://localhost/testdb/BraDypUS/](http://localhost/testdb/BraDypUS/) ed entrare nell'applicazione con:
- username: test@bradypus.net
- password: test
