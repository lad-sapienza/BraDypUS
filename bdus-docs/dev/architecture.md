---
title: Bootstrapping & routing
---

# Bootstrapping & routing

This page traces the life of a single HTTP request from the moment it hits
the web server to the moment a JSON response is returned. Understanding this
flow is the prerequisite for working on any part of the backend.

---

## Directory layout (backend)

```
bdus-api/
├── index.php            ← single entry point (all requests)
├── controllers/         ← one class per module: Bdus\Controllers\{Name}
│   ├── Record.php, Config.php, Search.php, Templates.php, …
├── lib/
│   ├── bootstrap.php    ← constants, autoload, app + JWT resolution
│   ├── Bdus/
│   │   ├── App.php         ← kernel: DB, logger, major-upgrade pre-flight
│   │   ├── Router.php      ← FastRoute table + ROUTE_PRIVILEGE map
│   │   ├── Dispatcher.php  ← auth gate, DI, controller invocation
│   │   └── Controller.php  ← abstract base class for controllers
│   ├── Auth/ JWT/ UAC/ Config/ DB/ Record/ Template/ …
│   └── …                  ← see the lib/ map
├── projects/            ← runtime data, one dir per application (not committed)
│   └── {app}/
│       ├── config.json    ← app settings (DB engine, credentials, …)
│       ├── .jwt_secret    ← per-app JWT signing secret
│       ├── files/         ← uploaded files
│       ├── backups/       ← DB dumps
│       ├── export/        ← export output
│       └── db/            ← SQLite database file (SQLite engine only)
├── tests/               ← Unit/, Integration/, api/ (hurl)
├── openapi.yaml         ← API contract
└── vendor/              ← Composer dependencies
```

Table/field/relation config (`bdus_cfg_tables`, `bdus_cfg_fields`,
`bdus_cfg_relations`) and print templates (`bdus_cfg_templates`) live **in the
database itself**, not as files on disk.

---

## Request lifecycle

```
Browser / Vue frontend
  │
  │  HTTP request (all paths rewritten to index.php by .htaccess / nginx)
  ▼
index.php
  ├─ CORS preflight handling (env BRADYPUS_CORS_ORIGIN)
  ├─ registers a last-resort handler: any fatal error → JSON envelope
  ├─ ob_start()
  ├─ require lib/bootstrap.php
  │     ├── MAIN_DIR, DEBUG_ON, error reporting
  │     ├── Composer autoload (vendor/autoload.php)
  │     ├── Router::resolveRequest(): first URL segment
  │     │     /{app}/api/…   → application-scoped
  │     │     /api/…         → instance-level (see below)
  │     ├── Bearer token peeked → app claim; 403 app_mismatch if URL app ≠ token app
  │     ├── define APP, PREFIX, PROJ_DIR (when the project dir exists)
  │     ├── JwtManager::decode() → Auth\CurrentUser::set(…) when valid
  │     └── runtime dirs ensured (cache/, files/, backups/, export/, db/)
  ├─ Bdus\Router::dispatch()
  │     ├── FastRoute matches the bare /api/… path
  │     ├── wrong path shape → 404 app_prefix_required / app_prefix_not_allowed
  │     ├── METHOD_NOT_ALLOWED → 405 method_not_allowed (exits)
  │     ├── FOUND → merges URL vars + JSON body into $_GET / $_POST / $_REQUEST
  │     │           sets $_GET['obj'] = controller FQCN, $_GET['method']
  │     └── NOT_FOUND → returns, request continues with defaults
  └─ (new Bdus\App())->start()
        ├── new DB(APP)  — PDO connection (when APP is defined)
        ├── Monolog logger (bdus_logs table in production, file in debug)
        ├── pre-v5 apps only: Migrate::maybeRemovePrefix / maybeAddBdusPrefix,
        │   and BDUS_MAJOR_UPGRADE is defined
        └── Bdus\Dispatcher::dispatch($_GET, $_POST, $_REQUEST)
              ├── $required = Router::requiredPrivilege($obj, $method)
              ├── major-upgrade gate → 409 major_upgrade_required
              ├── auth gate
              │     ├── 'none' → skip
              │     ├── no JWT user → try API key (Bearer {key}) → else 401
              │     ├── human user: token_version + privilege re-read from DB
              │     │     (revoked token → 401 token_invalidated)
              │     └── API key below required tier → 403 not_enough_privilege
              ├── method_exists + is_a($obj, Bdus\Controller) checks
              ├── new $obj($get, $post, $request)
              ├── inject DB · logger · Config · UAC
              └── $controller->$method()   ← runs the actual logic
```

Migrations are **not** run on every request: since v5 they run from the
upgrade endpoints (`Upgrade::runMajor`, `Upgrade::runMinor`) and the
instance/app creation flow. A pending major upgrade makes every authenticated
route answer `409 major_upgrade_required`.

---

## lib/bootstrap.php — in detail

`bootstrap.php` runs before any application logic.

### 1. Autoloading

Only Composer's PSR-4 autoloader is used (`composer.json`):

| Namespace | Directory |
|---|---|
| `Bdus\Controllers\` | `controllers/` |
| everything else (`Bdus\`, `Auth\`, `DB\`, …) | `lib/` |

So `Bdus\Controllers\Record` is `controllers/Record.php` and `DB\DB` is
`lib/DB/DB.php`. There is no custom autoloader and no `*_ctrl` naming.

### 2. App and JWT resolution

The application is the **first URL path segment** (`/{app}/api/…`, v5.9.0).
When there is no segment, the app comes from the `app` claim of the Bearer
token (only used to select the per-app secret), then from `?app=` or the JSON
body (login, OAuth callback).

When the project directory exists, `APP`, `PREFIX` (always empty in v5) and
`PROJ_DIR` are defined, and a valid token populates `Auth\CurrentUser`.
Privilege and `token_version` are re-checked against the database by the
Dispatcher, so the privilege inside the JWT is never trusted on its own.

### 3. Runtime directories

If `APP` is defined, these directories are created (mode 0777) when missing,
and a `RuntimeException` is thrown if one is not writable:

```
cache/  cache/img/
projects/{app}/files/  backups/  export/  db/
```

---

## Bdus\Router — FastRoute dispatcher

`Router::dispatch()` runs **before** `Bdus\App`. It maps REST paths to
`[Controller FQCN, method]`:

```php
$r->addRoute('GET',    '/api/record/{tb}/{id:\d+}', ['Bdus\\Controllers\\Record', 'getRecord']);
$r->addRoute('DELETE', '/api/file/{fileId:\d+}',    ['Bdus\\Controllers\\Record', 'deleteFile']);
```

The table is written against the bare `/api/…` path; clients call it as
`/{app}/api/…`. Only the genuinely instance-level endpoints (`Router::APP_INDEPENDENT`)
live at a bare `/api/…`:

| Endpoint | Controller |
|---|---|
| `GET /api/auth/apps` | `Login::listApps` |
| `GET /api/new-app/status`, `POST /api/new-app` | `NewApp` |
| `GET /api/info` | `Info::getInfo` |

Everything else, including login, register, password reset, refresh, logout
and OAuth, is application-scoped. Calling a scoped endpoint without the
prefix (or an independent one with it) returns a `404` with a contract error.

URL variables (`{tb}`, `{id}`, `{provider}`) are merged into `$_GET`, so
controllers read them through `$this->get['tb']`. JSON bodies are parsed
and merged into `$_POST` / `$_REQUEST`.

Other path segments are never an application name: see
`Router::RESERVED_SEGMENTS` (`api`, `login`, `new-app`, `assets`, …).

### ROUTE_PRIVILEGE map

`Router::ROUTE_PRIVILEGE` is the **single source of truth** for access control.
It maps `'Bdus\\Controllers\\Name::method'` to a privilege tier:

| Tier | Meaning | Numeric threshold |
|---|---|---|
| `none` | Public — no auth required | — |
| `read` | Any authenticated user or API key | privilege ≤ 30 |
| `edit` | Write operations | privilege ≤ 25 |
| `admin` | User management, backups, logs | privilege ≤ 10 |
| `super_admin` | Schema config, raw SQL | privilege = 1 |

Any `Controller::method` **not** listed in the map defaults to `super_admin`
(`Router::requiredPrivilege()`), the safest fallback. Controllers may enforce
further, finer checks with `Auth\Authorization::can()` and the UAC.

---

## Bdus\App and Bdus\Dispatcher

`App::start()` is the kernel: it opens the database, configures Monolog and,
for apps that have not yet been migrated to v5, runs the prefix pre-flight and
defines `BDUS_MAJOR_UPGRADE`. It then hands off to `Dispatcher`, which is
independent of the superglobals (it receives `$get`, `$post`, `$request`) and
therefore easy to unit-test.

### Dependency injection into controllers

`Dispatcher::dispatch()` instantiates the controller and injects:

```php
$ctrl = new $obj($get, $post, $request);
$ctrl->setDB($db);      // DB\DB (PDO wrapper)
$ctrl->setLog($log);    // Monolog\Logger
$ctrl->setCfg($config); // Config\Config — skipped during a major upgrade
$ctrl->setUAC($uac);    // UAC\UAC, with the user's access list when authenticated
$ctrl->$method();
```

`Config` and `UAC` are not built when a major upgrade is pending or for the
`Upgrade` controller (the system tables may not exist yet).

---

## Bdus\Controller — base class

Every controller in `controllers/` extends `Bdus\Controller`.

| Property / method | Type | Description |
|---|---|---|
| `$this->get` | `array` | URL path variables + query parameters |
| `$this->post` | `array` | form body, with the JSON body merged in |
| `$this->request` | `array` | `get` + `post` merged |
| `$this->db` | `DB\DBInterface` | injected PDO wrapper |
| `$this->log` | `Monolog\Logger` | injected logger |
| `$this->cfg` | `Config\Config` | injected app config |
| `$this->uac` | `UAC\UAC` | injected privilege enforcement |
| `returnJson(array)` | method | JSON response; adds `status: success` when missing; never emits an empty body |

**Security invariant** enforced in `Dispatcher`:

```php
method_exists($obj, $method)                 // the method must exist
is_a($obj, \Bdus\Controller::class, true)    // the class must extend Controller
```

and, since every route is in the FastRoute table, only listed
`[controller, method]` pairs can be reached from outside.

---

## Error envelope

Errors are HTTP 200 with `{ "status": "error", "code": "…" }` (the frontend
translates `code`); exceptions are `401`/`403`/`404`/`405`/`409` only for the
gates described above. Any uncaught exception or fatal error is converted to
`{ status: 'error', code: 'server_error' | 'dispatch_error', … }`; the detail
is included in `debug` only when `BRADYPUS_DEBUG=1`.

---

## CORS

CORS is handled at the very top of `index.php`, before any class is loaded:

```bash
BRADYPUS_CORS_ORIGIN=https://myapp.github.io https://localhost:5173
```

When the request `Origin` matches an allowed origin, the `Access-Control-Allow-*`
headers are emitted; preflight `OPTIONS` requests are answered with `204`.
Leave it unset when frontend and backend share the same origin.

---

## Environment variables

| Variable | Default | Effect |
|---|---|---|
| `BRADYPUS_DEBUG` | `0` | `1` enables verbose errors and file logging (`logs/error.log`) |
| `BRADYPUS_CORS_ORIGIN` | `''` | Space-separated list of allowed origins |
| `BRADYPUS_ALLOW_NEW_APP` | `0` | `1` enables the "Create new application" wizard |

Set them in `docker-compose.yml`'s `environment:` block, or via `SetEnv`
(Apache) / `fastcgi_param` (Nginx) when running without Docker.

---

## Adding a new endpoint

1. Create `controllers/MyThing.php` with
   `class MyThing extends \Bdus\Controller` in the `Bdus\Controllers` namespace.
2. Add a public method (e.g. `doStuff(): void`) that ends with
   `$this->returnJson(…)`.
3. Register the route in `Router::dispatch()`:
   ```php
   $r->addRoute('POST', '/api/mything', ['Bdus\\Controllers\\MyThing', 'doStuff']);
   ```
4. Add the privilege tier in `Router::ROUTE_PRIVILEGE`:
   ```php
   'Bdus\\Controllers\\MyThing::doStuff' => 'edit',
   ```
5. Document it in `openapi.yaml`.
6. Add tests in `tests/Unit/` or `tests/Integration/`, and an hurl case in
   `tests/api/` if it is part of the public contract.
