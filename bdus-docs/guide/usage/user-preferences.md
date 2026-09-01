---
title: User preferences
---

# User preferences

BraDypUS stores a small set of per-user preferences client-side (browser
`sessionStorage` per tab — preferences are per-session, not persistent across
browser restarts).

## Dark mode

Click the **☀ / ☾** toggle in the top-right corner to switch between light and
dark themes. The preference is saved in `localStorage` and persists across sessions.

## Column visibility

In DataView, the **Columns** button in the toolbar opens a popover where you can
show or hide individual columns. The selected columns are remembered for the
current session.

## Language

See [Interface language](/guide/usage/system-translation).

## Per-tab isolation

Each browser tab runs a completely independent session with its own JWT token.
This means you can be logged into different applications simultaneously in
different tabs.
