#!/usr/bin/env bash
# add-app.sh — create a BraDypUS application in a running Compose instance.
#
# Usage:
#   ./add-app.sh <instance-dir> --name <slug> --engine <sqlite|pgsql> \
#                --email <admin-email> [--definition "<text>"] [--password-stdin] \
#                [--db-name <name>] [--db-user <role>]
#
# sqlite: nothing else is needed.
#
# pgsql (default): the app gets its OWN, isolated Postgres role and database —
#   CREATE ROLE "<name>" LOGIN PASSWORD <generated>   (no superuser / createdb)
#   CREATE DATABASE "<name>" OWNER "<name>"
#   REVOKE CONNECT ON DATABASE "<name>" FROM PUBLIC; GRANT CONNECT ... TO the role
#   The generated password is printed once and stored (by BraDypUS) in
#   projects/<name>/config.json only. The shared superuser (POSTGRES_USER in
#   <instance-dir>/.env) is used here just to create the role/db and never
#   reaches the app.
#   --db-name  override the database name (default: <name>)
#   --db-user  reuse an EXISTING role instead of creating one; its password must
#              be supplied via the BDUS_DB_PASS environment variable
#
# The app is created by bin/create-app.php inside the "api" container — no HTTP,
# no BRADYPUS_ALLOW_NEW_APP toggle, no restart. The admin password is prompted
# (hidden) unless --password-stdin is given.
#
# Requires: docker (Compose v2), openssl (falls back to /dev/urandom).

set -euo pipefail

red()   { printf '\033[0;31m%s\033[0m\n' "$*" >&2; }
green() { printf '\033[0;32m%s\033[0m\n' "$*" >&2; }
cyan()  { printf '\033[0;36m%s\033[0m\n' "$*" >&2; }
die()   { red "ERROR: $*"; exit 1; }
usage() { sed -n '2,27p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; }

case "${1:-}" in
  -h|--help) usage; exit 0 ;;
  '')        usage; exit 1 ;;
esac
INSTDIR="$1"; shift
[ -d "$INSTDIR" ]      || die "instance directory not found: $INSTDIR"
[ -f "$INSTDIR/.env" ] || die "no .env in $INSTDIR"

NAME="" ENGINE="" EMAIL="" DEFN="" PW_STDIN=0
DBNAME="" DBUSER="" DBUSER_EXPLICIT=0
DBHOST="postgres" DBPORT="5432"
while [ $# -gt 0 ]; do
  case "$1" in
    --name)           NAME="${2:-}";   [ -n "$NAME" ]   || die "--name needs a value";   shift 2 ;;
    --engine)         ENGINE="${2:-}"; [ -n "$ENGINE" ] || die "--engine needs a value"; shift 2 ;;
    --email)          EMAIL="${2:-}";  [ -n "$EMAIL" ]  || die "--email needs a value";  shift 2 ;;
    --definition)     DEFN="${2:-}";   shift 2 ;;
    --db-name)        DBNAME="${2:-}"; shift 2 ;;
    --db-user)        DBUSER="${2:-}"; DBUSER_EXPLICIT=1; shift 2 ;;
    --db-host)        DBHOST="${2:-}"; shift 2 ;;
    --db-port)        DBPORT="${2:-}"; shift 2 ;;
    --password-stdin) PW_STDIN=1; shift ;;
    -h|--help)        usage; exit 0 ;;
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

DBNAME="${DBNAME:-$NAME}"
DBUSER="${DBUSER:-$NAME}"
case "$NAME" in
  postgres|template0|template1|pg_*) die "'$NAME' collides with a Postgres-reserved name" ;;
esac

gen_pw() { openssl rand -hex 24 2>/dev/null || head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

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

DB_PASS=""
if [ "$ENGINE" = pgsql ]; then
  : "${POSTGRES_USER:?POSTGRES_USER not set in $INSTDIR/.env}"
  : "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD not set in $INSTDIR/.env}"

  su_psql() { docker compose exec -T postgres psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -q "$@"; }
  su_q1()   { docker compose exec -T postgres psql -U "$POSTGRES_USER" -d postgres -tAqc "$1" 2>/dev/null | tr -d '[:space:]'; }

  # the app must not already exist — otherwise a re-run leaves an orphan role/db
  if docker compose exec -T api test -d "projects/$NAME" 2>/dev/null; then
    die "app '$NAME' already exists (projects/$NAME) — remove it first"
  fi

  if [ "$(su_q1 "SELECT 1 FROM pg_roles    WHERE rolname='$DBUSER'")" = 1 ]; then role_exists=1; else role_exists=0; fi
  if [ "$(su_q1 "SELECT 1 FROM pg_database WHERE datname='$DBNAME'")" = 1 ]; then db_exists=1;   else db_exists=0;   fi

  if [ "$DBUSER_EXPLICIT" -eq 1 ]; then
    [ "$role_exists" -eq 1 ] || die "--db-user '$DBUSER' is not an existing role (omit --db-user to have one created)"
    DB_PASS="${BDUS_DB_PASS:-}"
    [ -n "$DB_PASS" ] || die "with --db-user, supply the role's password via the BDUS_DB_PASS environment variable"
  else
    if [ "$role_exists" -eq 1 ] || [ "$db_exists" -eq 1 ]; then
      die "role '$DBUSER' or database '$DBNAME' already exists. Drop them and retry:
  docker compose exec postgres psql -U $POSTGRES_USER -c 'DROP DATABASE IF EXISTS \"$DBNAME\"'
  docker compose exec postgres psql -U $POSTGRES_USER -c 'DROP ROLE IF EXISTS \"$DBUSER\"'"
    fi
    DB_PASS="$(gen_pw)"
    # password over stdin, never in argv / psql history
    printf 'CREATE ROLE "%s" LOGIN PASSWORD %s;\n' "$DBUSER" "'$DB_PASS'" \
      | docker compose exec -T postgres psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -q
    green "postgres role '$DBUSER' created (no superuser, no createdb)"
  fi

  if [ "$db_exists" -eq 1 ]; then
    cyan "database '$DBNAME' already exists — reusing"
  else
    su_psql -c "CREATE DATABASE \"$DBNAME\" OWNER \"$DBUSER\""
    su_psql -c "REVOKE CONNECT ON DATABASE \"$DBNAME\" FROM PUBLIC"
    su_psql -c "GRANT  CONNECT ON DATABASE \"$DBNAME\" TO \"$DBUSER\""
    green "database '$DBNAME' created (owner $DBUSER; PUBLIC CONNECT revoked)"
  fi
fi

# ── run the CLI inside the api container ───────────────────────────────────
# the DB password travels in the exec'd process environment, never in argv.
# run as www-data so the new projects/<name>/ tree is writable by Apache.
exec_args=(exec -T -u www-data)
cli_args=(--name "$NAME" --engine "$ENGINE" --email "$EMAIL" \
          --definition "${DEFN:-$NAME}" --password-stdin)
if [ "$ENGINE" = pgsql ]; then
  exec_args+=(-e "BDUS_DB_PASS=${DB_PASS}")
  cli_args+=(--db-host "$DBHOST" --db-port "$DBPORT" --db-name "$DBNAME" --db-user "$DBUSER")
fi
exec_args+=(api php bin/create-app.php)

printf '%s' "$ADMIN_PW" | docker compose "${exec_args[@]}" "${cli_args[@]}"

green "done — app '${NAME}' (${ENGINE})"
if [ "$ENGINE" = pgsql ] && [ "$DBUSER_EXPLICIT" -eq 0 ]; then
  cyan "DB credentials (also stored in projects/$NAME/config.json):"
  printf '  host=%s db=%s user=%s password=%s\n' "$DBHOST" "$DBNAME" "$DBUSER" "$DB_PASS" >&2
fi
