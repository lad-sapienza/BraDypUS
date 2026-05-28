---
title: Testing
---

# Testing

BraDypUS has a full PHPUnit test suite (571 tests, ~1 200 assertions) and a
set of end-to-end Hurl tests that exercise the live REST API. This page
explains how the suites are organised, how to run them, and how to write
new tests.

---

## Running the test suite

### Prerequisites

- PHP 8.1+, `pdo_sqlite` extension.
- Composer dependencies installed (`composer install`).

### PHPUnit

```bash
# All tests
./vendor/bin/phpunit

# One suite only
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration

# Single file
./vendor/bin/phpunit tests/Integration/LoginCtrlTest.php

# Generate HTML coverage report (requires Xdebug or PCOV)
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html coverage/
```

Configuration is in `phpunit.xml` at the project root.
The bootstrap file is `tests/bootstrap.php`.

### Hurl (end-to-end)

[Hurl](https://hurl.dev) files live in `tests/hurl/`. They require a
running BraDypUS instance (e.g. `php -S localhost:8765 index.php`).

```bash
# Run all phases in order
for f in tests/hurl/*.hurl; do hurl --test "$f"; done
```

---

## Suite structure

```
tests/
├── bootstrap.php          ← PHPUnit bootstrap: autoloaders + APP constant
├── Support/
│   └── BdusTestCase.php   ← shared base class (in-memory SQLite, helpers)
├── Unit/                  ← pure-logic tests (no DB)
│   ├── SQL/
│   ├── Config/
│   └── …
├── Integration/           ← tests that hit a real (in-memory) SQLite DB
│   ├── LoginCtrlTest.php
│   ├── RecordCtrlTest.php
│   └── … (44 files total)
└── hurl/                  ← end-to-end REST tests
    ├── 01_create_app.hurl
    ├── 02_login.hurl
    └── … (10 phases)
```

---

## `BdusTestCase` — shared test base

All integration tests extend `Tests\Support\BdusTestCase`.

### Per-class shared state

```php
protected static DB\DB $db;
protected static DB\System\Manage $manage;
```

`setUpBeforeClass()` creates a fresh **in-memory SQLite** database and runs
the full migration stack against it. `tearDownAfterClass()` closes the
connection. Every test class therefore starts with a fully-migrated,
isolated database — no disk I/O, no leftover state between classes.

### Helper methods

#### `makeController(string $ctrlClass, array $get = [], array $post = [])`

Instantiates a controller and injects all dependencies:

```php
$ctrl = $this->makeController(login_ctrl::class, post: ['username' => 'admin', 'password' => 'secret']);
```

Injected automatically:
- `DB\DB` instance (the in-memory SQLite from `static::$db`)
- A silent Monolog logger (no output during test runs)
- A `Config\Config` instance loaded from the test DB
- A `UAC\UAC` instance
- `debug = false`

#### `callController(string $ctrlClass, string $method, array $get = [], array $post = [])`

Calls a controller method and captures the JSON output it echoes:

```php
$result = $this->callController(login_ctrl::class, 'login', post: [
    'username' => 'admin',
    'password' => 'secret',
    'app'      => 'testapp',
]);
// $result is the decoded array from returnJson()
```

Internally this uses output buffering (`ob_start` / `ob_get_clean`) and
`json_decode`. Returns the decoded payload array.

#### `setPrivilege(int $level)`

Sets `Auth\CurrentUser` privilege for the duration of the test:

```php
$this->setPrivilege(UAC::SUPERADM);  // 1 — super admin
$this->setPrivilege(UAC::READ);      // 30 — read-only
```

---

## Writing an integration test

### 1. Create the test file

```php
// tests/Integration/M023MyChangeTest.php
namespace Tests\Integration;

use Tests\Support\BdusTestCase;
use DB\System\Migrations\M023_MyChange;
use DB\System\Manage;

class M023MyChangeTest extends BdusTestCase
{
    public function testColumnAdded(): void
    {
        M023_MyChange::run(new Manage(static::$db));

        $cols  = static::$db->query('PRAGMA table_info(bdus_users)', [], 'read');
        $names = array_column($cols, 'name');

        $this->assertContains('new_column', $names);
    }

    public function testIdempotent(): void
    {
        M023_MyChange::run(new Manage(static::$db));
        M023_MyChange::run(new Manage(static::$db));   // must not throw
        $this->assertTrue(true);
    }
}
```

### 2. Test a controller endpoint

```php
class RecordCtrlTest extends BdusTestCase
{
    public function testGetRecordReturnsData(): void
    {
        $this->setPrivilege(\UAC\UAC::READ);

        $result = $this->callController(
            record_ctrl::class,
            'getRecord',
            get: ['tb' => 'sites', 'id' => 1]
        );

        $this->assertArrayHasKey('core', $result);
    }
}
```

### Important notes

**`exit` calls**: Some controller methods call `exit` after `returnJson()`.
`callController()` wraps the invocation so `exit` is caught via
`register_shutdown_function` — PHPUnit is not terminated. You do not need
to suppress this manually.

**Filesystem isolation**: Tests that create files (uploads, backups, exports)
should use `sys_get_temp_dir()` for temp paths and clean up in
`tearDown()`. The in-memory DB means DB changes are auto-discarded; file
system changes are not.

**Config reload**: If a test modifies `bdus_cfg_*` tables, call
`Config\Load::reload(static::$db)` to refresh the `Config\Config` singleton
before assertions that depend on it.

---

## Integration test inventory (44 files)

| File | What it covers |
|---|---|
| `ApiCtrlTest.php` | Public REST API key auth |
| `ApiKeyCtrlTest.php` | API key CRUD |
| `BackupCtrlTest.php` | DB backup / restore |
| `ChartCtrlTest.php` | Chart CRUD + data query |
| `ConfigCtrlTest.php` | App config read / write |
| `ConfigFieldsTest.php` | Field configuration management |
| `ConfigRelationsTest.php` | Relation config management |
| `ConfigTablesTest.php` | Table configuration management |
| `ConfirmSuperAdmPwdCtrlTest.php` | Password re-confirmation |
| `CreateAppTest.php` | New application wizard |
| `DebugCtrlTest.php` | Log access |
| `FileCtrlTest.php` | File sort |
| `FreeSqlCtrlTest.php` | Raw SQL execution |
| `GeofaceCtrlTest.php` | GeoJSON read / write |
| `HomeCtrlTest.php` | Table list + migration status |
| `ImportCtrlTest.php` | CSV / GeoJSON import |
| `InfoCtrlTest.php` | Version / app info |
| `LoginCtrlTest.php` | JWT login / refresh / logout |
| `M001Test.php`–`M022Test.php` | Individual migration tests (22 files) |
| `MyHistoryCtrlTest.php` | Navigation history |
| `NewAppCtrlTest.php` | App creation wizard |
| `OAuthCtrlTest.php` | OAuth2 callback flow |
| `RecordCtrlTest.php` | Record CRUD + files + RS + links |
| `SavedQueriesCtrlTest.php` | Saved search CRUD |
| `SearchCtrlTest.php` | Search config + values |
| `SearchReplaceCtrlTest.php` | Bulk find & replace |
| `TemplatesCtrlTest.php` | Print template CRUD |
| `UserCtrlTest.php` | User management |
| `VocabulariesCtrlTest.php` | Vocabulary CRUD + sort |
| `WidgetCtrlTest.php` | Public widget endpoint |

---

## Hurl end-to-end tests

The 10 Hurl phases run against a live server in order. Each phase depends
on state produced by the previous one (JWT token stored in a variable file,
created record IDs, etc.).

| Phase | File | What it tests |
|---|---|---|
| 01 | `01_create_app.hurl` | POST `/api/new-app` — create fresh application |
| 02 | `02_login.hurl` | POST `/api/auth/login` — obtain JWT |
| 03 | `03_config.hurl` | Table + field creation via config endpoints |
| 04 | `04_vocabularies.hurl` | Vocabulary CRUD |
| 05 | `05_records.hurl` | Record create / read / update / delete |
| 06 | `06_search.hurl` | Search + saved queries |
| 07 | `07_files.hurl` | File upload / sort / delete |
| 08 | `08_export.hurl` | Export to CSV / JSON / XLSX |
| 09 | `09_backup.hurl` | Backup + restore |
| 10 | `10_cleanup.hurl` | Delete the test application |

Run a single phase:

```bash
hurl --test --variable host=http://localhost:8765 tests/hurl/05_records.hurl
```

Variables (JWT token, app name, record IDs) are passed between phases via
`--variables-file tests/hurl/vars.env`.
