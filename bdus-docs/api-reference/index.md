---
title: API Reference
---

# API Reference

The full REST API is documented in OpenAPI 3.x format and rendered interactively below.
The spec is fetched from [bdus-api/openapi.yaml](https://github.com/lad-sapienza/BraDypUS/blob/v5/bdus-api/openapi.yaml) at build time.

<ClientOnly>
  <ScalarApiRef />
</ClientOnly>

::: info Running locally?
The spec file `public/openapi.yaml` is fetched by CI. To view the API reference locally, run:

```bash
curl -fsSL https://raw.githubusercontent.com/lad-sapienza/BraDypUS/v5/bdus-api/openapi.yaml \
  -o public/openapi.yaml
```
:::
