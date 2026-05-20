#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════
# BraDypUS API test suite
#
# Usage:
#   ./run.sh               # run all phases
#   ./run.sh --list        # dry-run: show phases and their steps
#   ./run.sh --from 04     # run from phase 04 onward (skip earlier phases)
#   ./run.sh --only 06     # run a single phase by number prefix
#
# Requirements:
#   - hurl  >= 4.0   (brew install hurl)
#   - jq    >= 1.6   (brew install jq)
#   - The PHP server must be running at BASE_URL
#   - BRADYPUS_ALLOW_NEW_APP=1 must be set in the server environment
# ════════════════════════════════════════════════════════════════════

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Parse flags ──────────────────────────────────────────────────────
LIST_ONLY=false
FROM_PHASE=""
ONLY_PHASE=""

for arg in "$@"; do
  case "$arg" in
    --list)         LIST_ONLY=true ;;
    --from=*)       FROM_PHASE="${arg#--from=}" ;;
    --only=*)       ONLY_PHASE="${arg#--only=}" ;;
  esac
done

# ── Dry-run: print all phases and their steps ────────────────────────
if [[ "$LIST_ONLY" == true ]]; then
  echo ""
  echo "BraDypUS API test suite — phases and steps"
  echo "══════════════════════════════════════════"
  for f in "${SCRIPT_DIR}"/[0-9]*.hurl; do
    phase=$(basename "$f")
    echo ""
    echo "  📄 ${phase}"
    # Print step descriptions using python for reliable UTF-8 handling
    python3 -c "
import sys, re
for line in open(sys.argv[1], encoding='utf-8'):
    line = line.rstrip()
    # Match '# 1a.' style or '# ── 3a.' style step comments
    m = re.match(r'^#\s+[\W_]*(\d+[a-z]\d*\..*)', line)
    if m:
        print('     ▸ ' + m.group(1))
" "$f" || true
  done
  echo ""
  exit 0
fi

# ── Load variables ──────────────────────────────────────────────────
ENV_FILE="${1:-${SCRIPT_DIR}/vars.env}"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: variables file not found: $ENV_FILE" >&2
  exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

# ── Check dependencies ──────────────────────────────────────────────
for cmd in hurl jq; do
  if ! command -v "$cmd" &>/dev/null; then
    echo "ERROR: '$cmd' not found. Install it first." >&2
    echo "  brew install $cmd" >&2
    exit 1
  fi
done

# ── Resolve the projects/ directory ─────────────────────────────────
PROJECTS_DIR="$(cd "${SCRIPT_DIR}/${API_DIR}" && pwd)/projects"
APP_DIR="${PROJECTS_DIR}/${APP_NAME}"

# ── Colour helpers ──────────────────────────────────────────────────
GREEN='\033[0;32m'; RED='\033[0;31m'; CYAN='\033[0;36m'
BOLD='\033[1m'; RESET='\033[0m'

pass()   { echo -e "${GREEN}✓${RESET} $*"                   >&2; }
fail()   { echo -e "${RED}✗${RESET} $*"                     >&2; }
info()   { echo -e "${CYAN}▶${RESET} $*"                    >&2; }
header() { echo -e "\n${BOLD}${CYAN}══ $* ══${RESET}"       >&2; }

# ── Base hurl flags ──────────────────────────────────────────────────
HURL_VARS=(
  --variable "base_url=${BASE_URL}"
  --variable "app_name=${APP_NAME}"
  --variable "admin_email=${ADMIN_EMAIL}"
  --variable "admin_password=${ADMIN_PASSWORD}"
)

# Run one hurl file; on failure print the report and exit.
run_phase() {
  local label="$1"; shift
  local file="$1";  shift
  local extra_vars=()
  [[ $# -gt 0 ]] && extra_vars=("$@")

  info "Phase: ${label}"
  if hurl --test "${HURL_VARS[@]}" "${extra_vars[@]+"${extra_vars[@]}"}" "${SCRIPT_DIR}/${file}"; then
    pass "${label}"
  else
    fail "${label} — aborting"
    exit 1
  fi
}

# Run one hurl file and return the JSON output (for captures).
capture_phase() {
  local label="$1"; shift
  local file="$1";  shift
  local extra_vars=()
  [[ $# -gt 0 ]] && extra_vars=("$@")

  info "Phase: ${label}"
  local out
  out=$(hurl "${HURL_VARS[@]}" "${extra_vars[@]+"${extra_vars[@]}"}" --json "${SCRIPT_DIR}/${file}" 2>/dev/null) || {
    fail "${label} — aborting"
    # Re-run in --test mode to show the human-readable error
    hurl --test "${HURL_VARS[@]}" "${extra_vars[@]+"${extra_vars[@]}"}" "${SCRIPT_DIR}/${file}" || true
    exit 1
  }
  pass "${label}"
  echo "$out"
}

# Extract a named capture from a hurl --json output blob.
capture() {
  local json="$1"
  local name="$2"
  echo "$json" | jq -r --arg n "$name" \
    '.entries[].captures[]? | select(.name == $n) | .value' | tail -1
}

# ════════════════════════════════════════════════════════════════════
# Pre-flight: clean up any leftover test app from a previous run
# ════════════════════════════════════════════════════════════════════
header "Pre-flight"
if [[ -d "$APP_DIR" ]]; then
  info "Removing leftover test app: ${APP_DIR}"
  rm -rf "$APP_DIR"
  pass "Removed"
fi

# ════════════════════════════════════════════════════════════════════
# Phase 1 — Create app
# ════════════════════════════════════════════════════════════════════
header "Phase 1 — Create app"
run_phase "Create app" "01_create_app.hurl"

# ════════════════════════════════════════════════════════════════════
# Phase 2 — Login (capture JWT)
# ════════════════════════════════════════════════════════════════════
header "Phase 2 — Login"
LOGIN_JSON=$(capture_phase "Login" "02_login.hurl")
JWT=$(capture "$LOGIN_JSON" "jwt")
if [[ -z "$JWT" ]]; then
  fail "Could not capture JWT from login response"
  exit 1
fi
pass "JWT captured (${#JWT} chars)"

# ════════════════════════════════════════════════════════════════════
# Phase 3 — Config: tables and fields
# ════════════════════════════════════════════════════════════════════
header "Phase 3 — Config"
run_phase "Config tables & fields" "03_config_tables.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 4 — Records CRUD (capture record IDs)
# ════════════════════════════════════════════════════════════════════
header "Phase 4 — Records CRUD"
RECORDS_JSON=$(capture_phase "Records CRUD" "04_records.hurl" \
  --variable "jwt=${JWT}")
US_ID_1=$(capture "$RECORDS_JSON" "us_id_1")
US_ID_2=$(capture "$RECORDS_JSON" "us_id_2")
pass "US IDs captured: us_id_1=${US_ID_1}, us_id_2=${US_ID_2}"

# ════════════════════════════════════════════════════════════════════
# Phase 5 — Stratigraphic relations
# ════════════════════════════════════════════════════════════════════
header "Phase 5 — Stratigraphic relations (RS)"
run_phase "Stratigraphic relations" "05_rs.hurl" \
  --variable "jwt=${JWT}" \
  --variable "us_id_1=${US_ID_1}" \
  --variable "us_id_2=${US_ID_2}"

# ════════════════════════════════════════════════════════════════════
# Phase 6 — Search
# ════════════════════════════════════════════════════════════════════
header "Phase 6 — Search"
run_phase "Search" "06_search.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 7 — Charts
# ════════════════════════════════════════════════════════════════════
header "Phase 7 — Charts"
run_phase "Charts" "07_charts.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 8 — Backup
# ════════════════════════════════════════════════════════════════════
header "Phase 8 — Backup"
run_phase "Backup" "08_backup.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 9 — Users and privilege enforcement
# ════════════════════════════════════════════════════════════════════
header "Phase 9 — Users & privileges"
run_phase "Users & privileges" "09_users_privileges.hurl" \
  --variable "jwt=${JWT}" \
  --variable "app_name=${APP_NAME}"

# ════════════════════════════════════════════════════════════════════
# Phase 10 — Cleanup
# ════════════════════════════════════════════════════════════════════
header "Phase 10 — Cleanup"
run_phase "Logout" "10_cleanup.hurl" \
  --variable "jwt=${JWT}"

# Remove the test app from disk
info "Removing test app directory: ${APP_DIR}"
rm -rf "$APP_DIR"
pass "App directory removed"

# ════════════════════════════════════════════════════════════════════
echo -e "\n${BOLD}${GREEN}All phases passed.${RESET}\n"
