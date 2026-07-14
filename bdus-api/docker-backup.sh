#!/bin/sh
# docker-backup.sh — runs INSIDE the api container (baked into the image at
# /usr/local/bin/docker-backup.sh). Streams a tar.gz of /var/www/html/projects
# (or a single app subdirectory) to stdout.
#
# Not meant to be run directly in a live container. Invoke it by overriding
# the entrypoint against the projects_data volume, so it works even if the
# stack isn't running — only the volume needs to exist:
#
#   docker run --rm \
#     --entrypoint /usr/local/bin/docker-backup.sh \
#     -v <projects_data_volume>:/var/www/html/projects \
#     ghcr.io/lad-sapienza/bdus-api:latest [app_name] > backup.tar.gz
#
# See backup.sh at the monorepo root for a wrapper that resolves the volume
# name and image tag automatically.

set -eu

APP_NAME="${1:-}"

cd /var/www/html/projects

if [ -n "$APP_NAME" ]; then
    [ -d "$APP_NAME" ] || {
        echo "App '$APP_NAME' not found. Available: $(ls -1 | tr '\n' ' ')" >&2
        exit 1
    }
    exec tar czf - "$APP_NAME"
fi

exec tar czf - .
