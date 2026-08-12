---
title: Application log
---

# Application log

The **Application log** shows internal events and errors recorded by BraDypUS
at runtime. Open it from the sidebar (admin privilege required).

![TODO_SCREENSHOT: LogView showing a table of log entries with level badges and expandable rows](/images/v5/usage/log.png)

## Reading log entries

Each row shows the timestamp, log level, channel, and a one-line preview of the
message. Click the expand arrow (▸) to see the full message, including stack
traces and any extra context.

### Log levels

BraDypUS uses [Monolog](https://github.com/Seldaek/monolog) severity levels:

| Level | Meaning |
|---|---|
| DEBUG | Detailed diagnostic information |
| INFO | Normal operational events (e.g. user login) |
| NOTICE | Significant but expected events |
| WARNING | Unexpected situations that do not stop execution |
| ERROR | Runtime errors (e.g. a failed DB query) |
| CRITICAL | Component unavailable, action required |
| ALERT | Immediate action required |
| EMERGENCY | System is unusable |

## Filtering

- **Level filter** — show only entries at or above a chosen severity.
- **Search** — free-text filter on the message content.

Click **Refresh** to reload the list from the top.

## Purging old entries

Click **Purge** to delete entries older than a chosen threshold (1 / 7 / 14 / 30 /
90 / 365 days). A confirmation dialog shows before any data is deleted. A toast
reports how many rows were removed.

::: tip When to check the log
Check the log after any unusual behaviour, failed import, or backup error. The
ERROR and CRITICAL entries are the most actionable; DEBUG and INFO can be safely
ignored in normal operation.
:::
