---
title: Setup on macOS / Linux
---

# Setup on macOS / Linux

## Install Docker Desktop

Download and install [Docker Desktop](https://www.docker.com/products/docker-desktop/).
On macOS, the easiest way is via Homebrew:

```bash
brew install --cask docker
```

Start Docker Desktop from the Applications folder or launchpad, then verify:

```bash
docker --version
docker compose version
```

## Install Git

macOS and most Linux distributions include Git. Verify with:

```bash
git --version
```

If not present:
```bash
# macOS
brew install git

# Debian/Ubuntu
sudo apt install git
```

## That's it

With Docker and Git you have everything needed to run BraDypUS.
Follow the [installation guide](/guide/install/terminal).
