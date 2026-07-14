#!/usr/bin/env bash
# backup.sh — Back up BraDypUS project data from the Docker volume.
#
# Usage:
#   ./backup.sh              # back up every app (entire projects_data volume)
#   ./backup.sh <app_name>   # back up a single app only
#
# Archives are written to ./backups/ as:
#   bradypus-all-<timestamp>.tar.gz          (no argument)
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

APP_NAME="${1:-}"
IMAGE="${BDUS_API_IMAGE:-ghcr.io/lad-sapienza/bdus-api:latest}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${SCRIPT_DIR}/backups"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"

# ── Locate the projects_data volume (name may be prefixed by the compose
#    project/directory name, e.g. "bradypus_projects_data") ────────────────
VOLUME="$(docker volume ls --format '{{.Name}}' | grep -E '(^|_)projects_data$' | head -n1 || true)"
[[ -n "$VOLUME" ]] || die "no Docker volume matching '*projects_data' found. Is the stack running? Try: docker compose -f bradypus.yml up -d"

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
