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
# Requires: docker (the bradypus.yml stack does not need to be running,
# only the named volume must exist).

set -euo pipefail

red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
cyan()  { printf '\033[0;36m%s\033[0m\n' "$*"; }

die() { red "ERROR: $*"; exit 1; }

APP_NAME="${1:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${SCRIPT_DIR}/backups"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"

# ── Locate the projects_data volume (name may be prefixed by the compose
#    project/directory name, e.g. "bradypus_projects_data") ────────────────
VOLUME="$(docker volume ls --format '{{.Name}}' | grep -E '(^|_)projects_data$' | head -n1 || true)"
[[ -n "$VOLUME" ]] || die "no Docker volume matching '*projects_data' found. Is the stack running? Try: docker compose -f bradypus.yml up -d"

if [[ -n "$APP_NAME" ]]; then
  docker run --rm -v "${VOLUME}:/data" alpine sh -c "test -d /data/'${APP_NAME}'" \
    || { red "App '${APP_NAME}' not found in volume '${VOLUME}'."; \
         cyan "Available apps:"; \
         docker run --rm -v "${VOLUME}:/data" alpine sh -c 'ls -1 /data 2>/dev/null'; \
         exit 1; }

  ARCHIVE_NAME="bradypus-${APP_NAME}-${TIMESTAMP}.tar.gz"
  cyan "Backing up app '${APP_NAME}' from volume '${VOLUME}'…"
  docker run --rm \
    -v "${VOLUME}:/data" \
    -v "${BACKUP_DIR}:/backup" \
    alpine tar czf "/backup/${ARCHIVE_NAME}" -C /data "${APP_NAME}"
else
  ARCHIVE_NAME="bradypus-all-${TIMESTAMP}.tar.gz"
  cyan "Backing up all apps from volume '${VOLUME}'…"
  docker run --rm \
    -v "${VOLUME}:/data" \
    -v "${BACKUP_DIR}:/backup" \
    alpine tar czf "/backup/${ARCHIVE_NAME}" -C /data .
fi

green "Done: ${BACKUP_DIR}/${ARCHIVE_NAME}"
