---
title: Uninstalling BraDypUS
---

# Uninstalling BraDypUS

## Remove the Docker containers and images

```bash
docker compose down --volumes --remove-orphans
docker rmi bdus-api bdus-app 2>/dev/null || true
```

## Remove application data

::: danger Irreversible
This permanently deletes all your databases and uploaded files.
Download a backup first if you need to preserve the data.
:::

```bash
rm -rf bdus-api/projects/
```

## Remove the code

```bash
cd ..
rm -rf BraDypUS/
```
