---
title: Record layouts per group
---

# Record layouts per group <Badge type="tip" text="v5.20.0" />

> **The layout decides what is comfortable to see and edit; the groups decide what is allowed.**

A table is often used by people with different tasks: who enters data, who
reviews, who approves, who only consults. [Groups](./groups) already say what each
of them is **allowed** to do. A *record layout* says how the record **looks** for
them: which [template](../template-system/) (sections, field order, widths,
accordions, plugin tables) opens when they view or edit a record.

You assign layouts to a **group**. A person in a group that has a layout for a
table no longer picks a template from the menu: they get the one (or the few)
the group assigns. Nobody else notices anything.

::: danger This is NOT security
A layout is an interface convenience. **It never protects data.**

- A field that a layout does not show can still be read through the API, text
  search, filters, sorting, export, record history, charts and maps.
- The server keeps deciding with the [group rules](./groups), exactly as before. A
  layout cannot grant or take away a permission.
- Do not use a layout to hide confidential data. For that, register an
  [SQL view as a table](./views) and give the group access to the view, not to the
  table (mind [its limits](./views#why-there-are-no-per-field-permissions)).
:::

## How it works

Layouts live **on the group**, per table: *"for table `records`, members of this
group use the template `reviewer_form`"*. For one user and one table BraDypUS
looks at all the groups they belong to:

| The user's groups assign, for this table… | The user gets |
|---|---|
| nothing | **The menu as before**: any template, or the default layout. Nothing changes. |
| one template | That template, **no menu**. |
| several templates | A menu with **only those**, without the "default layout" entry; the first one opens by default. |

Details that make the result predictable:

- **Several groups are united.** Templates are collected group by group (in the
  order of the group ids, each group's templates in the order listed) and
  duplicates are dropped. A person who is both cataloguer and reviewer gets both
  templates and chooses between them. There is no priority to set.
- **Per table.** A layout for table `records` does not affect table `sites`.
- **Admins and super admins are never restricted.** They keep the full menu, so
  they can open every template to test or correct it.
- **API keys** follow their own group, like everywhere else (`group_id` of the
  key). A key has no menu: when its group assigns a layout, the record it reads
  carries the assigned template in `schema.template` even if it did not ask for one.
- **The server applies the rule, not only the screen.** Asking the API for a
  template the user's groups do not assign (`?template=other`) is ignored and the
  first assigned template is used.
- **View and edit use the same template**, and so does creating a new record: the
  record form is drawn by the template in both modes.
- **Nothing assigned means nothing changes.** Existing applications behave
  exactly as before until an administrator assigns a layout to a group.

### When the assignment goes stale

- Renaming a template in the template editor renames it in every group that uses
  it. Deleting it removes it from the groups.
- If, for any reason, a group still names a template that does not exist, that
  name is **ignored**. If *nothing valid* is left for a user on a table, they are
  not restricted (they see the menu as before) rather than getting a broken page.
- When you assign a layout the server checks that the table and the templates
  exist. Field and plugin names are checked when the **template** is saved
  ([Print templates](../template-system/)), not again at assignment.

## Set it up

1. **Create the templates** in *Templates* (super admin), one per profile. See
   [Print templates](../template-system/). Keep names meaningful for the people
   who will see them in the menu (`cataloguer`, `reviewer`, `consultation`).
2. **Create the groups**, or reuse yours, and put the people in them
   ([Groups](./groups)).
3. In **Users → Groups**, select the group and scroll to **Record layouts**. Click
   **Add table**, choose the table and one or more templates, then **Save**.
   Repeat for other tables. To remove the assignment, delete the row and save.
4. Log in as a member (or ask a colleague) and open a record: the menu is gone or
   reduced.

The system groups (Reader, Writer, Self writer) cannot be changed: their layouts
are shown but not editable. Create your own group when you need one.

## What a layout can and cannot do today

A layout is **a choice of template**. What it can change is what a template can
express: which fields and plugin tables appear, in which order and width, sections
and accordions, collapsed or open by default, and, for each field of the table, a
different **label**, **read-only** or **hidden**
([field options](../template-system/#field-options-label-read-only-hidden)).

| | In this release |
|---|---|
| Choose which template a group uses, per table | Yes |
| Fields, order, sections, accordions, plugin tables | Yes, as in any template |
| Fields hidden by the layout | Yes: leave them out of the template, or mark them `hidden` (not secure, see above) |
| Fields that show but cannot be edited **per layout** | Yes: `readonly` on the field's item. **Interface only**: the server does not know about it, so the field can still be written through the API if the group's rules allow. To make the server refuse other values, pin them with an `enforce` rule |
| Different label for a field **per layout** | Yes: `label` on the field's item (plugin columns keep their own label) |
| Different layout for creating and for editing | **Not yet**: one template draws both |
| Different layout by record state | **Not yet**: obtain it with a status field, a lock and a group per state |
| Columns and preview of the list per layout | **No**: the list and its preview are set per table |
| Base layout with differences (inheritance) | **Not yet**: today each template is complete |

The menu choice a person makes (when they have several templates) is remembered in
their browser, per table; it is only used if it is one of the assigned templates.

The ideas not built yet (inheritance, create-vs-edit, by record state, list columns per
layout, DBML export) are collected in
[issue #64](https://github.com/lad-sapienza/BraDypUS/issues/64).

## Where layouts help

Each example is generic: adapt the names to your tables and groups.

### Cataloguing with review

*Cataloguer, reviewer, approver* on one table of records or collection items.
Three groups with the same members' permissions as usual (the **rules** give the
cataloguer create/update on the open records, the reviewer update, the approver
update and the publish step), and three templates: a short form with the fields
to fill in (`cataloguer`), the same fields plus the reviewer's notes
(`reviewer`), and a compact view with the approval fields first (`approver`).
The cataloguer no longer wades through review fields; the approver finds the few
fields they need. A person who is cataloguer **and** reviewer is in both groups
and chooses between the two templates.

### Field work and laboratory on the same table

A table of finds or samples used in the field and in the laboratory. Group
*Field team*: template with description, location and context. Group
*Laboratory*: template with measurements, treatments and analysis results,
including the plugin tables of the lab. Same records, each team sees its own
fields first.

### Several institutions or projects sharing one archive

One table, several groups (one per institution). Row rules and group attributes
(`$GROUP.<name>`, see [Groups](./groups#attributes-and-pinned-values)) decide
**which records** each institution sees and changes; a layout per group shows
**the fields** that matter to that institution.

### Students, tutors and occasional collaborators

A *Students* group with a simplified template: few fields, wide, with the
essentials. A *Tutors* group with the complete one. People who use the
application rarely are not confronted with fifty fields.

### Consultation and accessibility

A *Readers* group (read-only rules) with a clean template: the fields worth
reading, in order, without technical fields. Whether the person can edit is
decided by their rules, not by the layout: a layout never switches editing on or
off.

### Fewer mistakes with system fields

Fields such as a status, a number or automatic dates should not be typed by
hand. In the layouts of the people who must not touch them, mark the field
`readonly`: the value is shown, with no editor. That is a **comfort**, not a
guard: for a status that must only take certain values per group, also pin it with
an `enforce` rule, so the **server** accepts only those values. To make a field
read-only for everyone, set it read-only in the table configuration.

A layout can also **rename** a field for one audience (`label`): the cataloguer's
form can say "Description of the object" where the reviewer's says "Object
description".

## What it is not

- **Not permissions.** Permissions are the group *rules* and *locks*. A layout
  never grants or denies access, in either direction.
- **Not a replacement for SQL views.** Views are how to keep columns or rows out
  of reach ([SQL views as tables](./views)).
- **Not a theme system.** It changes which fields appear and how they are laid
  out, not colours, fonts or the look of the application.

## For integrators

- `GET /{app}/api/record/{tb}/templates` returns the templates **available to the
  caller**, plus two flags: `assigned` (the list comes from the caller's groups)
  and `locked` (a single template: nothing to choose). Without an assignment it
  lists every template, as before.
- `GET /{app}/api/record/{tb}/{id}` applies the same rule: with `?template=` it
  uses that template only if it is allowed, otherwise the first assigned one; with
  no assignment it behaves as before. The template is returned in
  `schema.template`.
- `PUT /{app}/api/group/{id}/layouts` (admin) sets the layouts of a group:
  `{"layouts": {"records": ["cataloguer", "reviewer"]}}`; `{}` clears them.
  Errors: `group_not_found`, `unknown_table`, `template_not_found`,
  `invalid_layouts`, `system_group`. `GET /api/group/{id}` returns `layouts`.
- The layout changes only what the record view receives. Lists, search, export,
  charts, maps and every other endpoint return the same data whatever the layout.
  A field a template marks `readonly` or `hidden`, or leaves out, can still be
  written (and read) through the API when the group's rules allow it.
- Developer notes: [Groups & permissions](/dev/groups-permissions#record-layouts-per-group).
