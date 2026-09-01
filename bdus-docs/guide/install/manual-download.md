---
title: Manual installation
---

# Manual installation (without Docker)

Use this approach for shared hosting or servers where Docker is not available.

## Requirements

See [System requirements](/guide/environment/system-requirements) for the
full list of PHP extensions and server requirements.

## 1. Get the source

```bash
git clone https://github.com/lad-sapienza/BraDypUS.git
cd BraDypUS
```

Or download the ZIP from GitHub Releases and unzip it.

## 2. Install the backend (bdus-api)

```bash
cd bdus-api
composer install --no-dev
cd ..
```

## 3. Build the frontend (bdus-app)

```bash
cd bdus-app
npm install
npm run build
```

This produces `bdus-app/dist/` with the static Vue SPA.

## 4. Configure the web server

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

## 5. Set environment variables

Set `BRADYPUS_ALLOW_NEW_APP=1` to enable the new-application wizard,
and `BRADYPUS_DEBUG=0` for production.

For Apache, add to `.htaccess`:

```apache
SetEnv BRADYPUS_ALLOW_NEW_APP 1
```

## 6. Create the projects directory

```bash
mkdir bdus-api/projects
chmod 755 bdus-api/projects
```

Ensure the web server user (`www-data` on Debian/Ubuntu) has write access.

## 7. Open the application and create your first app

Navigate to your server URL and follow the [Create application](/guide/create-app/) guide.
