---
title: Creating a new application
---

# Creating a new application

A new BraDypUS application can be created entirely through the web UI —
no code or command line required.

## Prerequisites

The server must have `BRADYPUS_ALLOW_NEW_APP=1` set as an environment variable.
In the Docker Compose setup this is already set in `docker-compose.yml`.

::: warning Security notice
`BRADYPUS_ALLOW_NEW_APP=1` should be enabled only during initial setup or
when intentionally creating new applications. On a shared server, disable it
after creation to prevent unauthorised app creation.
:::

## Creating the first application

When no applications exist yet, the login page shows a **Create new application**
link. Click it to open the creation form.

On an existing installation, the link is hidden by default. An administrator
with file-system access can temporarily enable it via the environment variable.

![TODO_SCREENSHOT: Login page with the 'Create new application' link visible](/images/v5/create-app/login-with-create-link.png)

## The creation form

![TODO_SCREENSHOT: New application form with all fields filled in](/images/v5/create-app/new-app-form.png)

| Field | Description |
|---|---|
| **Application name** | Unique identifier — see [naming rules](/guide/conventions#application-name) |
| **Application description** | Short description of the database |
| **Your email** | Will be your login email as the initial super-admin user |
| **Your password** | Will be your login password |
| **Database engine** | `sqlite` (no extra config), `mysql`, or `pgsql` |

For MySQL and PostgreSQL, additional connection fields appear:
**host**, **port**, **database name**, **username**, **password**.

::: tip SQLite for development
SQLite requires no external database service and is the recommended engine
for development and single-user deployments.
:::

## After creation

On success, you are redirected to the login page. Log in with the email and
password you entered. The application starts empty — proceed to
[Setup](/guide/setup/) to create your first data tables.

See [App anatomy](/guide/create-app/new-app-anatomy) for what gets created on disk.
