---
title: Troubleshooting
---

# Troubleshooting app creation

## "App creation is not permitted"

`BRADYPUS_ALLOW_NEW_APP=1` is not set in the server environment.
Set the environment variable and restart the server.

## "App name already in use"

A directory with that name already exists in `projects/`. Either choose a
different name or manually delete the existing directory if it is a failed
previous attempt.

## "App name is invalid"

The name must match `^[a-z][a-z0-9_-]{2,19}$` — start with a lowercase letter,
3–20 characters, only lowercase letters, digits, hyphens and underscores.

## "Cannot create directory"

The web server does not have write permission to the `projects/` directory.
Grant write access to the `www-data` user (or equivalent) on `projects/`.

## "Could not connect to database" (MySQL/PostgreSQL)

- Verify the database server is running and reachable from the PHP container.
- Verify the database and user exist and the user has `CREATE TABLE` privileges.
- Check host, port, database name, username and password.

## Application created but login fails

The JWT secret may not have been written correctly. Check that
`projects/{app}/.jwt_secret` exists and is readable by the web server.
