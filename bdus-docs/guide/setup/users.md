---
title: Users & privileges
---

# Users & privileges

User management is available from **Users** in the sidebar navigation.
Admin privilege or higher is required.

![TODO_SCREENSHOT: UsersView showing a table of users with name, email and privilege columns](/images/v5/setup/users-list.png)

## Privilege levels

BraDypUS uses a numeric privilege system. Lower numbers mean higher access.

| Level | Name | Can do |
|---|---|---|
| 1 | **Super-admin** | Everything, including Config and schema changes |
| 10 | **Admin** | User management, backups, logs, migrations |
| 20 | **Editor (write)** | Create, edit and delete records |
| 25 | **Contributor** | Create and edit records, but not delete |
| 30 | **Reader** | Read records only |
| 40 | **Pending** | No access until an admin changes the privilege |

## Adding a user

Click **Add user** and fill in name, email, password and privilege level.
The new user can immediately log in with those credentials.

## Editing a user

Click a user row to expand it. Edit name, email or privilege, then click **Save**.

To change a password, enter a new value in the **Password** field. Leave it
blank to keep the existing password.

## Deleting a user

Click the **Delete** button in the expanded user row. The user's records are
not affected — only the login account is removed.

## Per-table privilege overrides

Expand a user row and click **Table privileges** to assign table-specific
overrides. This allows, for example, giving a reader user write access to a
specific table, or restricting an editor to a subset of records.

![TODO_SCREENSHOT: Per-table privilege override panel expanded for a user](/images/v5/setup/user-table-privileges.png)

| Property | Description |
|---|---|
| **Table** | The table this override applies to |
| **Privilege** | Override privilege level for this table |
| **Row filter** | Optional SQL WHERE fragment to restrict which records the user can see (e.g. `site_id = 5`) |

::: warning
Row filters are appended to every query for that user and table. Write them
carefully — a syntax error will prevent the user from seeing any records in that table.
:::
