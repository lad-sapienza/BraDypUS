#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════
# Demo-seed runner — creates a clean demo app and populates it.
#
# Usage:
#   ./run_demo_seed.sh [vars_file] [--seed-more]
#
# Default vars file: vars.demo.env  (APP_NAME=bdus_demo, port 8080)
# Override example:  ./run_demo_seed.sh vars.test.env  (port 8081)
#
# Phases run (in order):
#   01 — create demo app
#   02 — login, capture JWT
#   03 — configure schema (tables, fields, vocabularies config)
#   19 — realistic demo data (3 siti, saggi, 30 US, RS, reperti...)
#  [19b]— extended seed (15 siti, 375 reperti, geodata) if --seed-more
#   10 — logout
#
# The result is a clean populated demo app (bdus_demo) with no test junk.
# ════════════════════════════════════════════════════════════════════

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

VARS_FILE="${1:-${SCRIPT_DIR}/vars.demo.env}"
SEED_MORE=false
[[ "${2:-}" == "--seed-more" ]] && SEED_MORE=true

source "$VARS_FILE"

GREEN='\033[0;32m'; RED='\033[0;31m'; CYAN='\033[0;36m'
BOLD='\033[1m'; RESET='\033[0m'

pass()   { echo -e "${GREEN}✓${RESET} $*" >&2; }
fail()   { echo -e "${RED}✗${RESET} $*"   >&2; }
info()   { echo -e "${CYAN}▶${RESET} $*"  >&2; }
header() { echo -e "\n${BOLD}${CYAN}══ $* ══${RESET}" >&2; }

HURL_VARS=(
  --variable "base_url=${BASE_URL}"
  --variable "app_name=${APP_NAME}"
  --variable "admin_email=${ADMIN_EMAIL}"
  --variable "admin_password=${ADMIN_PASSWORD}"
  --variable "db_engine=${DB_ENGINE:-sqlite}"
  --variable "db_host=${DB_HOST:-}"
  --variable "db_port=${DB_PORT:-}"
  --variable "db_name=${DB_NAME:-}"
  --variable "db_username=${DB_USERNAME:-}"
  --variable "db_password=${DB_PASSWORD:-}"
)

run_phase() {
  local label="$1"; local file="$2"; shift 2
  local extra_vars=(); [[ $# -gt 0 ]] && extra_vars=("$@")
  info "Phase: ${label}"
  if hurl --test "${HURL_VARS[@]}" "${extra_vars[@]+"${extra_vars[@]}"}" "${SCRIPT_DIR}/${file}"; then
    pass "${label}"
  else
    fail "${label} — aborting"; exit 1
  fi
}

capture_phase() {
  local label="$1"; local file="$2"; shift 2
  local extra_vars=(); [[ $# -gt 0 ]] && extra_vars=("$@")
  info "Phase: ${label}"
  local out
  out=$(hurl "${HURL_VARS[@]}" "${extra_vars[@]+"${extra_vars[@]}"}" --json "${SCRIPT_DIR}/${file}" 2>/dev/null) || {
    fail "${label} — aborting"
    hurl --test "${HURL_VARS[@]}" "${extra_vars[@]+"${extra_vars[@]}"}" "${SCRIPT_DIR}/${file}" || true
    exit 1
  }
  pass "${label}"; echo "$out"
}

capture() { echo "$1" | jq -r --arg n "$2" '.entries[].captures[]? | select(.name==$n) | .value' | tail -1; }

# ── Phase 1 — Create demo app ─────────────────────────────────────────
header "Phase 1 — Create demo app"
run_phase "Create demo app" "01_create_app.hurl"

# ── Phase 2 — Login ───────────────────────────────────────────────────
header "Phase 2 — Login"
LOGIN_JSON=$(capture_phase "Login" "02_login.hurl")
JWT=$(capture "$LOGIN_JSON" "jwt")
[[ -z "$JWT" ]] && { fail "Could not capture JWT"; exit 1; }
pass "JWT captured (${#JWT} chars)"

# ── Phase 3 — Schema config ───────────────────────────────────────────
header "Phase 3 — Schema config"
run_phase "Schema config" "03_config_tables.hurl" --variable "jwt=${JWT}"

# ── Phase 19 — Demo seed ──────────────────────────────────────────────
header "Phase 19 — Demo seed"
capture_phase "Demo seed" "19_seed_demo.hurl" --variable "jwt=${JWT}"

# ── Phase 19b — Extended seed (optional) ─────────────────────────────
if [[ "$SEED_MORE" == true ]]; then
  header "Phase 19b — Extended seed"
  run_phase "Extended seed" "19b_seed_more.hurl" --variable "jwt=${JWT}"
fi

# ── Phase 10 — Logout ────────────────────────────────────────────────
header "Phase 10 — Logout"
run_phase "Logout" "10_cleanup.hurl" --variable "jwt=${JWT}"

echo -e "\n${BOLD}${GREEN}Demo seed complete.${RESET}"
echo -e "${CYAN}  App:         ${APP_NAME}${RESET}"
echo -e "${CYAN}  URL:         ${BASE_URL}${RESET}"
echo -e "${CYAN}  Credentials: ${ADMIN_EMAIL} / ${ADMIN_PASSWORD}${RESET}\n"
