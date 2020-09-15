# Some advices and hacks

Follow here some advices regarding the configuration of the database
that might help in solving practical issues. Please feel free to contribute,
and to share your experience.

## ID field
As already reported multiple times each table of a Bradypus application,
be it a system table, a plugin or a data table has a special column named
always `id` which acts as the Primary Key. Its compilation is entrusted to 
the database engine, and the user should never interfere.

It is thus warmly recommended to set the `readonly` setting to 1, and if
its value is not important to the general usage of the database, from
the users point of view, also set `hide` to 1. This will avoid unpleasant
errors on data entry.

## Creator
The same can be said for column `creator`, as well, which is available only
in data tables and not in plugin or system tables.

## Adding a Last edited field
It is easy to add and configure a field wich holds the timestamp of the latest
edit. Just add a field named `lastedit` (or whatever name you prefer) with 
the following setting:
- def_value: %today%
- force_default: 1
- readonly: 1

Or optionally:
- hide: 1

## Adding a Last edited by field
It is easy to add and configure a field wich holds the id of the latest
user who edited it. Just add a field named `lasteditby` (or whatever name you prefer) 
with the following setting:
- def_value: %current_user%
- force_default: 1
- readonly: 1

Or optionally:
- hide: 1