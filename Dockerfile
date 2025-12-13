# 1. Base Image: Use the official PHP image with Apache
FROM php:8.2-apache

# 2. Install System Dependencies: Install required libraries for PHP extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libonig-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# 3. Enable mod_rewrite
RUN a2enmod rewrite

# 4. Install PHP Extensions: Enable PDO, SQLite PDO, mbstring, and GD for image processing
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_sqlite mbstring gd

# 5. Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# 6. Set Working Directory
WORKDIR /var/www/html

# 7. Copy Application Source Code
# Copy all application files from the repository (includes vendor/ with dependencies)
COPY --chown=www-data:www-data . /var/www/html

# 8. Prepare the 'projects' directory inside the container
# Create directory for user projects (can be overridden by volume mount at runtime)
RUN mkdir -p projects && chown www-data:www-data projects

# 9. Expose Port: The base image is already configured to run on port 80
EXPOSE 80