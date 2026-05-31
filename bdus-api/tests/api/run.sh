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
SEED_DEMO=false

for arg in "$@"; do
  case "$arg" in
    --list)         LIST_ONLY=true ;;
    --from=*)       FROM_PHASE="${arg#--from=}" ;;
    --only=*)       ONLY_PHASE="${arg#--only=}" ;;
    --seed-demo)    SEED_DEMO=true ;;
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
  --variable "db_engine=${DB_ENGINE:-sqlite}"
  --variable "db_host=${DB_HOST:-}"
  --variable "db_port=${DB_PORT:-}"
  --variable "db_name=${DB_NAME:-}"
  --variable "db_username=${DB_USERNAME:-}"
  --variable "db_password=${DB_PASSWORD:-}"
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
# Phase 11 — Record version history
# ════════════════════════════════════════════════════════════════════
header "Phase 11 — Record version history"
run_phase "Version history" "11_versions.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 12 — Data import (CSV, JSON, GeoJSON)
# ════════════════════════════════════════════════════════════════════
header "Phase 12 — Data import"
run_phase "Data import" "12_import.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 13 — DB migrations list
# ════════════════════════════════════════════════════════════════════
header "Phase 13 — DB migrations"
run_phase "DB migrations" "13_migrations.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 14 — Relations panel (bdus_cfg_relations CRUD)
# ════════════════════════════════════════════════════════════════════
header "Phase 14 — Relations panel"
run_phase "Relations panel" "14_relations.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 15 — Vocabularies CRUD
# ════════════════════════════════════════════════════════════════════
header "Phase 15 — Vocabularies"
run_phase "Vocabularies" "15_vocabularies.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 16 — Welcome text + Search & Replace
# ════════════════════════════════════════════════════════════════════
header "Phase 16 — Welcome & Search-Replace"
run_phase "Welcome & Search-Replace" "16_welcome_search_replace.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 18 — JSON filter (Directus-style GET bracket notation)
# ════════════════════════════════════════════════════════════════════
header "Phase 18 — JSON filter"
run_phase "JSON filter" "18_json_filter.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 17 — Zotero library management & citation links
# ════════════════════════════════════════════════════════════════════
header "Phase 17 — Zotero"
run_phase "Zotero libraries & links" "17_zotero.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 20 — Fuzzy-date plugin (end-to-end)
# ════════════════════════════════════════════════════════════════════
header "Phase 20 — Fuzzy-date plugin"
run_phase "Fuzzy-date plugin" "20_fuzzy_date.hurl" \
  --variable "jwt=${JWT}" \
  --variable "us_id_1=${US_ID_1}"

# ════════════════════════════════════════════════════════════════════
# Phase 21 — Chrono filter (_chrono_overlap)
# ════════════════════════════════════════════════════════════════════
header "Phase 21 — Chrono filter"
run_phase "Chrono filter" "21_chrono_filter.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 22 — Schema structural changes (tmp table lifecycle)
# ════════════════════════════════════════════════════════════════════
header "Phase 22 — Schema structural changes"
run_phase "Schema structural changes" "22_schema_changes.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 23 — Widgets
# ════════════════════════════════════════════════════════════════════
header "Phase 23 — Widgets"
run_phase "Widgets" "23_widgets.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 24 — Application logs
# ════════════════════════════════════════════════════════════════════
header "Phase 24 — Logs"
run_phase "Logs" "24_logs.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 25 — API key management
# ════════════════════════════════════════════════════════════════════
header "Phase 25 — API keys"
run_phase "API keys" "25_api_keys.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 26 — Saved queries
# ════════════════════════════════════════════════════════════════════
header "Phase 26 — Saved queries"
run_phase "Saved queries" "26_saved_queries.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 27 — Templates + field-structure
# ════════════════════════════════════════════════════════════════════
header "Phase 27 — Templates"
run_phase "Templates" "27_templates.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 28 — Geoface feature CRUD
# ════════════════════════════════════════════════════════════════════
header "Phase 28 — Geoface feature operations"
run_phase "Geoface feature ops" "28_geoface_ops.hurl" \
  --variable "jwt=${JWT}" \
  --variable "us_id_1=${US_ID_1}"

# ════════════════════════════════════════════════════════════════════
# Phase 10 — Cleanup
# ════════════════════════════════════════════════════════════════════
header "Phase 10 — Cleanup"
run_phase "Logout" "10_cleanup.hurl" \
  --variable "jwt=${JWT}"

# ════════════════════════════════════════════════════════════════════
# Phase 19 — Demo seed (optional — only with --seed-demo)
# ════════════════════════════════════════════════════════════════════
if [[ "$SEED_DEMO" == true ]]; then
  header "Phase 19 — Demo seed"
  SEED_JSON=$(capture_phase "Demo seed" "19_seed_demo.hurl" \
    --variable "jwt=${JWT}")
  pass "Demo seed: app populated with realistic data"
  echo -e "${CYAN}  App available at: ${BASE_URL} (app: ${APP_NAME})${RESET}"
  echo -e "${CYAN}  Credentials: ${ADMIN_EMAIL} / ${ADMIN_PASSWORD}${RESET}"
fi

# NOTE: app directory is intentionally NOT deleted here.
# The pre-flight at the start of the next run cleans it up.
# This allows the populated app to be used for screenshots and demos.

# ════════════════════════════════════════════════════════════════════
echo -e "\n${BOLD}${GREEN}All phases passed.${RESET}\n"
