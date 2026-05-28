---
title: Tree structure of Bradypus
---

A simplified  overview of the main file and folders 
of which Bradypus is made of might be useful for the general 
comprehention of the entire system


```txt
.
├── LICENSE
├── README.md
├── UNSAFE_permit_app_creation
├── cache/                              -> Application cache: can be safetely empties
├── composer.json                       -> Composer file
├── composer.lock                       -> Composer lock file
├── css/                                -> Minified CSS files
├── css-less/                           -> LESS files, for development
├── docs/                               -> Few docs, to be migrated in this guide
├── fonts/                              -> Icon Fonts
├── img/                                -> System images
├── index.php                           -> Main enter point of the system
├── js/                                 -> Minified javascript files
├── js-sources/                         -> Plain javascript files for development
├── lib/                                -> System PHP libraries
├── locale/                             -> Locales folder containin system translations in different languages
├── logs/                               -> System logs
├── modules/                            -> System modules (PHP and Javascript)
├── node_modules/                       -> Node modules, for development
├── package-lock.json                   -> Node packages lock file
├── package.json                        -> Node packages file
├── projects/                           -> Directory containing projectsor applications
│   └── test/                           -> Test application home
│       ├── backups/                    -> Contains backups
│       ├── cfg/                        -> Contains application configuration JSON files
│       │   ├── app_data.json
│       │   ├── bibliography.json
│       │   ├── files.json
│       │   ├── geodata.json
│       │   ├── m_citations.json
│       │   ├── m_samples.json
│       │   ├── sites.json
│       │   ├── su.json
│       │   └── tables.json
│       ├── db/                         -> Contains SQLite database file
│       │   └── bdus.sqlite
│       ├── export/                     -> Contains exported files
│       ├── files/                      -> Contains uploaded files
│       ├── geodata/                    -> Contains GIS data to be loaded in GeoFace
│       ├── templates/                  -> Contains project-based template files
│       ├── tmp/                        -> Contains temporary files. It is safe to empty
│       └── welcome.html                -> Contains welcome page, can be edited within the application
├── sessions/                           -> Contains systemsession data
├── vendor/                             -> Third party PHP libraries installed via Composer
└── version                             -> Application version and change log
```