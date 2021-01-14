---
title: System requirements
---

To run Bradypus locally, for development and testing purposes, 
a web server and php are needed. Indeed, PHP is the only required
dependency, since PHP comes with a internal server wich, while is not
recommended for production, if more than sufficient for development and testing.


### Dependecy details:
- [PHP](https://www.php.net/) (>=7.x)
    - [php-pdo](https://www.php.net/manual/en/book.pdo.php)
    - [php-sqlite3](https://www.php.net/manual/en/book.sqlite3.php), if Sqlite will be used as database engine
    - [php-gd](https://www.php.net/manual/en/book.image.php), for basic image manipulation (resize)
- A web server with a PHP hadler. For development purposes the 
[PHP built-in web server](https://www.php.net/manual/en/features.commandline.webserver.php)
may be used (`php -S localhost:8000`).
    - [Apache](https://httpd.apache.org/), or
    - [Mongoose](https://github.com/cesanta/mongoose), or
    - [Nginx](https://www.nginx.com/) (not tested), etc.
- A database, eg. 
    - [SQLite 3](https://www.sqlite.org/index.html). Sqlite will be used in this guide
    - [MariaDB](http://go.mariadb.com/), or [MySQL](https://www.mysql.com) or [Percona](https://www.percona.com/)
    - [PostgreSQL](https://www.postgresql.org/) (>= 12.0.0)
- An interface to the database, be it [CLI](https://en.wikipedia.org/wiki/Command-line_interface) 
or [GUI](https://en.wikipedia.org/wiki/Graphical_user_interface) 
to be used to create and edit the database structure, eg.
    - [SQLiteStudio](https://sqlitestudio.pl/) for Sqlite, or
    - [phpmyadmin](https://www.phpmyadmin.net/) for MySQL, MariaDB, Percona, etc.
- A text editor or [IDE](https://en.wikipedia.org/wiki/Integrated_development_environment) 
usefull to comfortably edit the configuration files
    - [VisualStudio Code](https://code.visualstudio.com/)
    - [Atom](https://atom.io/)
    - [SublimeText](https://www.sublimetext.com/)
    - [Notepad++](https://notepad-plus-plus.org/downloads/)
    - ecc.
- [Git](https://git-scm.com/), might be very usefull for the installationa and udating of the
entire system
- A Terminal, that makes installationa and configuration really fast.