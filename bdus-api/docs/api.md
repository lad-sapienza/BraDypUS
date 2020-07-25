# BraDypUS API

BraDypUS has a JSON API that permits users to remotely query the database,
extract data or get information about the database structure.

Currently (v.3.x.x) two versions of the API are available,
the most stable [v.1](api-v1) and the most recent [v.2](api-v2).

Version 1 has been **deprecated** and receives no active development. 
Only patches and security fixes are being pushed.

On the other hand the version 2, introduced in bdus v.3.13.0 
is in a very active development state and some breaking changes 
might occur. It is being developed with security and ease of use in mind. 
The most relevant feature is the introduction of [ShortSql](ShortSql) 
query language that is parsed in SQL after being thoroghly validated.

If you are planing to start a new application **do not use v1** but
**start experimenting with v2**. If important features are missing,
file an issue!