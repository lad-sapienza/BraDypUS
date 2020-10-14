---
title: Some conventions
---

Bradypus is a [relational system](https://en.wikipedia.org/wiki/Relational_database) 
which means that at its base there are **tables**.

Each table is made up of a number of **columns**, 
each of them has its own settings and costraints.

## Application name
Bradypus is a multi-project system, it means that on the same 
installation many applications can coexist, each being fully isolted and self-contained.

{: .callout-block .callout-block-warning }
Pay attention: when designing multi-app system based on MySQL or PostgreSQL, 
make sure to create a separate db user for each application. This will grant full
isolation between different projects.

The Application name is the unique identification name for each application,
and must be unique in each installation. Please use a string matching the following requisites:
- lower-case letters
- no numbers, no diacritics, no dashes, slashes, whitespaces
- a minimum of 3 and a a maximum of 7 characters

it means that the application name nust match the following regular expression:
`^[a-z]{3,7}$`.

In this guide **test** will be used as application name.

## Prefix

It is a legacy constraint of Bradypus to define a prefix to append
to all database tables, be them system or data tables (see below).

At present the user cannot choose a custom prefix, since it is formed
by the `application name` followed by `double underscore`.

For our application named `test` the prefix will thus be `test__`.

## System tables

Bradypus includes **system tables**, whose structure cannot be 
changed. The system tables serve to the overall management of the system,
and are the following (the prefix is ommitted):
- **charts** : manages saved charts
- **files** : manages uploaded files
- **geodata**: manages geometries and GIS data linked to records
- **log**: manages system logs
- **queries**: manages saved queries
- **rs**: manages stratigraphic relationships
- **userlinks**: manages manually set links between records
- **users**: manages users
- **versions**: manages record prevuois version
- **vocabularies**: manages vocabularies and vocabulary items

## Data tables
Data tables are defined by users to meet their needs of data storage, management and query. 

Their structure and relations depends on the research needs and can be easily
updated anytime by a super-admin user, within Bradypus.

The data tables can be of two types, `regular` and `plugin`. 
`Regular` tables are application entities and contain records, while `plugin` tables
are ancillary tables, usually linked in a `1-many` relationship with a `regular` table.
`Plugin` tables do not live on their own and fully depend on one or many `regular` tables.

Tra le tabelle di dati alcune sono di secondo ordine e vengono chiamate `plugin` e contengono dati aggiuntivi
relativamente a una tabella di dati principale.

Nel nostro caso, la tabella dei campioni è una tabella `plugin`.

### Data tables name
Regular data tables are named using the prefix followed by a lower-case string 
of ASCII, with no spaces, dashes, etc.

The following are valid regular table names for application test:
- **test__sites**
- **test__bibliografy**
- **test__strigraphicunits**

The following are not valid:
- **test__Sites** (no upper-case)
- **sites** (missing prefix)
- **test__strigraphic units** (no spaces)
- **test__strigraphic_units** (no underscores)

Plugin tables include a `m_` between the prefix and the proper name.
This is not strictly needed for the application to work properly, 
but it is strongly recomended, because it provides a promt visual
distinction between regular and plugin tables.

The following are valid plugin table names for application test:
- **test__m_biblio**
- **test__m_samples**

The following are not valid:
- **test__m_Biblio** (no upper-case)
- **m_sites** (missing prefix)
- **test__biblio** (missing m_)

### Data tables structure
As already mentioned the structure of data tables depends on the user need,
yet some few columns are must always be implemented, both for regular and plugin tables.

When the tables are added via the system configuration manager, these columns are automatically set
and can not be deleted.

Required for both **regular and plugin** tables:
- `id`: the column must be of type `INT` (integer), must be set to be a 
primary key and must be set to be auto-incremental., and forced not to receive null values.

Required for **regular** tables:
- `creator`: the column must be of type `INT` (integer) and forced not to receive null values.

Required for **plugin** tables:
- `table_link`: the column must be of type `TEXT` and forced not to receive null values.
- `id_link`: the column must be of type `INT` (integer) and forced not to receive null values.

These two columns contain Foreign Keys to regular tables, in order to keep records links.
No `ON UPDATE`, `ON DELETE`, ecc. policy is needed to be defined in the database, since Bradypus will
take care of the correct reference actions. Yet, FK policies can be stored in the database engine.
