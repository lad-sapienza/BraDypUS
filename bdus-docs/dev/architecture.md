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
├── lib/
│   ├── constants.php    ← bootstrap: constants, autoloaders, JWT auth
│   ├── autoLoader.php   ← custom PSR-4-like resolver
│   ├── Controller.php   ← abstract base class for all modules
│   ├── Bdus/
│   │   ├── App.php      ← orchestrator (DB, logger, migrations, routing)
│   │   └── Router.php   ← FastRoute dispatcher + ROUTE_PRIVILEGE map
│   └── …               ← lib/ namespaces (see lib/ map)
├── modules/             ← 37 controller modules
│   └── {name}/{name}.php
├── projects/            ← runtime data, one dir per application
│   └── {app}/
│       ├── cfg/         ← YAML config files
│       ├── files/       ← uploaded files
│       ├── backups/     ← DB dumps
│       └── db/          ← SQLite files (if SQLite engine)
└── vendor/              ← Composer dependencies
```

---

## Request lifecycle

```
Browser / Vue frontend
  │
  │  HTTP request (all paths rewritten to index.php by .htaccess / nginx)
  ▼
index.php
  ├─ CORS preflight handling (env BRADYPUS_CORS_ORIGIN)
  ├─ ob_start()
  ├─ require lib/constants.php
  │     ├── define MAIN_DIR, DEBUG_ON
  │     ├── register autoLoader (lib/ + modules/)
  │     ├── require vendor/autoload.php (Composer)
  │     └── JWT / app resolution → define APP, PREFIX, PROJ_DIR
  │                               → Auth\CurrentUser::set(…) if valid token
  ├─ Bdus\Router::dispatch()
  │     ├── FastRoute matches /api/… path
  │     │     → merges URL vars into $_GET / $_REQUEST
  │     │     → parses JSON body → $_POST / $_REQUEST
  │     │     → sets $_GET['obj'] = 'foo_ctrl', $_GET['method'] = 'bar'
  │     └── NOT_FOUND → falls through (legacy ?obj=&method= params)
  ├─ Legacy public REST API check (/api/{app}/… → api_ctrl::run)
  └─ new Bdus\App($_GET, $_POST, $_REQUEST)->start()
        ├── DB::__construct(APP) — opens PDO connection
        ├── Monolog logger setup
        ├── Migrate::maybeRemovePrefix()   ─┐
        ├── Migrate::maybeAddBdusPrefix()   ├─ idempotent pre-flight
        ├── Migrate::run()                 ─┘
        └── App::route()
              ├── $obj    = $_GET['obj']    (default: 'home_ctrl')
              ├── $method = $_GET['method'] (default: 'showAll')
              ├── Router::requiredPrivilege($obj, $method)
              │     → 'none' | 'read' | 'edit' | 'admin' | 'super_admin'
              ├── Auth gate
              │     ├── 'none' → skip
              │     ├── not authenticated → 401
              │     └── API key + insufficient privilege → 403
              ├── method_exists($obj, $method) check
              ├── get_parent_class($obj) === 'Controller' check
              ├── new $obj($get, $post, $request)
              ├── inject: DB · logger · Config · UAC · debug flag
              └── $controller->$method()   ← runs the actual logic
```

---

## lib/constants.php — Bootstrap in detail

`constants.php` runs before any application logic and sets up three things:

### 1. Autoloading

Two autoloaders are registered in sequence:

**`autoLoader`** (custom, `lib/autoLoader.php`) resolves:

| Class pattern | Resolution |
|---|---|
| `Foo_ctrl` (ends in `_ctrl`) | `modules/foo/foo.php` |
| `Namespace\ClassName` | `lib/Namespace/ClassName.php` |
| `ClassName` (no namespace) | `lib/ClassName.php` or `lib/ClassName.inc` |

**Composer's PSR-4 autoloader** (`vendor/autoload.php`) handles all
third-party packages (league/oauth2-*, monolog, nikic/fast-route, etc.).

### 2. JWT resolution (app context)

Every authenticated request carries an `Authorization: Bearer <token>` header.
`constants.php` extracts it, peeks at the unverified `app` claim to know which
per-app secret to use, then fully verifies the signature.

On success:
- `APP`, `PREFIX`, `PROJ_DIR` constants are defined.
- `Auth\CurrentUser::set(…)` stores the authenticated user for the request.

For **unauthenticated** requests (login, app list) the app is resolved from
`$_REQUEST['app']` or the raw JSON body — no token required.

### 3. Runtime directories

If `APP` is defined, `constants.php` ensures these directories exist and are
writable, creating them with `mkdir(0777, true)` if absent:

```
cache/
cache/img/
projects/{app}/files/
projects/{app}/backups/
projects/{app}/export/
projects/{app}/db/
```

---

## Bdus\Router — FastRoute dispatcher

`Router::dispatch()` is a static method that runs **before** `Bdus\App`.
It maps REST paths to `[ctrl, method]` pairs:

```php
// Route definition examples:
$r->addRoute('GET',    '/api/record/{tb}/{id:\d+}', ['record_ctrl', 'getRecord']);
$r->addRoute(['GET','POST'], '/api/records/{tb}',   ['record_ctrl', 'getRecords']);
$r->addRoute('DELETE', '/api/record/{tb}/{id:\d+}', ['record_ctrl', 'erase']);
```

URL path variables (`{tb}`, `{id}`, `{provider}`) are merged into `$_GET` so
controllers read them via `$this->get['tb']` etc.

JSON request bodies (Vue sends `Content-Type: application/json`) are parsed
and merged into `$_POST` / `$_REQUEST`. PHP only auto-populates `$_POST` for
form-encoded bodies.

`NOT_FOUND` from FastRoute is **not** an error — it falls through to the legacy
`?obj=&method=` routing still used by some internal requests.

### ROUTE_PRIVILEGE map

`Router::ROUTE_PRIVILEGE` is the **single source of truth** for access control.
It maps `'ctrl::method'` to a privilege tier:

| Tier | Meaning | Numeric threshold |
|---|---|---|
| `none` | Public — no auth required | — |
| `read` | Any authenticated user or API key | privilege ≤ 30 |
| `edit` | Write operations | privilege ≤ 25 |
| `admin` | User management, backups, logs | privilege ≤ 10 |
| `super_admin` | Schema config, raw SQL | privilege = 1 |

Any `ctrl::method` **not** listed in the map defaults to `super_admin` — the
safest possible fallback.

---

## Bdus\App — Orchestrator

`App::start()` wires the request together:

1. **Opens the DB connection** (`DB\DB` → PDO wrapper, auto-selects
   MySQL / PostgreSQL / SQLite based on `config.json`).
2. **Sets up Monolog** — writes to the `bdus_logs` system table in
   production; writes to `logs/error.log` in debug mode.
3. **Runs migrations** — `Migrate::run()` checks `bdus_migrations` and applies
   any pending `Mxxx` class. Idempotent; negligible overhead on steady state.
4. **Routes the request** — `App::route()` instantiates the controller and
   calls the method.

### Dependency injection into controllers

`App::route()` injects all dependencies via setters before calling the method:

```php
$ctrl = new foo_ctrl($get, $post, $request);  // constructor
$ctrl->setDB($this->db);       // PDO wrapper
$ctrl->setDebug($this->debug); // bool
$ctrl->setLog($this->log);     // Monolog\Logger
$ctrl->setCfg($config);        // Config\Config (YAML)
$ctrl->setUAC($uac);           // UAC\UAC (privilege enforcement)
$ctrl->theMethod();            // the actual call
```

---

## abstract Controller — Base class

Every module PHP file defines a class `foo_ctrl extends Controller`.
The abstract base class provides:

| Property / method | Type | Description |
|---|---|---|
| `$this->get` | `array` | URL path vars + `$_GET` params |
| `$this->post` | `array` | JSON body + `$_POST` params |
| `$this->request` | `array` | merged get + post |
| `$this->db` | `DB\DBInterface` | injected PDO wrapper |
| `$this->log` | `Monolog\Logger` | injected logger |
| `$this->cfg` | `Config\Config` | injected YAML config |
| `$this->uac` | `UAC\UAC` | injected UAC instance |
| `$this->debug` | `bool` | injected debug flag |
| `returnJson(array)` | method | sets JSON header + echoes `json_encode` |
| `response(text, status, …)` | method | normalised `{status, code, …}` response |

**Security invariant enforced in `App::route()`:**

```php
// Both checks must pass or the request is rejected.
method_exists($obj, $method)              // method must exist
get_parent_class($obj) === 'Controller'   // class must extend Controller
```

This prevents calling arbitrary PHP functions or classes via the `?obj=` param.

---

## CORS

CORS is handled at the very top of `index.php`, before any PHP class is
loaded. Configure it with the environment variable:

```bash
BRADYPUS_CORS_ORIGIN=https://myapp.github.io https://localhost:5173
```

When the request `Origin` header matches an allowed origin, the appropriate
`Access-Control-Allow-*` headers are emitted. Preflight `OPTIONS` requests are
answered immediately with `204` and no further processing.

Leave `BRADYPUS_CORS_ORIGIN` unset (or empty) when frontend and backend share
the same origin — no CORS headers are added.

---

## Environment variables

| Variable | Default | Effect |
|---|---|---|
| `BRADYPUS_DEBUG` | `0` | `1` enables verbose errors, file logging |
| `BRADYPUS_CORS_ORIGIN` | `''` | Space-separated list of allowed origins |

---

## Adding a new endpoint

1. Create `modules/mything/mything.php` with `class mything_ctrl extends Controller`.
2. Add a public method (e.g. `doStuff(): void`) that ends with `$this->returnJson(…)`.
3. Register the route in `Router::dispatch()`:
   ```php
   $r->addRoute('POST', '/api/mything', ['mything_ctrl', 'doStuff']);
   ```
4. Add the privilege level in `Router::ROUTE_PRIVILEGE`:
   ```php
   'mything_ctrl::doStuff' => 'edit',
   ```
5. Document it in `openapi.yaml`.
6. Add integration tests in `tests/Integration/`.
