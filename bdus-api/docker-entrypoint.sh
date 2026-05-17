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

# Install PHP dependencies if vendor is missing
# (normally vendor/ is tracked in git, but this is a safety net)
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "vendor/ not found, running composer install..."
    composer install --working-dir=/var/www/html --no-interaction --no-progress
fi

exec apache2-foreground
