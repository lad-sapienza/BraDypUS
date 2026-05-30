---
title: App settings
---

# App settings

The **App settings** panel controls the global properties of your application.
Open it from **Config → App settings** (the first panel in the sidebar).

![TODO_SCREENSHOT: App settings panel with all form fields visible](/images/v5/setup/app-settings.png)

## Fields

| Field | Description |
|---|---|
| **Application name** | Short identifier used internally (read-only after creation) |
| **Application label** | Human-readable display name shown in the navigation header |
| **Definition** | Brief description of the application's purpose |
| **Language** | Default UI language (`en` or `it`) |
| **Status** | `on` = fully active; `off` = read-only for non-admins; `freeze` = no writes at all |
| **DB engine** | `sqlite`, `mysql`, or `pgsql` (set at creation, not changeable here) |
| **Max image size** | If set, uploaded images are automatically downscaled to fit within this pixel bound |

## Status values

- **`on`** — normal operation; all users with the right privilege can read and write.
- **`off`** — only admins and super-admins can write; other users see data in read-only mode.
- **`freeze`** — the entire application is read-only for everyone, including admins.

Use `freeze` before performing a backup or migration.

## Appearance

The **Appearance** section lets an administrator pick the application's
primary colour. The change takes effect immediately (live preview) and is
applied to every user the next time they load the app.

Eight palette options are available — all tested in both light and dark mode:

| Swatch | Name |
|---|---|
| 🟣 | Indigo (default) |
| 🔵 | Blue |
| 🟣 | Violet |
| 🟢 | Emerald |
| 🩵 | Teal |
| 🟡 | Amber |
| 🌹 | Rose |
| ⬜ | Slate |

The selection is stored in `bdus_cfg_app.color` (added by migration M025)
and is read at login time from `GET /api/info/app`.

## Saving

Click **Save** to apply changes. A toast notification confirms success or shows
the error detail if validation fails.
