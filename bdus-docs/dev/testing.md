---
title: Testing
---

# Testing

BraDypUS has a PHPUnit suite (unit + integration) and a Hurl end-to-end API
suite that covers 18 phases of the full application lifecycle. Both suites are
orchestrated by `test.sh`, which manages disposable Docker containers and
supports all three database engines.

---

## Quick start — one command

```bash
cd bdus-api

# SQLite (default): PHPUnit + 18 Hurl phases
./test.sh

# PostgreSQL only (Hurl, disposable postgres:16 container)
./test.sh --db=pgsql --skip-unit

# MariaDB only (Hurl, disposable mariadb:11 container)
./test.sh --db=mysql --skip-unit
```

All three engines pass identically. Requirements: Docker, `hurl` ≥ 4.0, `jq`.

### `test.sh` flags

| Flag | Effect |
|---|---|
| `--db=sqlite\|pgsql\|mysql` | DB engine (default: `sqlite`) |
| `--skip-unit` | Skip PHPUnit, run Hurl only |
| `--skip-e2e` | Skip Hurl, run PHPUnit only |
| `--keep` | Leave the container running after tests |
| `--seed-demo` | Populate the app with demo data (implies `--keep`) |

---

## PHPUnit — unit and integration tests

### How it works

PHPUnit tests use an **in-memory SQLite** database created fresh for each test
class. There is no network I/O and no disk access for the database.

```bash
# Inside the running Docker container
docker compose exec app php vendor/bin/phpunit --testdox

# One suite only
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit --testsuite Integration
```

Configuration: `phpunit.xml`. Bootstrap: `tests/bootstrap.php`.

### `BdusTestCase` — shared base class

All integration tests extend `Tests\Support\BdusTestCase`.

```
tests/
├── bootstrap.php
├── Support/
│   └── BdusTestCase.php   ← in-memory SQLite DB, DI helpers
├── Unit/                  ← pure-logic tests (no DB required)
└── Integration/           ← controller-level tests (real in-memory DB)
```

**Key helpers:**

```php
// Instantiate a controller with full DI (DB, Config, UAC, logger)
$ctrl = $this->makeController(RecordController::class, get: ['tb' => 'us', 'id' => 1]);

// Call a method and capture the JSON it echoes
$result = $this->callController(LoginController::class, 'auth', post: [
    'email'    => 'admin@example.com',
    'password' => 'secret',
    'app'      => 'testapp',
]);

// Set the simulated user privilege
$this->setPrivilege(\UAC\UAC::SUPERADM);   // 1
$this->setPrivilege(\UAC\UAC::READ);       // 30
```

---

## Hurl — end-to-end API suite

18 sequential phases exercise the full REST API against a live server.
Each phase may capture values (JWT, record IDs) that later phases consume.

| Phase | File | What it tests |
|---|---|---|
| 01 | `01_create_app.hurl` | `POST /api/new-app` — create application (parametric DB engine) |
| 02 | `02_login.hurl` | Auth: JWT capture, negative cases, refresh |
| 03 | `03_config_tables.hurl` | Table & field config CRUD |
| 04 | `04_records.hurl` | Record create / read / update / delete |
| 05 | `05_rs.hurl` | Stratigraphic relations |
| 06 | `06_search.hurl` | Simple, advanced, SQL expert, saved queries |
| 07 | `07_charts.hurl` | Chart CRUD + data query |
| 08 | `08_backup.hurl` | Backup create / list / delete |
| 09 | `09_users_privileges.hurl` | User management + privilege enforcement |
| 10 | `10_cleanup.hurl` | Logout |
| 11 | `11_versions.hurl` | Record version history + restore |
| 12 | `12_import.hurl` | CSV / JSON / GeoJSON import |
| 13 | `13_migrations.hurl` | Migration status endpoint |
| 14 | `14_relations.hurl` | bdus_cfg_relations CRUD |
| 15 | `15_vocabularies.hurl` | Vocabulary CRUD + sort |
| 16 | `16_welcome_search_replace.hurl` | Welcome text + bulk search & replace |
| 17 | `17_zotero.hurl` | Zotero library management + citation links |
| 18 | `18_json_filter.hurl` | Directus-style `filter[field][op]=value` notation |

### Running ad-hoc (without `test.sh`)

```bash
# Against a server already running on port 8080
bash tests/api/run.sh tests/api/vars.env

# Dry-run: list all phases and their steps
bash tests/api/run.sh tests/api/vars.env --list

# Run from a specific phase
bash tests/api/run.sh tests/api/vars.env --from=06

# Run a single phase
bash tests/api/run.sh tests/api/vars.env --only=04
```

Copy `tests/api/vars.env` to `tests/api/vars.local.env` to override the server
URL, app name, or credentials.

### Multi-engine test infrastructure

`01_create_app.hurl` uses variables for the DB engine and connection parameters.
`test.sh --db=pgsql` / `--db=mysql` injects those variables automatically and
starts the matching disposable service container:

| Engine | Container image | Compose override |
|---|---|---|
| SQLite | — (file in `app` container) | `docker-compose.test.yml` |
| PostgreSQL | `postgres:16-alpine` | `docker-compose.test.pg.yml` |
| MariaDB | `mariadb:11` | `docker-compose.test.mysql.yml` |

---

## Writing tests

### New integration test

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

### New Hurl phase

Add a numbered file in `tests/api/`, then call `run_phase` (or `capture_phase`)
in `tests/api/run.sh`:

```bash
# tests/api/run.sh (add after Phase 17)
header "Phase 19 — My new feature"
run_phase "My feature" "19_my_feature.hurl" \
  --variable "jwt=${JWT}"
```

Available variables from `vars.env` / `vars.test.env`:
`base_url`, `app_name`, `admin_email`, `admin_password`,
`db_engine`, `db_host`, `db_port`, `db_name`, `db_username`, `db_password`.

### Cross-engine code rules

Any new PHP code that touches the database must be engine-agnostic:

- Use `$db->getEngine()` to branch on engine-specific syntax only when unavoidable.
- For table existence checks, use `$manage->tableExists(string $table)`.
- For column checks, use `$manage->columnExists(string $table, string $column)`.
- For index checks, use `$manage->indexExistsPublic(string $table, string $index)`.
- Never query `sqlite_master` or use `PRAGMA` outside of SQLite-guarded blocks.
- Never use `INSERT OR IGNORE` or `INSERT OR REPLACE` (SQLite/MySQL only).
- Never use `last_insert_rowid()` — use `$db->query($sql, $params, 'id')` instead.
- Partial indexes (`CREATE INDEX ... WHERE ...`) are not supported on MySQL;
  use an engine check or a regular index (MySQL already allows multiple NULLs in UNIQUE).
