---
title: Setup environment in MacOS / Linux / Unix
---

Normally *nix operating systems (Linux, MacOS, Unix) come with a
default installation of PHP and often also a web server (like Apache).

To verify that PHP is available, open a Terminal / Command Line and type:
```bash
which php
``` 
and then type Enter. Something similar to the following should appear in the terminal
```bash
/usr/bin/php
```
This is the full path pf the PHP executable.

To check the version of the installed PHP, type in the Terminal: 
```bash
php -v
```
You will get an output similar to the following:
```bash
PHP 7.3.11 (cli) (built: Jul  5 2020 03:23:39) ( NTS )
Copyright (c) 1997-2018 The PHP Group
Zend Engine v3.3.11, Copyright (c) 1998-2018 Zend Technologies
```

As in Windows, integrated solutions are also available, such as:
- [XAMPP](https://www.apachefriends.org/)
- [WAMP](https://www.mamp.info/)
- [Mongoose](https://github.com/cesanta/mongoose)