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
# The image bakes vendor/ at build time (--no-dev), so this is a dev-only
# fallback: it fires when the source is volume-mounted over /var/www/html and
# the host has no vendor/ yet. Dev installs include dev dependencies.
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "vendor/ not found, running composer install..."
    composer install --working-dir=/var/www/html --no-interaction --no-progress
fi

exec apache2-foreground
