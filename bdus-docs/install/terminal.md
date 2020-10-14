---
title: Installing with the terminal and git
---

To install Bradypus with the terminal and git you need:
- a connection to the Internet
- [Git](https://git-scm.com/)
- a terminal / command line

Typically these dependecies are met by default on 
Linux/Unix (included MacOS X) systems.
Since the Anniversary Update also on Windows 10 
it is posibble to 
[enable the Bash shell of Ubuntu Linux](https://stackoverflow.com/questions/36352627/how-to-enable-bash-in-windows-10-developer-preview).

## TL;DR

```bash
git clone -b dev --single-branch https://github.com/bdus-db/BraDypUS.git && \
cd BraDypUS && \
mkdir projects && \
php -S localhost:8000
```

## Installation

Before installation, you want probably to [check your local environment](#verify-the-local-environment). 
If you already did, we are ready to go.

1. Get files from remote repository using git
    ```bash
    git clone -b dev --single-branch https://github.com/bdus-db/BraDypUS.git
    ```
    Git will create a new directory named BraDypUS with all the need files. In details:
    - `-b dev` means that the **dev**, i.e. the development branch will be retrived. Version 4 lives here.
    - `--single-branch` will prevent git from cloning everything, only the requeste branch will be downloaded

2. Move inside the newly created directory named BraDypUS
    ```bash
    cd ../BraDypUS
    ```
3. Make projects folder
    ```bash
    mkdir projects
    ```

4. Start PHP's web server (if no local web server is available). Done!
    ```bash
    php -S localhost:8000
    ```
To stop the PHP's web server type `CRL+C`.

Now you are ready to create your first web database application. 
Open the browser and go to: 
[http://localhost:8000/](http://localhost:8000/)


## Uninstalling

By removing the BraDypus directory created by the git command will remove all the installed packages.
Bradypus will not copy or install files in your system.

## Verify the local environment

Check if php is installed. Open a terminal and type:

```bash
php -v
```

You should see something like (version might be different, 
but please make sure it is equalror bigger than 7.0):

```bash
PHP 7.3.11 (cli) (built: Feb 29 2020 02:50:36) ( NTS )
Copyright (c) 1997-2018 The PHP Group
Zend Engine v3.3.11, Copyright (c) 1998-2018 Zend Technologies
```

Check if php_pdo for sqlite is available
```bash
php -i | grep pdo_sqlite 
```

You should read something like:

```bash
pdo_sqlite
```

If you are going to use MySQL or PostgreSQL, you could test using:
```bash
php -i | grep pdo_mysql 
```
or 
```bash
php -i | grep pdo_pgsql 
```

Check if git is available
```bash
git --version
```

You should read something like:

```bash
git version 2.24.2 (Apple Git-127)
```