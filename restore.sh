#!/usr/bin/env bash
# restore.sh — Restore BraDypUS project data into the Docker volume from a
# backup created by backup.sh.
#
# Usage:
#   ./restore.sh                    # restore the latest full backup (bradypus-all-*.tar.gz)
#   ./restore.sh <app_name>         # restore the latest backup for a single app
#   ./restore.sh <app_name> <file>  # restore a specific archive (any of the two above)
#   ./restore.sh -y ...             # skip the confirmation prompt
#
# Data already in the volume is overwritten by files present in the archive;
# anything not in the archive is left untouched. Stop the api service first
# (docker compose -f bradypus.yml stop api) to avoid restoring under a live
# writer.
#
# The actual extraction runs inside the bdus-api image (docker-restore.sh,
# baked in at /usr/local/bin/) via a throwaway `docker run`, not inside the
# live api container — so this works whether or not the stack is running,
# and needs no bdus-api/bdus-app source checked out, only the image and the
# projects_data volume. Extracted files are chowned to www-data regardless
# of the ownership recorded in the archive.
#
# Requires: docker. Override the image with BDUS_API_IMAGE if you're not
# running the default ghcr.io/lad-sapienza/bdus-api:latest.

set -euo pipefail

red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
cyan()  { printf '\033[0;36m%s\033[0m\n' "$*"; }
yellow(){ printf '\033[1;33m%s\033[0m\n' "$*"; }

die() { red "ERROR: $*"; exit 1; }

FORCE=false
ARGS=()
for arg in "$@"; do
  case "$arg" in
    -y|--force) FORCE=true ;;
    *)          ARGS+=("$arg") ;;
  esac
done

APP_NAME="${ARGS[0]:-}"
EXPLICIT_FILE="${ARGS[1]:-}"
IMAGE="${BDUS_API_IMAGE:-ghcr.io/lad-sapienza/bdus-api:latest}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${SCRIPT_DIR}/backups"

# ── Locate the projects_data volume ─────────────────────────────────────────
VOLUME="$(docker volume ls --format '{{.Name}}' | grep -E '(^|_)projects_data$' | head -n1 || true)"
[[ -n "$VOLUME" ]] || die "no Docker volume matching '*projects_data' found. Is the stack running? Try: docker compose -f bradypus.yml up -d"

# ── Resolve which archive to restore ────────────────────────────────────────
if [[ -n "$EXPLICIT_FILE" ]]; then
  ARCHIVE="$EXPLICIT_FILE"
  [[ -f "$ARCHIVE" ]] || die "archive not found: ${ARCHIVE}"
else
  if [[ -n "$APP_NAME" ]]; then
    PATTERN="bradypus-${APP_NAME}-*.tar.gz"
  else
    PATTERN="bradypus-all-*.tar.gz"
  fi
  ARCHIVE="$(ls -1t "${BACKUP_DIR}"/${PATTERN} 2>/dev/null | head -n1 || true)"
  if [[ -z "$ARCHIVE" ]]; then
    red "no backup found matching ${PATTERN} in ${BACKUP_DIR}"
    cyan "Available backups:"
    ls -1 "${BACKUP_DIR}" 2>/dev/null || echo "  (none)"
    exit 1
  fi
fi

if [[ -n "$APP_NAME" ]]; then
  TARGET_DESC="app '${APP_NAME}'"
else
  TARGET_DESC="ALL apps"
fi

yellow "This will overwrite data for ${TARGET_DESC} in volume '${VOLUME}' from:"
echo "  ${ARCHIVE}"
if [[ "$FORCE" != true ]]; then
  read -r -p "Continue? [y/N] " ans </dev/tty
  [[ "$ans" =~ ^[yY]$ ]] || { cyan "Aborted."; exit 0; }
fi

if [[ -n "$APP_NAME" ]]; then
  cyan "Restoring app '${APP_NAME}'…"
else
  cyan "Restoring all apps…"
fi

docker run --rm -i \
  --entrypoint /usr/local/bin/docker-restore.sh \
  -v "${VOLUME}:/var/www/html/projects" \
  "${IMAGE}" < "${ARCHIVE}"

green "Restore complete."
