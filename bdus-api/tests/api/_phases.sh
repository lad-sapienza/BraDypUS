#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════
# _phases.sh — Hurl phase library for BraDypUS test suite
#
# Sourced by test.sh — not callable directly.
#
# Variables that must be set before sourcing:
#   BASE_URL, APP_NAME, ADMIN_EMAIL, ADMIN_PASSWORD
#   DB_ENGINE, DB_HOST, DB_PORT, DB_NAME, DB_USERNAME, DB_PASSWORD
#   APP_DIR        — absolute path to the app runtime directory
#   FORCE_RESET    — true → auto-delete existing app without asking
#   FROM_PHASE     — run from this phase prefix onward (e.g. "06")
#   ONLY_PHASE     — run only phases with this prefix (e.g. "04")
#
# Colour variables (GREEN, RED, YELLOW, CYAN, BOLD, RESET) must be
# defined in the sourcing script.
# ════════════════════════════════════════════════════════════════════

PHASES_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Colour helpers (all to stderr so they never pollute $() captures) ──
pass()   { echo -e "${GREEN}✓${RESET} $*" >&2; }
fail()   { echo -e "${RED}✗${RESET} $*"   >&2; }
info()   { echo -e "${CYAN}▶${RESET} $*"  >&2; }
warn()   { echo -e "${YELLOW}⚠${RESET} $*" >&2; }
header() { echo -e "\n${BOLD}${CYAN}══ $* ══${RESET}" >&2; }

# ── Hurl runner helpers ───────────────────────────────────────────

run_phase() {
  local label="$1"; local file="$2"; shift 2
  local extra_vars=(); [[ $# -gt 0 ]] && extra_vars=("$@")
  info "Phase: ${label}"
  if hurl --test "${HURL_VARS[@]}" "${extra_vars[@]+"${extra_vars[@]}"}" "${PHASES_DIR}/${file}"; then
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
  out=$(hurl "${HURL_VARS[@]}" "${extra_vars[@]+"${extra_vars[@]}"}" --json "${PHASES_DIR}/${file}" 2>/dev/null) || {
    fail "${label} — aborting"
    hurl --test "${HURL_VARS[@]}" "${extra_vars[@]+"${extra_vars[@]}"}" "${PHASES_DIR}/${file}" || true
    exit 1
  }
  pass "${label}"; echo "$out"
}

capture() {
  echo "$1" | jq -r --arg n "$2" \
    '.entries[].captures[]? | select(.name == $n) | .value' | tail -1
}

# ── HURL_VARS builder ────────────────────────────────────────────

setup_hurl_vars() {
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
}

# ── Phase filter ─────────────────────────────────────────────────
# Returns 0 (run) or 1 (skip) based on FROM_PHASE / ONLY_PHASE.
# Phase IDs are 2-digit prefixes with optional letter suffix:
#   04, 04b, 04c … 10, 11 … 19, 19b … 31
# Lexicographic comparison is correct for this numbering scheme.

should_run() {
  local phase="$1"
  if [[ -n "${ONLY_PHASE:-}" ]]; then
    [[ "$phase" == "${ONLY_PHASE}"* ]] && return 0 || return 1
  fi
  if [[ -n "${FROM_PHASE:-}" ]]; then
    [[ ! "$phase" < "${FROM_PHASE}" ]] && return 0 || return 1
  fi
  return 0
}

# ── Phase listing (--list dry-run) ───────────────────────────────

list_phases() {
  echo ""
  echo "BraDypUS test suite — available phases"
  echo "════════════════════════════════════════"
  for f in "${PHASES_DIR}"/[0-9]*.hurl; do
    echo ""
    echo "  📄 $(basename "$f")"
    python3 -c "
import sys, re
for line in open(sys.argv[1], encoding='utf-8'):
    line = line.rstrip()
    m = re.match(r'^#\s+[\W_]*(\d+[a-z]?\d*\..+)', line)
    if m:
        print('     ▸ ' + m.group(1))
" "$f" || true
  done
  echo ""
}

# ── Pre-flight: check / remove existing app ──────────────────────

pre_flight() {
  if [[ -d "$APP_DIR" ]]; then
    if [[ "${FORCE_RESET:-false}" == true ]]; then
      info "Removing existing app: ${APP_DIR}"
      rm -rf "$APP_DIR"
      pass "Removed"
    else
      echo "" >&2
      warn "App '${APP_NAME}' already exists at ${APP_DIR}"
      read -r -p "  Delete it and start fresh? [y/N] " answer </dev/tty
      if [[ "$answer" =~ ^[yY]$ ]]; then
        rm -rf "$APP_DIR"
        pass "Removed"
      else
        fail "Aborting — app already exists. Use --reset to auto-delete."
        exit 1
      fi
    fi
  fi
}

# ── Login: phase 02, captures JWT into global $JWT ───────────────

do_login() {
  local out
  out=$(capture_phase "Login" "02_login.hurl") || exit 1
  JWT=$(capture "$out" "jwt")
  [[ -z "$JWT" ]] && { fail "JWT not captured"; exit 1; }
  pass "JWT captured (${#JWT} chars)"
}

# ── run_setup: phases 01, 02 (login), 03 ─────────────────────────

run_setup() {
  header "Phase 1 — Create app"
  run_phase "Create app" "01_create_app.hurl"

  header "Phase 2 — Login"
  do_login

  header "Phase 3 — Config"
  run_phase "Config tables & fields" "03_config_tables.hurl" \
    --variable "jwt=${JWT}"
}

# ── run_tests: phases 04–31 + cleanup (10) ───────────────────────
# Expects $JWT set (by run_setup or a standalone do_login call).
# Respects FROM_PHASE and ONLY_PHASE for selective runs.
# Phase 10 (cleanup + logout) always executes at the end.

run_tests() {
  # ── 04 — Records CRUD (captures US IDs for subsequent phases) ──
  if should_run "04"; then
    header "Phase 4 — Records CRUD"
    local rec_json
    rec_json=$(capture_phase "Records CRUD" "04_records.hurl" \
      --variable "jwt=${JWT}")
    US_ID_1=$(capture "$rec_json" "us_id_1")
    US_ID_2=$(capture "$rec_json" "us_id_2")
    pass "US IDs captured: us_id_1=${US_ID_1}, us_id_2=${US_ID_2}"
  fi

  if should_run "04b"; then
    header "Phase 4b — Plugin CRUD"
    run_phase "Plugin CRUD" "04b_plugin_crud.hurl" \
      --variable "jwt=${JWT}" \
      --variable "us_id_1=${US_ID_1:-}" \
      --variable "us_id_2=${US_ID_2:-}"
  fi

  if should_run "04c"; then
    header "Phase 4c — File upload"
    run_phase "File upload" "04c_file_upload.hurl" \
      --variable "jwt=${JWT}" \
      --variable "us_id_1=${US_ID_1:-}"
  fi

  if should_run "04d"; then
    header "Phase 4d — Export"
    run_phase "Export" "04d_export.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "04e"; then
    header "Phase 4e — Manual links"
    run_phase "Manual links" "04e_manual_links.hurl" \
      --variable "jwt=${JWT}" \
      --variable "us_id_1=${US_ID_1:-}" \
      --variable "us_id_2=${US_ID_2:-}"
  fi

  if should_run "05"; then
    header "Phase 5 — Stratigraphic relations"
    run_phase "Stratigraphic relations" "05_rs.hurl" \
      --variable "jwt=${JWT}" \
      --variable "us_id_1=${US_ID_1:-}" \
      --variable "us_id_2=${US_ID_2:-}"
  fi

  if should_run "06"; then
    header "Phase 6 — Search"
    run_phase "Search" "06_search.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "07"; then
    header "Phase 7 — Charts"
    run_phase "Charts" "07_charts.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "08"; then
    header "Phase 8 — Backup"
    run_phase "Backup" "08_backup.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "09"; then
    header "Phase 9 — Users & privileges"
    run_phase "Users & privileges" "09_users_privileges.hurl" \
      --variable "jwt=${JWT}" \
      --variable "app_name=${APP_NAME}"
  fi

  if should_run "11"; then
    header "Phase 11 — Version history"
    run_phase "Version history" "11_versions.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "12"; then
    header "Phase 12 — Data import"
    run_phase "Data import" "12_import.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "13"; then
    header "Phase 13 — DB migrations"
    run_phase "DB migrations" "13_migrations.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "14"; then
    header "Phase 14 — Relations panel"
    run_phase "Relations panel" "14_relations.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "15"; then
    header "Phase 15 — Vocabularies"
    run_phase "Vocabularies" "15_vocabularies.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "16"; then
    header "Phase 16 — Welcome & Search-Replace"
    run_phase "Welcome & Search-Replace" "16_welcome_search_replace.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "17"; then
    header "Phase 17 — Zotero"
    run_phase "Zotero libraries & links" "17_zotero.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "18"; then
    header "Phase 18 — JSON filter"
    run_phase "JSON filter" "18_json_filter.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "20"; then
    header "Phase 20 — Fuzzy-date plugin"
    run_phase "Fuzzy-date plugin" "20_fuzzy_date.hurl" \
      --variable "jwt=${JWT}" \
      --variable "us_id_1=${US_ID_1:-}"
  fi

  if should_run "21"; then
    header "Phase 21 — Chrono filter"
    run_phase "Chrono filter" "21_chrono_filter.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "22"; then
    header "Phase 22 — Schema structural changes"
    run_phase "Schema structural changes" "22_schema_changes.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "23"; then
    header "Phase 23 — Widgets"
    run_phase "Widgets" "23_widgets.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "24"; then
    header "Phase 24 — Logs"
    run_phase "Logs" "24_logs.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "25"; then
    header "Phase 25 — API keys"
    run_phase "API keys" "25_api_keys.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "26"; then
    header "Phase 26 — Saved queries"
    run_phase "Saved queries" "26_saved_queries.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "27"; then
    header "Phase 27 — Templates"
    run_phase "Templates" "27_templates.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "28"; then
    header "Phase 28 — Geoface feature ops"
    run_phase "Geoface feature ops" "28_geoface_ops.hurl" \
      --variable "jwt=${JWT}" \
      --variable "us_id_1=${US_ID_1:-}"
  fi

  if should_run "29"; then
    header "Phase 29 — History & confirm-admin-pwd"
    run_phase "History & confirm-admin-pwd" "29_history_admin.hurl" \
      --variable "jwt=${JWT}" \
      --variable "admin_password=${ADMIN_PASSWORD}"
  fi

  if should_run "30"; then
    header "Phase 30 — File sort"
    run_phase "File sort" "30_file_sort.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "31"; then
    header "Phase 31 — FK indexes"
    run_phase "FK indexes" "31_fk_indexes.hurl" \
      --variable "jwt=${JWT}"
  fi

  if should_run "32"; then
    header "Phase 32 — Duplicate record"
    run_phase "Duplicate record" "32_duplicate.hurl" \
      --variable "jwt=${JWT}" \
      --variable "us_id_1=${US_ID_1:-}"
  fi

  if should_run "33"; then
    header "Phase 33 — Upgrade status"
    run_phase "Upgrade status" "33_upgrade_status.hurl" \
      --variable "jwt=${JWT}" \
      --variable "app=${APP_NAME}"
  fi

  if should_run "34"; then
    header "Phase 34 — File management"
    run_phase "File management" "34_file_management.hurl" \
      --variable "jwt=${JWT}" \
      --variable "us_id_1=${US_ID_1:-}"
  fi

  # Phase 10 always runs: drops crud_test tables and logs out
  header "Phase 10 — Cleanup"
  run_phase "Cleanup" "10_cleanup.hurl" \
    --variable "jwt=${JWT}"
  JWT=""
}

# ── run_seed: phase 19 (full demo + extended seed) ───────────────
# Always does a fresh login (JWT may be stale after run_tests).
# If setup ran but tests did NOT, first cleans up crud_test via
# phase 10 (which also logs out), then re-logs in.

run_seed() {
  if [[ "${RUN_SETUP:-false}" == true && "${RUN_TESTS:-false}" == false ]]; then
    header "Phase 10 — Cleanup (pre-seed)"
    run_phase "Cleanup" "10_cleanup.hurl" \
      --variable "jwt=${JWT}"
  fi

  header "Phase 2 — Login (pre-seed)"
  do_login

  header "Phase 19 — Demo seed"
  run_phase "Demo seed" "19_seed_demo.hurl" \
    --variable "jwt=${JWT}"
}
