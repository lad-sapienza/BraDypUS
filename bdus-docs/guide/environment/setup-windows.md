---
title: Setup on Windows
---

# Setup on Windows

## Install Docker Desktop

Download and install [Docker Desktop for Windows](https://www.docker.com/products/docker-desktop/).
It requires Windows 10/11 with WSL 2 (Windows Subsystem for Linux).

Enable WSL 2 if not already done:

```powershell
wsl --install
```

Then install Docker Desktop and verify in PowerShell:

```powershell
docker --version
docker compose version
```

## Install Git

Download [Git for Windows](https://git-scm.com/download/win) and install it.
This also installs **Git Bash**, a Unix-like terminal useful for running
shell scripts.

Verify in Git Bash or PowerShell:

```bash
git --version
```

## Clone and run

Open Git Bash and follow the [installation guide](/guide/install/terminal).
