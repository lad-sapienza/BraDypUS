#!/bin/bash
set -e

# Create writable directories if missing
mkdir -p /var/www/html/cache \
         /var/www/html/logs

# Fix permissions on writable directories
# (needed when source is volume-mounted from host)
chown -R www-data:www-data \
    /var/www/html/cache \
    /var/www/html/logs \
    /var/www/html/projects 2>/dev/null || true

# Install PHP dependencies if vendor is missing.
# vendor/ is gitignored and never baked into the image at build time, so this
# runs on every fresh container (not just a fallback).
#
# --ignore-platform-req=php: some locked packages (e.g. funiq/geophp,
# symfony/process pulled in by spatie/db-dumper) declare php constraints
# stricter than what actually runs; composer.json pins config.platform.php
# to match the image's PHP, but "composer install" still validates each
# locked package's own declared range against it. Skip that check — it
# blocks install even though nothing here is actually incompatible with
# the image's PHP version.
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "vendor/ not found, running composer install..."
    composer install --working-dir=/var/www/html --no-interaction --no-progress --ignore-platform-req=php
fi

exec apache2-foreground
