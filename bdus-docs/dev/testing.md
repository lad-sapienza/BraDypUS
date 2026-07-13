---
title: Testing
---

# Testing

BraDypUS ha una suite PHPUnit (unit + integration) e una suite Hurl end-to-end
che copre 38 fasi del ciclo di vita completo dell'applicazione. Entrambe sono
orchestrate da un unico entry point: `test.sh`, che gestisce container Docker
usa-e-getta e supporta tutti e tre i motori DB.

Per popolare rapidamente un'istanza con dati demo senza passare da `test.sh`
direttamente, vedi anche
[`seed-demo.sh`](https://github.com/lad-sapienza/BraDypUS/blob/v5/seed-demo.sh)
nel repo monorepo — un wrapper sottile su `--no-docker --setup --seed`.

---

## Quick start

```bash
cd bdus-api

# Menu interattivo: sceglie cosa eseguire
./test.sh

# Crea app + suite CRUD completa (SQLite)
./test.sh --setup --tests

# Crea app + suite + seed demo (per screenshot)
./test.sh --setup --tests --seed --keep

# Solo PHPUnit
./test.sh --unit

# Lista tutte le fasi senza eseguire
./test.sh --list
```

---

## Interfaccia di `test.sh`

### Flag di modalità (combinabili)

Se non viene passato nessun flag di modalità, `test.sh` mostra un menu interattivo.

| Flag | Cosa fa |
|---|---|
| `--unit` | Esegue PHPUnit (unit + integration) |
| `--setup` | Fasi 01–03: crea l'app e configura lo schema |
| `--tests` | Fasi 04–38: suite CRUD e feature completa |
| `--seed` | Fase 19: popola l'app con il dataset demo completo |

### Controllo delle fasi

| Flag | Cosa fa |
|---|---|
| `--list` | Elenca tutte le fasi con i loro step; non esegue nulla |
| `--from=N` | Esegue da fase N in poi (es. `--from=06`) |
| `--only=N` | Esegue solo le fasi con prefisso N (es. `--only=04` include 04, 04b, 04c…) |

::: warning Dipendenze tra fasi
`--from` e `--only` saltano la fase 04 che cattura `us_id_1` / `us_id_2`.
Le fasi che dipendono da questi ID (04b, 04c, 04e, 05, 20, 28) ricevono
variabili vuote se 04 viene saltata.
:::

### Infrastruttura

| Flag | Cosa fa |
|---|---|
| `--reset` | Cancella l'app esistente senza chiedere |
| `--db=sqlite\|pgsql\|mysql` | Motore DB (default: `sqlite`) |
| `--all-engines` | Esegue la suite su tutti e 3 i motori in sequenza |
| `--keep` | Lascia il container Docker in esecuzione dopo i test |
| `--no-docker` | Salta Docker; usa il server all'indirizzo `BASE_URL` in `vars.env` (locale o remoto) |
| `--export-demo` | Dopo `--seed`, copia l'app demo dal container di test alla cartella `projects/` host, così il container di sviluppo (porta 8080) ne ha una copia persistente. No-op con `--no-docker`. |

### Workflow comuni

```bash
# Suite completa, run pulita (cancella app esistente)
./test.sh --reset --setup --tests

# Test su PostgreSQL
./test.sh --db=pgsql --setup --tests

# CI: tutti i motori
./test.sh --all-engines --setup --tests

# Solo fase 04 e sotto-fasi (04b, 04c, 04d, 04e)
./test.sh --tests --only=04

# Da fase 06 in poi (presuppone app già configurata)
./test.sh --tests --from=06

# Screenshot workflow: seed + container in piedi
./test.sh --reset --setup --seed --keep
# → apri http://localhost:8081 nel browser per gli screenshot
# → il prossimo ./test.sh pulisce automaticamente

# Seed persistente nel container di sviluppo (porta 8080)
./test.sh --setup --tests --seed --export-demo
```

---

## Configurazione

Le variabili di ambiente sono in `tests/api/vars.env`.
Copia in `tests/api/vars.local.env` per override locali (git-ignored).

```bash
BASE_URL=http://localhost:8080   # usato con --no-docker
APP_NAME=bdus_demo
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=Admin1234!
DB_ENGINE=sqlite
```

In modalità Docker, `test.sh` sovrascrive `BASE_URL` con `http://localhost:8081`
e inietta le credenziali DB per pgsql/mysql automaticamente.

---

## Attenzione: schema bdus_users nei test

I test PHPUnit che creano `bdus_users` lo fanno manualmente con `CREATE TABLE` hardcoded
nel metodo `createSchema()` della singola classe. **Quando si aggiunge una colonna a
`bdus_users` tramite migration, bisogna aggiornare tutti i `CREATE TABLE bdus_users`
nei file di test.** File da controllare:

```
tests/Integration/LoginCtrlTest.php
tests/Integration/ConfirmAdminPwdCtrlTest.php
tests/Integration/FreeSqlCtrlTest.php
tests/Integration/SavedQueriesCtrlTest.php
tests/Integration/ChartCtrlTest.php
tests/Integration/UserCtrlTest.php
```

`M022MigrationTest` e simili vanno lasciati invariati: testano la migration stessa,
quindi partono intenzionalmente da uno schema precedente.

---

## PHPUnit — test unit e integration

PHPUnit usa un database SQLite **in-memory** creato da zero per ogni classe di test.
Nessun I/O di rete, nessun accesso a disco per il DB.

```bash
# Direttamente nel container Docker
docker compose exec app php vendor/bin/phpunit --testdox

# Una suite specifica
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit --testsuite Integration
```

Configurazione: `phpunit.xml`. Bootstrap: `tests/bootstrap.php`.

### `BdusTestCase` — classe base condivisa

Tutti i test di integrazione estendono `Tests\Support\BdusTestCase`.

```
tests/
├── bootstrap.php
├── Support/
│   └── BdusTestCase.php   ← SQLite in-memory, DI helpers
├── Unit/                  ← logica pura (nessun DB)
└── Integration/           ← test a livello controller (DB in-memory reale)
```

**Helper chiave:**

```php
// Instanzia un controller con DI completo (DB, Config, UAC, logger)
$ctrl = $this->makeController(RecordController::class, get: ['tb' => 'us', 'id' => 1]);

// Chiama un metodo e cattura il JSON che produce
$result = $this->callController(LoginController::class, 'auth', post: [
    'email'    => 'admin@example.com',
    'password' => 'secret',
    'app'      => 'testapp',
]);

// Imposta il privilegio utente simulato
$this->setPrivilege(\UAC\UAC::SUPERADM);   // 1
$this->setPrivilege(\UAC\UAC::READ);       // 30
```

---

## Hurl — suite E2E API

Le fasi si eseguono in sequenza su un server live. Alcune catturano valori (JWT,
ID record) che le fasi successive consumano. Tutte sono gestite da
`tests/api/_phases.sh` (sorgente interna, non eseguire direttamente).

### Fasi di setup (run con `--setup`)

| Fase | File | Cosa testa |
|---|---|---|
| 01 | `01_create_app.hurl` | `POST /api/new-app` — creazione app (DB parametrico) |
| 02 | `02_login.hurl` | Auth: cattura JWT, casi negativi, refresh |
| 03 | `03_config_tables.hurl` | Config tabelle e campi CRUD; crea `crud_test` |

### Fasi di test (run con `--tests`)

| Fase | File | Cosa testa |
|---|---|---|
| 04 | `04_records.hurl` | Record create/read/update/delete; cattura `us_id_1`, `us_id_2` |
| 04b | `04b_plugin_crud.hurl` | Righe plugin (repeater) CRUD |
| 04c | `04c_file_upload.hurl` | Upload file, attach, sort, delete |
| 04d | `04d_export.hurl` | Export CSV, JSON, XLSX |
| 04e | `04e_manual_links.hurl` | Link manuali tra record (userlinks) |
| 05 | `05_rs.hurl` | Relazioni stratigrafiche (Harris Matrix) |
| 06 | `06_search.hurl` | Ricerca semplice, avanzata, SQL expert |
| 07 | `07_charts.hurl` | Chart CRUD + query dati |
| 08 | `08_backup.hurl` | Backup create/list/delete |
| 09 | `09_users_privileges.hurl` | Gestione utenti + enforcement privilegi |
| 10 | `10_cleanup.hurl` | Drop `crud_test` + logout *(sempre alla fine di `--tests`)* |
| 11 | `11_versions.hurl` | Versioni record + restore |
| 12 | `12_import.hurl` | Import CSV / JSON / GeoJSON |
| 13 | `13_migrations.hurl` | Stato migrazioni |
| 14 | `14_relations.hurl` | `bdus_cfg_relations` CRUD |
| 15 | `15_vocabularies.hurl` | Vocabulary CRUD + sort |
| 16 | `16_welcome_search_replace.hurl` | Testo welcome + search & replace globale |
| 17 | `17_zotero.hurl` | Gestione libreria Zotero + citation links |
| 18 | `18_json_filter.hurl` | Filtro stile Directus `filter[field][op]=value` |
| 20 | `20_fuzzy_date.hurl` | Plugin fuzzy-date end-to-end |
| 21 | `21_chrono_filter.hurl` | Filtro `_chrono_overlap` |
| 22 | `22_schema_changes.hurl` | Modifiche strutturali schema (add/drop colonne) |
| 23 | `23_widgets.hurl` | Widget dashboard CRUD |
| 24 | `24_logs.hurl` | Log applicazione |
| 25 | `25_api_keys.hurl` | API key management |
| 26 | `26_saved_queries.hurl` | Query salvate CRUD |
| 27 | `27_templates.hurl` | Template layout record |
| 28 | `28_geoface_ops.hurl` | Geoface feature CRUD |
| 29 | `29_history_admin.hurl` | History + gate admin password |
| 30 | `30_file_sort.hurl` | Ordinamento file drag-and-drop |
| 31 | `31_fk_indexes.hurl` | Indici FK definiti dall'utente |
| 32 | `32_duplicate.hurl` | Duplicazione record (`POST /api/record/{tb}/{id}/duplicate`) |
| 33 | `33_upgrade_status.hurl` | Endpoint stato upgrade (`GET /api/upgrade/status`) |
| 34 | `34_file_management.hurl` | Gestione file: list, patch, replace |
| 35 | `35_file_link_unlink.hurl` | Link/unlink file a record |
| 36 | `36_dbml.hurl` | Import/export schema via DBML |
| 37 | `37_chrono_timeline.hurl` | Endpoint timeline cronologica comparata |
| 38 | `38_osteology.hurl` | Plugin osteologico (inventario scheletrico) |

### Fasi di seed (run con `--seed`)

| Fase | File | Cosa fa |
|---|---|---|
| 19 | `19_seed_demo.hurl` | Popola l'app con il dataset demo completo (siti, complessi, saggi, US, reperti, sepolture, RS relations, geodata, chart) |

---

## Infrastruttura multi-motore

`01_create_app.hurl` usa variabili per motore e connessione DB.
`test.sh --db=pgsql` / `--db=mysql` inietta queste variabili e avvia il container
del servizio corretto:

| Motore | Immagine container | Override compose |
|---|---|---|
| SQLite | — (file nel container `app`) | `docker-compose.test.yml` |
| PostgreSQL | `postgres:16-alpine` | `docker-compose.test.pg.yml` |
| MariaDB | `mariadb:11` | `docker-compose.test.mysql.yml` |

---

## Scrivere nuovi test

### Nuovo test di integrazione PHPUnit

```php
// tests/Integration/MyFeatureTest.php
namespace Tests\Integration;

use Tests\Support\BdusTestCase;

class MyFeatureTest extends BdusTestCase
{
    public function testItWorks(): void
    {
        $this->setPrivilege(\UAC\UAC::SUPERADM);

        $result = $this->callController(
            \Bdus\Controllers\MyController::class,
            'doSomething',
            post: ['key' => 'value']
        );

        $this->assertEquals('success', $result['status']);
    }
}
```

### Nuova fase Hurl

1. Crea `tests/api/NN_nome.hurl` con il numero di fase successivo.
2. Aggiungi il blocco corrispondente in `tests/api/_phases.sh` dentro `run_tests()`:

```bash
# In _phases.sh → run_tests()
if should_run "NN"; then
  header "Phase NN — Nome feature"
  run_phase "Nome feature" "NN_nome.hurl" \
    --variable "jwt=${JWT}"
fi
```

Le variabili disponibili via `HURL_VARS`:
`base_url`, `app_name`, `admin_email`, `admin_password`,
`db_engine`, `db_host`, `db_port`, `db_name`, `db_username`, `db_password`.

### Regole per codice cross-engine

Qualsiasi codice PHP che tocca il database deve essere engine-agnostic:

- Usa `$db->getEngine()` per branching engine-specific solo quando strettamente necessario.
- Per verificare l'esistenza di una tabella: `$manage->tableExists(string $table)`.
- Per verificare una colonna: `$manage->columnExists(string $table, string $column)`.
- Per verificare un indice: `$manage->indexExistsPublic(string $table, string $index)`.
- Non usare `sqlite_master` o `PRAGMA` fuori da blocchi SQLite-guard.
- Non usare `INSERT OR IGNORE` / `INSERT OR REPLACE` (SQLite/MySQL only).
- Non usare `last_insert_rowid()` — usa `$db->query($sql, $params, 'id')`.
- Gli indici parziali (`CREATE INDEX ... WHERE ...`) non sono supportati su MySQL.
