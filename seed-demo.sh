#!/usr/bin/env bash
# seed-demo.sh — Populate a BraDypUS instance with realistic demo data.
#
# Thin wrapper around bdus-api/test.sh (--no-docker --setup --seed): creates
# a new application and fills it with the full archaeological demo dataset
# (siti, complessi, saggi, US, reperti, sepolture, RS relations, geodata,
# chart, ...). See bdus-api/tests/api/19_seed_demo.hurl for exactly what
# gets created.
#
# Usage:
#   ./seed-demo.sh              # app name "bdus_demo"
#   ./seed-demo.sh <app_name>   # custom app name
#   ./seed-demo.sh <app_name> --reset   # auto-delete the app first if it exists
#   (any extra arguments are forwarded to bdus-api/test.sh as-is)
#
# Target server / admin credentials come from bdus-api/tests/api/vars.local.env
# if present, otherwise fall back to vars.env (target: http://localhost:8080).
# To seed a remote testing server:
#
#   cp bdus-api/tests/api/vars.env bdus-api/tests/api/vars.local.env
#   # edit BASE_URL / ADMIN_EMAIL / ADMIN_PASSWORD in that file
#
# The target server needs BRADYPUS_ALLOW_NEW_APP=1 enabled (only required
# for the app-creation step — safe to turn off again afterwards).
#
# Requires: bdus-api/ cloned next to this script, hurl >= 4.0, jq.

set -euo pipefail

red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
cyan()  { printf '\033[0;36m%s\033[0m\n' "$*"; }

die() { red "ERROR: $*"; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="${SCRIPT_DIR}/bdus-api"

[[ -d "$API_DIR" ]] || die "bdus-api/ not found next to this script. Clone it: git clone https://github.com/lad-sapienza/bdus-api.git"
[[ -x "${API_DIR}/test.sh" ]] || die "${API_DIR}/test.sh not found or not executable"

for cmd in hurl jq; do
  command -v "$cmd" &>/dev/null || die "$cmd not found (brew install $cmd)"
done

APP_NAME="bdus_demo"
if [[ $# -gt 0 && "$1" != --* ]]; then
  APP_NAME="$1"
  shift
fi
EXTRA_ARGS=("$@")

VARS_DIR="${API_DIR}/tests/api"
VARS_LOCAL="${VARS_DIR}/vars.local.env"
VARS_DEFAULT="${VARS_DIR}/vars.env"
[[ -f "$VARS_DEFAULT" ]] || die "${VARS_DEFAULT} not found"

if [[ ! -f "$VARS_LOCAL" ]]; then
  cyan "No vars.local.env found — using defaults from vars.env (target: http://localhost:8080)."
  cyan "To seed a remote server, copy vars.env to vars.local.env and set BASE_URL first."
fi

# ── Override APP_NAME for this run only, restoring vars.local.env afterwards ──
BACKUP_VARS=""
if [[ -f "$VARS_LOCAL" ]]; then
  BACKUP_VARS="$(mktemp)"
  cp "$VARS_LOCAL" "$BACKUP_VARS"
fi

restore_vars() {
  if [[ -n "$BACKUP_VARS" ]]; then
    mv "$BACKUP_VARS" "$VARS_LOCAL"
  else
    rm -f "$VARS_LOCAL"
  fi
}
trap restore_vars EXIT

SOURCE_VARS="$VARS_LOCAL"
[[ -f "$SOURCE_VARS" ]] || SOURCE_VARS="$VARS_DEFAULT"
grep -v '^APP_NAME=' "$SOURCE_VARS" > "$VARS_LOCAL"
echo "APP_NAME=${APP_NAME}" >> "$VARS_LOCAL"

cyan "Seeding demo data into app '${APP_NAME}'…"
if [[ ${#EXTRA_ARGS[@]} -eq 0 ]]; then
  "${API_DIR}/test.sh" --no-docker --setup --seed
else
  "${API_DIR}/test.sh" --no-docker --setup --seed "${EXTRA_ARGS[@]}"
fi

green "Done."
