FROM php:8.2-apache

# System dependencies for PHP extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libonig-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Enable mod_rewrite for .htaccess routing
RUN a2enmod rewrite

# PHP extensions: PDO + SQLite, mbstring, GD (image thumbnailing)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_sqlite mbstring gd

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' \
    /etc/apache2/apache2.conf

# Composer (copied from official image — no version pinning needed)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy source (used in production; overridden by volume mount in dev)
COPY --chown=www-data:www-data . /var/www/html

# Ensure projects directory exists and is writable
RUN mkdir -p projects && chown www-data:www-data projects

# Entrypoint handles permissions + optional composer install + starts Apache
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
