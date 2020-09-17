---
title: Anatomy of the newly created application
---

Once the new application has been succesfully created a similar report will
be print, giving credit for any single operation:

```txt
Directory projects/test/backups created
Directory projects/test/cfg created
Directory projects/test/export created
Directory projects/test/files created
Directory projects/test/geodata created
Directory projects/test/templates created
Directory projects/test/tmp created
Table charts created
Table files created
Table geodata created
Table log created
Table queries created
Table rs created
Table userlinks created
Table users created
Table versions created
Table vocabularies created
User data added
Configuration file projects/cfg/test/app_data.json created!
Configuration file projects/cfg/test/tables.json created!
Configuration file projects/cfg/test/geodata.json created!
Configuration file projects/cfg/test/files.json created!
Welcome page created!
```

Also, the `projects` folder will have the following structure (bdus.sqlite file will not
be created for MySQL or PostgreSQL engines):

```txt
projects
└── test
    ├── backups
    ├── cfg
    │   ├── app_data.json
    │   ├── files.json
    │   ├── geodata.json
    │   └── tables.json
    ├── db
    │   └── bdus.sqlite
    ├── export
    ├── files
    ├── geodata
    ├── templates
    ├── tmp
    └── welcome.html
```