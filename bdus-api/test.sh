#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════
# BraDypUS — test suite entry point
#
# Single entry point for all test workflows: Docker orchestration,
# PHPUnit, and Hurl E2E API phases.  Configuration lives in
# tests/api/vars.env (copy to vars.local.env to override locally).
#
# USAGE
#   ./test.sh [OPTIONS]
#
# MODE FLAGS  (combinable; if none are given → interactive menu)
#   --unit          Run PHPUnit unit + integration tests
#   --setup         Hurl phases 01-03: create app + configure schema
#   --tests         Hurl phases 04-31: full CRUD & feature suite
#   --seed          Hurl phase 19: populate app with demo data
#   --seed-more     Hurl phase 19b: extended seed (implies --seed)
#
# PHASE CONTROL  (apply to --tests)
#   --list          List all phases with their steps; do not run
#   --from=N        Run from phase N onward  (e.g. --from=06)
#   --only=N        Run only phases with prefix N (e.g. --only=04)
#                   Note: phases 04b/04c/04d/04e match --only=04
#
# INFRASTRUCTURE
#   --reset         Delete existing app without asking
#   --db=ENGINE     DB engine: sqlite (default), pgsql, mysql
#   --all-engines   Run the full suite on sqlite, pgsql, mysql
#   --keep          Leave the Docker container running after the run
#   --no-docker     Skip Docker; use local server at BASE_URL (:8080)
#
# COMMON WORKFLOWS
#   ./test.sh --setup --tests            # create app + full test suite
#   ./test.sh --setup --tests --seed     # tests then demo seed
#   ./test.sh --setup --seed             # create app + seed only
#   ./test.sh --reset --setup --tests    # clean run (auto-delete app)
#   ./test.sh --seed-more --keep         # seed + leave container up for screenshots
#   ./test.sh --tests --only=04          # run all phase-04 sub-phases
#   ./test.sh --unit                     # PHPUnit only
#   ./test.sh --list                     # list phases, exit
#   ./test.sh --all-engines --setup --tests  # CI: test on all DB engines
#
# REQUIREMENTS
#   docker   (Docker Desktop or Docker Engine) — unless --no-docker
#   hurl  >= 4.0   (brew install hurl)
#   jq    >= 1.6   (brew install jq)
#   BRADYPUS_ALLOW_NEW_APP=1 is set automatically in the test container
# ════════════════════════════════════════════════════════════════════

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Colour helpers ───────────────────────────────────────────────
GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

pass()   { echo -e "${GREEN}✓${RESET} $*"; }
fail()   { echo -e "${RED}✗${RESET} $*"; }
info()   { echo -e "${CYAN}▶${RESET} $*"; }
warn()   { echo -e "${YELLOW}⚠${RESET} $*"; }
header() { echo -e "\n${BOLD}${CYAN}══ $* ══${RESET}"; }

# ── Parse flags ──────────────────────────────────────────────────
FORCE_RESET=false
RUN_UNIT=false
RUN_SETUP=false
RUN_TESTS=false
RUN_SEED=false
SEED_MORE=false
FROM_PHASE=""
ONLY_PHASE=""
DB_ENGINE=sqlite
ALL_ENGINES=false
KEEP=false
NO_DOCKER=false
LIST_ONLY=false

for arg in "$@"; do
  case "$arg" in
    --reset)        FORCE_RESET=true ;;
    --unit)         RUN_UNIT=true ;;
    --setup)        RUN_SETUP=true ;;
    --tests)        RUN_TESTS=true ;;
    --seed)         RUN_SEED=true ;;
    --seed-more)    RUN_SEED=true; SEED_MORE=true ;;
    --list)         LIST_ONLY=true ;;
    --from=*)       FROM_PHASE="${arg#--from=}" ;;
    --only=*)       ONLY_PHASE="${arg#--only=}" ;;
    --db=*)         DB_ENGINE="${arg#--db=}" ;;
    --all-engines)  ALL_ENGINES=true ;;
    --keep)         KEEP=true ;;
    --no-docker)    NO_DOCKER=true ;;
    -h|--help)
      sed -n '/^# USAGE/,/^# REQUIREMENTS/p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "Unknown option: $arg" >&2
      echo "Run ./test.sh --help for usage." >&2
      exit 1
      ;;
  esac
done

case "$DB_ENGINE" in
  sqlite|pgsql|mysql) ;;
  *)
    echo "Unknown DB engine: ${DB_ENGINE} (supported: sqlite, pgsql, mysql)" >&2
    exit 1
    ;;
esac

# ── --all-engines: re-invoke for each DB engine ──────────────────
if [[ "$ALL_ENGINES" == true ]]; then
  PASS_FLAGS=()
  for arg in "$@"; do
    [[ "$arg" == "--all-engines" ]] && continue
    [[ "$arg" == --db=* ]]         && continue
    PASS_FLAGS+=("$arg")
  done
  MULTI_EXIT=0
  FAILED_ENGINES=()
  for engine in sqlite pgsql mysql; do
    echo -e "\n${BOLD}${CYAN}════════════════════════════════════${RESET}"
    echo -e "${BOLD}${CYAN}  Engine: ${engine}${RESET}"
    echo -e "${BOLD}${CYAN}════════════════════════════════════${RESET}\n"
    if bash "$0" --db="$engine" "${PASS_FLAGS[@]}"; then
      pass "Engine ${engine}: PASSED"
    else
      fail "Engine ${engine}: FAILED"
      FAILED_ENGINES+=("$engine")
      MULTI_EXIT=1
    fi
  done
  echo ""
  if [[ $MULTI_EXIT -eq 0 ]]; then
    echo -e "${BOLD}${GREEN}All engines passed: sqlite ✓  pgsql ✓  mysql ✓${RESET}\n"
  else
    echo -e "${BOLD}${RED}Failed engines: ${FAILED_ENGINES[*]}${RESET}\n"
  fi
  exit $MULTI_EXIT
fi

# ── Interactive menu (no mode flags given) ───────────────────────
ANY_MODE=false
for v in "$RUN_UNIT" "$RUN_SETUP" "$RUN_TESTS" "$RUN_SEED" "$LIST_ONLY"; do
  [[ "$v" == true ]] && ANY_MODE=true && break
done

if [[ "$ANY_MODE" == false ]]; then
  header "BraDypUS Test Suite"
  echo ""

  read -r -p "  Run PHPUnit unit + integration tests? [Y/n] " ans </dev/tty
  [[ "$ans" =~ ^[nN]$ ]] || RUN_UNIT=true

  read -r -p "  Run setup (phases 01-03: create app + schema)? [Y/n] " ans </dev/tty
  [[ "$ans" =~ ^[nN]$ ]] || RUN_SETUP=true

  read -r -p "  Run CRUD tests (phases 04-31)? [Y/n] " ans </dev/tty
  [[ "$ans" =~ ^[nN]$ ]] || RUN_TESTS=true

  read -r -p "  Run demo seed (phase 19)? [y/N] " ans </dev/tty
  [[ "$ans" =~ ^[yY]$ ]] && RUN_SEED=true

  if [[ "$RUN_SEED" == true ]]; then
    read -r -p "  Extended seed (phase 19b)? [y/N] " ans </dev/tty
    [[ "$ans" =~ ^[yY]$ ]] && SEED_MORE=true
  fi
  echo ""
fi

# ── --list: print phases and exit ────────────────────────────────
if [[ "$LIST_ONLY" == true ]]; then
  source "${SCRIPT_DIR}/tests/api/_phases.sh"
  list_phases
  exit 0
fi

# ── Check required tools ─────────────────────────────────────────
header "Checking dependencies"
MISSING=()
NEED_DOCKER=$([[ "$NO_DOCKER" == false ]] && echo true || echo false)

for cmd in hurl jq; do
  if command -v "$cmd" &>/dev/null; then
    pass "$cmd found"
  else
    fail "$cmd not found (brew install $cmd)"
    MISSING+=("$cmd")
  fi
done

if [[ "$NEED_DOCKER" == true ]]; then
  if command -v docker &>/dev/null; then
    pass "docker found"
  else
    fail "docker not found"
    MISSING+=("docker")
  fi
fi

if [[ ${#MISSING[@]} -gt 0 ]]; then
  fail "Missing tools: ${MISSING[*]}"
  exit 1
fi

# ── Docker setup ─────────────────────────────────────────────────
DOCKER_BASE_URL="http://localhost:8081"
COMPOSE_PROJECT="bdus-test"
COMPOSE_FILES=(-f "${SCRIPT_DIR}/docker-compose.yml" -f "${SCRIPT_DIR}/docker-compose.test.yml")

if [[ "$NO_DOCKER" == false ]]; then
  [[ "$DB_ENGINE" == "pgsql"  ]] && COMPOSE_FILES+=(-f "${SCRIPT_DIR}/docker-compose.test.pg.yml")
  [[ "$DB_ENGINE" == "mysql"  ]] && COMPOSE_FILES+=(-f "${SCRIPT_DIR}/docker-compose.test.mysql.yml")

  # Ensure Docker daemon is running
  header "Docker daemon"
  if ! docker info &>/dev/null; then
    warn "Docker daemon not running"
    if [[ "$(uname)" == "Darwin" ]]; then
      info "Starting Docker.app…"
      open -a Docker
      info "Waiting for daemon (up to 60 s)…"
      ELAPSED=0
      until docker info &>/dev/null; do
        sleep 2; ELAPSED=$((ELAPSED + 2))
        [[ $ELAPSED -ge 60 ]] && { fail "Docker did not start in time"; exit 1; }
        echo -n "."
      done
      echo ""
      pass "Docker started"
    else
      fail "Docker daemon is not running. Start it manually."
      exit 1
    fi
  else
    pass "Docker daemon is running"
  fi

  # Stop any leftover test container
  header "Test container (project: ${COMPOSE_PROJECT}, port: 8081, db: ${DB_ENGINE})"
  if docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" ps -q 2>/dev/null | grep -q .; then
    warn "Leftover container found — stopping"
    docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" down --volumes --remove-orphans 2>/dev/null || true
  fi

  info "Building and starting test container…"
  docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" up -d --build

  # Cleanup trap
  cleanup() {
    local code=$?
    if [[ "$KEEP" == true ]]; then
      warn "Container left running (--keep). Stop with:"
      echo "  docker compose -p ${COMPOSE_PROJECT} ${COMPOSE_FILES[*]} down"
    else
      header "Cleanup"
      info "Stopping test container…"
      docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" down --volumes --remove-orphans 2>/dev/null || true
      pass "Container stopped"
    fi
    exit "$code"
  }
  trap cleanup EXIT

  # Health check
  header "Server readiness"
  info "Waiting for ${DOCKER_BASE_URL} (timeout: 60 s)…"
  ELAPSED=0
  until curl -s --max-time 2 -o /dev/null "${DOCKER_BASE_URL}"; do
    sleep 2; ELAPSED=$((ELAPSED + 2))
    if [[ $ELAPSED -ge 60 ]]; then
      fail "Server did not become ready within 60 s"
      docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" logs --tail=50
      exit 1
    fi
    echo -n "."
  done
  echo ""
  pass "Server ready at ${DOCKER_BASE_URL}"
fi

# ── Load configuration ───────────────────────────────────────────
VARS_FILE="${SCRIPT_DIR}/tests/api/vars.local.env"
[[ -f "$VARS_FILE" ]] || VARS_FILE="${SCRIPT_DIR}/tests/api/vars.env"
# shellcheck disable=SC1090
source "$VARS_FILE"

# Override BASE_URL and DB credentials when running in Docker
if [[ "$NO_DOCKER" == false ]]; then
  BASE_URL="$DOCKER_BASE_URL"
fi

if [[ "$DB_ENGINE" == "pgsql" ]]; then
  DB_HOST=postgres; DB_PORT=5432; DB_NAME=bdus_test
  DB_USERNAME=bdus; DB_PASSWORD=bdus_test_pw
elif [[ "$DB_ENGINE" == "mysql" ]]; then
  DB_HOST=mariadb; DB_PORT=3306; DB_NAME=bdus_test
  DB_USERNAME=bdus; DB_PASSWORD=bdus_test_pw
fi
# DB_ENGINE from flag always wins over vars.env
export DB_ENGINE

# ── Source phase library ─────────────────────────────────────────
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/tests/api/_phases.sh"
setup_hurl_vars

# ── App directory (for pre_flight in --no-docker mode) ───────────
PROJECTS_DIR="${SCRIPT_DIR}/projects"
APP_DIR="${PROJECTS_DIR}/${APP_NAME}"

# ── Pre-flight ───────────────────────────────────────────────────
# In Docker mode the test container uses an isolated named volume
# (bdus_test_projects) that is wiped by `down --volumes` on each run.
# Pre-flight is only needed for --no-docker, where the host filesystem
# IS the app filesystem and leftover runs could interfere.
if [[ "$NO_DOCKER" == true ]] && \
   [[ "$RUN_SETUP" == true || "$RUN_TESTS" == true || "$RUN_SEED" == true ]]; then
  header "Pre-flight"
  pre_flight
fi

# ── PHPUnit ──────────────────────────────────────────────────────
UNIT_EXIT=0
if [[ "$RUN_UNIT" == true ]]; then
  header "PHPUnit"
  if [[ "$NO_DOCKER" == false ]]; then
    CONTAINER_ID=$(docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" ps -q app)
    docker exec "$CONTAINER_ID" php vendor/bin/phpunit --colors=always \
      || UNIT_EXIT=$?
  else
    (cd "${SCRIPT_DIR}" && php vendor/bin/phpunit --colors=always) \
      || UNIT_EXIT=$?
  fi
  [[ $UNIT_EXIT -eq 0 ]] && pass "PHPUnit: all tests passed" \
                          || fail "PHPUnit: some tests failed"
fi

# ── Hurl phases ──────────────────────────────────────────────────
JWT=""
US_ID_1=""
US_ID_2=""

if [[ "$RUN_SETUP" == true ]]; then
  header "═══ Setup ═══"
  run_setup
fi

if [[ "$RUN_TESTS" == true ]]; then
  # Allow resuming against an already-configured app (--tests without --setup)
  if [[ -z "$JWT" ]]; then
    if [[ -d "$APP_DIR" ]]; then
      header "Phase 2 — Login (resuming existing app)"
      do_login
    else
      fail "--tests requires --setup or an existing app at ${APP_DIR}"
      exit 1
    fi
  fi
  header "═══ Tests ═══"
  run_tests
fi

if [[ "$RUN_SEED" == true ]]; then
  header "═══ Seed ═══"
  run_seed
  echo -e "\n${CYAN}  App:   ${APP_NAME}${RESET}"
  echo -e "${CYAN}  URL:   ${BASE_URL}${RESET}"
  echo -e "${CYAN}  Login: ${ADMIN_EMAIL} / ${ADMIN_PASSWORD}${RESET}\n"
fi

# ── Summary ──────────────────────────────────────────────────────
header "Summary"

if [[ "$RUN_UNIT" == true ]]; then
  [[ $UNIT_EXIT -eq 0 ]] && pass "PHPUnit:  passed" || fail "PHPUnit:  FAILED"
fi

if [[ "$RUN_SETUP" == true || "$RUN_TESTS" == true || "$RUN_SEED" == true ]]; then
  pass "Hurl E2E: passed"
fi

OVERALL=$((UNIT_EXIT))
if [[ $OVERALL -eq 0 ]]; then
  echo -e "\n${BOLD}${GREEN}All tests passed.${RESET}\n"
else
  echo -e "\n${BOLD}${RED}One or more suites failed.${RESET}\n"
fi
exit $OVERALL
