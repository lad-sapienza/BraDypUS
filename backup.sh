#!/usr/bin/env bash
# backup.sh — Back up BraDypUS project data from the Docker volume.
#
# Usage:
#   ./backup.sh [-p <project>]              # back up every app (whole volume)
#   ./backup.sh [-p <project>] <app_name>   # back up a single app only
#
#   -p <project>   Compose project of the target instance; the volume is
#                  <project>_projects_data. Required when more than one
#                  BraDypUS instance shares this host (otherwise the single
#                  projects_data volume is auto-detected).
#
# Archives are written to ./backups/ as:
#   bradypus-all-<timestamp>.tar.gz          (no app argument)
#   bradypus-<app_name>-<timestamp>.tar.gz   (single app)
#
# The actual archiving runs inside the bdus-api image (docker-backup.sh,
# baked in at /usr/local/bin/) via a throwaway `docker run`, not inside the
# live api container — so this works whether or not the stack is running,
# and needs no bdus-api/bdus-app source checked out, only the image and the
# projects_data volume.
#
# Requires: docker. Override the image with BDUS_API_IMAGE if you're not
# running the default ghcr.io/lad-sapienza/bdus-api:latest.

set -euo pipefail

red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
cyan()  { printf '\033[0;36m%s\033[0m\n' "$*"; }

die() { red "ERROR: $*"; exit 1; }

PROJECT=""
APP_NAME=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -p|--project) PROJECT="${2:-}"; [[ -n "$PROJECT" ]] || die "-p needs a value"; shift 2 ;;
    -p=*)         PROJECT="${1#-p=}"; shift ;;
    --project=*)  PROJECT="${1#--project=}"; shift ;;
    -h|--help)    sed -n '2,25p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; exit 0 ;;
    -*)           die "unknown option: $1 (see: $0 --help)" ;;
    *)            APP_NAME="$1"; shift ;;
  esac
done

IMAGE="${BDUS_API_IMAGE:-ghcr.io/lad-sapienza/bdus-api:latest}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${SCRIPT_DIR}/backups"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"

# ── Locate the projects_data volume ────────────────────────────────────────
# Compose prefixes the volume with the project name, e.g. bdus-prod_projects_data.
# One instance on the host → auto-detect; several → require -p <project>.
if [[ -n "$PROJECT" ]]; then
  VOLUME="${PROJECT}_projects_data"
  docker volume inspect "$VOLUME" >/dev/null 2>&1 \
    || die "volume '${VOLUME}' not found (from -p '${PROJECT}'). Present: $(docker volume ls --format '{{.Name}}' | grep -E '(^|_)projects_data$' | tr '\n' ' ')"
else
  _matches="$(docker volume ls --format '{{.Name}}' | grep -E '(^|_)projects_data$' || true)"
  _count=0; VOLUME=""
  while IFS= read -r _v; do
    [[ -n "$_v" ]] || continue
    _count=$((_count + 1)); [[ -n "$VOLUME" ]] || VOLUME="$_v"
  done <<< "$_matches"
  if [[ "$_count" -eq 0 ]]; then
    die "no Docker volume matching '*projects_data' found. Is the stack running? Try: docker compose -f bradypus.yml up -d"
  elif [[ "$_count" -gt 1 ]]; then
    red "ERROR: several projects_data volumes on this host — pass -p <project> to pick one:"
    printf '%s\n' "$_matches" | sed 's/^/  /' >&2
    exit 1
  fi
fi

if [[ -n "$APP_NAME" ]]; then
  ARCHIVE_NAME="bradypus-${APP_NAME}-${TIMESTAMP}.tar.gz"
  cyan "Backing up app '${APP_NAME}' from volume '${VOLUME}'…"
else
  ARCHIVE_NAME="bradypus-all-${TIMESTAMP}.tar.gz"
  cyan "Backing up all apps from volume '${VOLUME}'…"
fi

TMP_ARCHIVE="${BACKUP_DIR}/.${ARCHIVE_NAME}.tmp"
trap 'rm -f "$TMP_ARCHIVE"' EXIT

docker run --rm \
  --entrypoint /usr/local/bin/docker-backup.sh \
  -v "${VOLUME}:/var/www/html/projects" \
  "${IMAGE}" ${APP_NAME:+"${APP_NAME}"} > "$TMP_ARCHIVE" \
  || die "backup failed (see error above)"

mv "$TMP_ARCHIVE" "${BACKUP_DIR}/${ARCHIVE_NAME}"
green "Done: ${BACKUP_DIR}/${ARCHIVE_NAME}"
