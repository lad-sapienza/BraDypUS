---
title: Manual installation
---

# Manual installation (without Docker)

Use this approach for shared hosting or servers where Docker is not available.

## Requirements

See [System requirements](/guide/environment/system-requirements) for the
full list of PHP extensions and server requirements.

## 1. Download bdus-api

```bash
git clone https://github.com/lad-sapienza/bdus-api.git
cd bdus-api
composer install --no-dev
```

Or download the ZIP from GitHub Releases and unzip it.

## 2. Build bdus-app (frontend)

```bash
git clone https://github.com/lad-sapienza/bdus-app.git
cd bdus-app
npm install
npm run build
```

This produces a `dist/` directory with the static Vue SPA.

## 3. Configure the web server

The simplest approach: put `bdus-api/` under the web root and copy
`bdus-app/dist/` contents to a subdirectory (or the same root).

### Apache example (`.htaccess` in `bdus-api/`)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

### Nginx example

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
location /api/ {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

## 4. Set environment variables

Set `BRADYPUS_ALLOW_NEW_APP=1` to enable the new-application wizard,
and `BRADYPUS_DEBUG=0` for production.

For Apache, add to `.htaccess`:

```apache
SetEnv BRADYPUS_ALLOW_NEW_APP 1
```

## 5. Create the projects directory

```bash
mkdir bdus-api/projects
chmod 755 bdus-api/projects
```

Ensure the web server user (`www-data` on Debian/Ubuntu) has write access.

## 6. Open the application and create your first app

Navigate to your server URL and follow the [Create application](/guide/create-app/) guide.
