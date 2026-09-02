#!/usr/bin/env bash
# add-app.sh — create a BraDypUS application in a running Compose instance.
#
# Usage:
#   ./add-app.sh <instance-dir> --name <slug> --engine <sqlite|pgsql> \
#                --email <admin-email> [--db-name <name>] [--definition "<text>"] \
#                [--password-stdin]
#
# Reads <instance-dir>/.env for COMPOSE_* (and, for pgsql, POSTGRES_USER /
# POSTGRES_PASSWORD). For pgsql it creates the app's database on the compose
# "postgres" service if it does not exist, then runs bin/create-app.php inside
# the "api" container — no HTTP, no BRADYPUS_ALLOW_NEW_APP toggle, no restart.
#
# The admin password is prompted (hidden) unless --password-stdin is given, in
# which case it is read from the first line of stdin.
#
# Requires: docker (Compose v2).

set -euo pipefail

red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
cyan()  { printf '\033[0;36m%s\033[0m\n' "$*"; }
die()   { red "ERROR: $*"; exit 1; }

case "${1:-}" in
  -h|--help|'') sed -n '2,17p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; [ -z "${1:-}" ] && exit 1 || exit 0 ;;
esac
INSTDIR="$1"
shift
[ -d "$INSTDIR" ]      || die "instance directory not found: $INSTDIR"
[ -f "$INSTDIR/.env" ] || die "no .env in $INSTDIR"

NAME="" ENGINE="" EMAIL="" DBNAME="" DEFN="" PW_STDIN=0
DBHOST="postgres" DBPORT="5432"
while [ $# -gt 0 ]; do
  case "$1" in
    --name)           NAME="${2:-}";   [ -n "$NAME" ]   || die "--name needs a value";   shift 2 ;;
    --engine)         ENGINE="${2:-}"; [ -n "$ENGINE" ] || die "--engine needs a value"; shift 2 ;;
    --email)          EMAIL="${2:-}";  [ -n "$EMAIL" ]  || die "--email needs a value";  shift 2 ;;
    --db-name)        DBNAME="${2:-}"; shift 2 ;;
    --db-host)        DBHOST="${2:-}"; shift 2 ;;
    --db-port)        DBPORT="${2:-}"; shift 2 ;;
    --definition)     DEFN="${2:-}";   shift 2 ;;
    --password-stdin) PW_STDIN=1; shift ;;
    -h|--help)        sed -n '2,17p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

[ -n "$NAME" ]   || die "--name required"
[ -n "$ENGINE" ] || die "--engine required"
[ -n "$EMAIL" ]  || die "--email required"
case "$ENGINE" in
  sqlite|pgsql) ;;
  *) die "--engine must be 'sqlite' or 'pgsql' (mysql: use bin/create-app.php directly)" ;;
esac
[ "$ENGINE" = pgsql ] && [ -z "$DBNAME" ] && DBNAME="bdus_${NAME}"

# ── admin password ─────────────────────────────────────────────────────────
if [ "$PW_STDIN" -eq 1 ]; then
  IFS= read -r ADMIN_PW || true
else
  printf 'Admin password for %s: ' "$NAME" >&2
  stty -echo 2>/dev/null || true
  IFS= read -r ADMIN_PW
  stty echo 2>/dev/null || true
  printf '\n' >&2
fi
[ -n "$ADMIN_PW" ] || die "empty admin password"

cd "$INSTDIR"
# shellcheck disable=SC1091
. ./.env

# ── pgsql: ensure the app database exists ──────────────────────────────────
if [ "$ENGINE" = pgsql ]; then
  : "${POSTGRES_USER:?POSTGRES_USER not set in $INSTDIR/.env}"
  : "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD not set in $INSTDIR/.env}"
  exists="$(docker compose exec -T postgres \
    psql -U "$POSTGRES_USER" -d postgres -tAc \
    "SELECT 1 FROM pg_database WHERE datname = '${DBNAME}'" 2>/dev/null | tr -d '[:space:]' || true)"
  if [ "$exists" = "1" ]; then
    cyan "database '${DBNAME}' already exists — reusing"
  else
    docker compose exec -T postgres \
      psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -c \
      "CREATE DATABASE \"${DBNAME}\" OWNER \"${POSTGRES_USER}\""
    green "database '${DBNAME}' created"
  fi
fi

# ── run the CLI inside the api container ───────────────────────────────────
# BDUS_DB_PASS travels in the exec'd process environment, never in argv.
exec_args=(exec -T)
cli_args=(--name "$NAME" --engine "$ENGINE" --email "$EMAIL" \
          --definition "${DEFN:-$NAME}" --password-stdin)
if [ "$ENGINE" = pgsql ]; then
  exec_args+=(-e "BDUS_DB_PASS=${POSTGRES_PASSWORD}")
  cli_args+=(--db-host "$DBHOST" --db-port "$DBPORT" --db-name "$DBNAME" --db-user "$POSTGRES_USER")
fi
exec_args+=(api php bin/create-app.php)

printf '%s' "$ADMIN_PW" | docker compose "${exec_args[@]}" "${cli_args[@]}"

green "done — app '${NAME}' (${ENGINE})"
