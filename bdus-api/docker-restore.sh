#!/bin/sh
# docker-restore.sh — runs INSIDE the api container (baked into the image at
# /usr/local/bin/docker-restore.sh). Reads a tar.gz from stdin and extracts
# it into /var/www/html/projects, in place: files present in the archive
# overwrite existing ones, anything not in the archive is left untouched
# (this applies to both full and single-app archives — a single-app archive
# already has the app name as its top-level entry, from docker-backup.sh).
#
# Not meant to be run directly in a live container. Invoke it by overriding
# the entrypoint against the projects_data volume, so it works even if the
# stack isn't running — only the volume needs to exist. Stop the api service
# first if it's running, to avoid restoring under a live writer:
#
#   docker run --rm -i \
#     --entrypoint /usr/local/bin/docker-restore.sh \
#     -v <projects_data_volume>:/var/www/html/projects \
#     ghcr.io/lad-sapienza/bdus-api:latest < backup.tar.gz
#
# See restore.sh at the monorepo root for a wrapper that resolves the volume
# name and image tag automatically, with a confirmation prompt.

set -eu

tar xzf - -C /var/www/html/projects

# Ownership recorded in the archive may not match this container's www-data
# uid/gid (e.g. an archive built on a dev machine) — reset it so the api
# container can write to the restored files.
chown -R www-data:www-data /var/www/html/projects
