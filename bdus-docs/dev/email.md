---
title: Email / Resend
---

# Email (Resend)

BraDypUS sends two kinds of transactional emails — password reset and
self-registration confirmation — through [Resend](https://resend.com), a
transactional email API. There is no SMTP option and no local mail server
support: `Mail\Mailer` talks to the Resend HTTP API directly.

---

## How it works

```
Mail\Mailer::isConfigured()
  → true only when RESEND_API_KEY and MAIL_FROM_ADDRESS are both set

Mail\Mailer::send($to, $subject, $html)
  → POSTs to https://api.resend.com/emails
  → throws MailException on any network/HTTP error
```

BraDypUS is multi-tenant: **one Resend account/API key covers every app
hosted on the instance** — set once via environment variables, not per-app
config (the same reasoning as one PHP/DB backend serving many apps). A
deployer who doesn't want to run email just leaves the key unset; the
features that need it then report themselves as unavailable instead of
silently failing.

Two callers use `Mail\Mailer::send()`, both in `Controllers\Login`:

| Flow | Trigger | Templates (`Mail\Templates`) |
|---|---|---|
| Self-registration | `POST /api/auth/register` (only when `allow_self_registration` is on for the app — see [App settings](/guide/setup/main-app-config#access)) | `registrationConfirmation()` (to the new user) + `registrationAdminNotice()` (to every admin/super-admin) |
| Password reset | `POST /api/auth/password-reset/request` | `passwordReset()` |

Email language is picked per-request from the `Accept-Language` header
(`Templates::langFromHeader()`) — it is **not** the app's configured default
language (see [Config & UAC](/dev/config)) or the recipient's own locale
preference, since neither is known at the point these emails are sent.

---

## Configuration

Set these environment variables on the `bdus-api` container (or the host PHP
process) — see [Deploy → environment variables](/guide/deploy/) for how to
pass them through Docker Compose:

| Variable | Required | Description |
|---|---|---|
| `RESEND_API_KEY` | Yes | API key from your Resend account. Unset = email features report as unavailable. |
| `MAIL_FROM_ADDRESS` | Yes | Sender address. Must be on a domain verified in Resend. |
| `MAIL_FROM_NAME` | No (default `BraDypUS`) | Sender display name. |

There is no per-app configuration: every app on the instance shares the same
sender identity and quota.

## Setup

1. Create a [Resend](https://resend.com) account and verify a sending domain
   (Resend walks you through the DNS records).
2. Create an API key in the Resend dashboard.
3. Set `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` (an address on the verified
   domain), and optionally `MAIL_FROM_NAME` on the `bdus-api` container.
4. Restart `bdus-api` so the new environment variables are picked up.

No restart of individual apps is needed — `Mail\Mailer::isConfigured()` reads
the environment on every call.

## What's gated on this

- **Self-registration** — the *Allow self-registration* toggle in
  [App settings](/guide/setup/main-app-config#access) is disabled, with an
  explanatory note, whenever `Mail\Mailer::isConfigured()` is false: turning
  registration on without a way to email the new user (and notify admins)
  would silently strand accounts in the **Pending** privilege level forever.
- **Password reset** — `POST /api/auth/password-reset/request` returns
  `email_not_configured` instead of sending anything.

Both checks happen server-side on every request — there is no cached
"email is configured" state to go stale.

---

## Security notes

- The Resend API key is a single instance-wide secret — treat it like any
  other credential in `docker-compose.yml`/`.env`, not like the per-app OAuth
  or DB credentials in `projects/{app}/config.json`.
- `Mail\Mailer::send()` never logs the API key; a `MailException` message is
  safe to write to the application log but was never meant to be shown to the
  end user verbatim (callers translate it to a generic error code).
- Password-reset tokens are single-use and time-limited — see
  `Controllers\Login::requestPasswordReset()`/`confirmPasswordReset()` and the
  `reset_token_hash`/`reset_token_expires` columns added by M040.
