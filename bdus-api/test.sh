#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════
# BraDypUS — full local test runner
#
# Runs in one shot:
#   1. Ensures the Docker daemon is running (starts Docker.app on macOS)
#   2. Starts an isolated test container (project "bdus-test", port 8081)
#   3. Waits until the server is healthy
#   4. Runs PHPUnit unit + integration tests inside the container
#   5. Runs the full Hurl E2E API suite (tests/api/run.sh)
#   6. Tears down the test container (unless --keep is passed)
#
# Usage:
#   ./test.sh              # run everything, clean up on exit
#   ./test.sh --keep       # leave the container running after the tests
#   ./test.sh --skip-unit  # skip PHPUnit, run only Hurl
#   ./test.sh --skip-e2e   # skip Hurl, run only PHPUnit
#
# Requirements:
#   - docker        (Docker Desktop or Docker Engine)
#   - hurl >= 4.0   (brew install hurl)
#   - jq  >= 1.6    (brew install jq)
# ════════════════════════════════════════════════════════════════════

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Colour helpers ──────────────────────────────────────────────────
GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

pass()   { echo -e "${GREEN}✓${RESET} $*"; }
fail()   { echo -e "${RED}✗${RESET} $*"; }
info()   { echo -e "${CYAN}▶${RESET} $*"; }
warn()   { echo -e "${YELLOW}⚠${RESET} $*"; }
header() { echo -e "\n${BOLD}${CYAN}══ $* ══${RESET}"; }

# ── Parse flags ──────────────────────────────────────────────────────
KEEP=false
SKIP_UNIT=false
SKIP_E2E=false

for arg in "$@"; do
  case "$arg" in
    --keep)       KEEP=true ;;
    --skip-unit)  SKIP_UNIT=true ;;
    --skip-e2e)   SKIP_E2E=true ;;
    *)
      echo "Unknown option: $arg" >&2
      echo "Usage: $0 [--keep] [--skip-unit] [--skip-e2e]" >&2
      exit 1
      ;;
  esac
done

# ── Constants ────────────────────────────────────────────────────────
COMPOSE_PROJECT="bdus-test"
COMPOSE_FILES=(-f docker-compose.yml -f docker-compose.test.yml)
TEST_PORT=8081
HEALTH_URL="http://localhost:${TEST_PORT}/"
HEALTH_TIMEOUT=60   # seconds to wait for the server to respond
VARS_FILE="${SCRIPT_DIR}/tests/api/vars.test.env"

# ── Check required tools ─────────────────────────────────────────────
header "Checking dependencies"
MISSING=()
for cmd in docker hurl jq; do
  if command -v "$cmd" &>/dev/null; then
    pass "$cmd found"
  else
    fail "$cmd not found"
    MISSING+=("$cmd")
  fi
done
if [[ ${#MISSING[@]} -gt 0 ]]; then
  echo ""
  fail "Missing tools: ${MISSING[*]}"
  echo "  brew install ${MISSING[*]}"
  exit 1
fi

# ── Step 1 — Ensure Docker daemon is running ─────────────────────────
header "Step 1 — Docker daemon"
if docker info &>/dev/null; then
  pass "Docker daemon is running"
else
  warn "Docker daemon is not running"
  if [[ "$(uname)" == "Darwin" ]]; then
    info "Starting Docker.app…"
    open -a Docker
    info "Waiting for Docker daemon (up to 60 s)…"
    WAIT=0
    until docker info &>/dev/null; do
      sleep 2; WAIT=$((WAIT + 2))
      if [[ $WAIT -ge 60 ]]; then
        fail "Docker daemon did not start in time"
        exit 1
      fi
    done
    pass "Docker daemon started"
  else
    fail "Docker daemon is not running. Please start it manually."
    exit 1
  fi
fi

# ── Step 2 — Start isolated test container ───────────────────────────
header "Step 2 — Test container (project: ${COMPOSE_PROJECT}, port: ${TEST_PORT})"

# Bring down any leftover test project first (idempotent)
if docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" ps -q 2>/dev/null | grep -q .; then
  warn "Leftover test container found — stopping it first"
  docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" down --volumes --remove-orphans 2>/dev/null || true
fi

info "Building and starting test container…"
docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" up -d --build

# ── Cleanup trap ─────────────────────────────────────────────────────
cleanup() {
  local exit_code=$?
  if [[ "$KEEP" == true ]]; then
    warn "Container left running (--keep). Stop it with:"
    echo "  docker compose -p ${COMPOSE_PROJECT} ${COMPOSE_FILES[*]} down"
  else
    header "Cleanup"
    info "Stopping test container…"
    docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" down --volumes --remove-orphans 2>/dev/null || true
    pass "Test container stopped"
  fi
  exit "$exit_code"
}
trap cleanup EXIT

# ── Step 3 — Wait for server readiness ──────────────────────────────
header "Step 3 — Server readiness"
info "Waiting for ${HEALTH_URL} (timeout: ${HEALTH_TIMEOUT} s)…"
ELAPSED=0
until curl -s --max-time 2 -o /dev/null "$HEALTH_URL"; do
  sleep 2; ELAPSED=$((ELAPSED + 2))
  if [[ $ELAPSED -ge $HEALTH_TIMEOUT ]]; then
    fail "Server did not become ready within ${HEALTH_TIMEOUT} s"
    echo ""
    echo "Container logs:"
    docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" logs --tail=50
    exit 1
  fi
  echo -n "."
done
echo ""
pass "Server is ready at ${HEALTH_URL}"

# ── Step 4 — PHPUnit ─────────────────────────────────────────────────
UNIT_EXIT=0
if [[ "$SKIP_UNIT" == true ]]; then
  warn "PHPUnit skipped (--skip-unit)"
else
  header "Step 4 — PHPUnit (unit + integration)"
  CONTAINER_ID=$(docker compose -p "$COMPOSE_PROJECT" "${COMPOSE_FILES[@]}" ps -q app)
  if docker exec "$CONTAINER_ID" \
      php vendor/bin/phpunit --colors=always; then
    pass "PHPUnit: all tests passed"
  else
    UNIT_EXIT=$?
    fail "PHPUnit: some tests failed (exit ${UNIT_EXIT})"
  fi
fi

# ── Step 5 — Hurl E2E ────────────────────────────────────────────────
E2E_EXIT=0
if [[ "$SKIP_E2E" == true ]]; then
  warn "Hurl E2E skipped (--skip-e2e)"
else
  header "Step 5 — Hurl E2E API suite"
  if "${SCRIPT_DIR}/tests/api/run.sh" "$VARS_FILE"; then
    pass "Hurl E2E: all phases passed"
  else
    E2E_EXIT=$?
    fail "Hurl E2E: some phases failed (exit ${E2E_EXIT})"
  fi
fi

# ── Summary ──────────────────────────────────────────────────────────
header "Summary"
if [[ "$SKIP_UNIT" == true ]]; then
  warn "PHPUnit: skipped"
elif [[ $UNIT_EXIT -eq 0 ]]; then
  pass "PHPUnit: passed"
else
  fail "PHPUnit: FAILED"
fi

if [[ "$SKIP_E2E" == true ]]; then
  warn "Hurl E2E: skipped"
elif [[ $E2E_EXIT -eq 0 ]]; then
  pass "Hurl E2E: passed"
else
  fail "Hurl E2E: FAILED"
fi

# Exit non-zero if either suite failed
OVERALL=$((UNIT_EXIT + E2E_EXIT))
if [[ $OVERALL -eq 0 ]]; then
  echo -e "\n${BOLD}${GREEN}All tests passed.${RESET}\n"
else
  echo -e "\n${BOLD}${RED}One or more test suites failed.${RESET}\n"
fi
exit $OVERALL
