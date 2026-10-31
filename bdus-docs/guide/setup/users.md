---
title: Users & privileges
---

# Users & privileges

User management is available from **Users** in the sidebar navigation.
Admin privilege or higher is required.

![The Users view: tabs for users, groups and locks, and a table of users with their level and groups](/images/v5/setup/users-list.png)

::: tip <Badge type="tip" text="v5.6.0" />
Once the list grows past 7 users, a filter box appears next to the title —
it matches on name or email as you type.
:::

## Privilege levels

BraDypUS uses a numeric privilege system. Lower numbers mean higher access.
A user's level is their baseline on **every** table; [groups](./groups) can add
access on specific tables and rows, never take it away.

| Level | Name | Can do |
|---|---|---|
| 1 | **Super admin** | Everything, including the system configuration and schema changes |
| 10 | **Admin** | All the data (records frozen by a [lock](./groups#locks) included); manages users, groups, locks, backups and logs |
| 20 | **Writer** | Read, create, edit and delete records |
| 25 | **Self writer** | Read and create records; edit and delete only the ones they created |
| 30 | **Reader** | Read every table, change nothing |
| 39 | **Enter** | Sign in, but see no data: access comes only from [groups](./groups) |
| 40 | **Pending** | Nothing until an admin changes the level |

::: tip <Badge type="tip" text="new" />
Level **Enter** is for people who should reach only a slice of the data — an
external reviewer, a student working on one trench. Give them the level and put
them in a group that grants what they need.
:::

## Adding a user

Click **New user** and fill in name, email, password and privilege level; admins
also choose the user's **groups**. The new user can immediately log in with those
credentials.

## Editing a user

Click the edit icon on a user row. Change name, email, level or groups, then
click **Save**.

To change a password, enter a new value in the **Password** field. Leave it
blank to keep the existing password. Passwords must be at least 8 characters
long.

<Badge type="tip" text="v5.16.4" /> A user who changes **their own** password from the profile page (non-administrators)
must also enter their **current password**. Changing a password signs the user out of
every other session; the person who made the change stays logged in.

## Deleting a user and ending a session

The **Delete** button removes the login account; the user's records are not
affected. The **Revoke session** button signs the user out everywhere.

Both are admin-only, and an admin cannot act on someone with a higher level than
their own.

## Groups

Admins see two more tabs next to the user list — **Groups** and **Locks** — and a
**Groups** column in the list. See [Groups, permissions & locks](./groups).
