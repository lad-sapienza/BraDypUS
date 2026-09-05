---
title: OAuth2 / SSO
---

# OAuth2 / SSO authentication

BraDypUS supports OAuth2 Authorization Code flow for Google and ORCID.
OAuth handles **authentication** (who you are); BraDypUS handles
**authorisation** (what you can do) — but unlike earlier versions, a matching
account no longer has to already exist: see [Recovering from no_account](#recovering-from-no-account)
below.

---

## How it works

```
User clicks "Sign in with Google"
  → Frontend calls GET /api/auth/oauth/google/redirect?app=APP&origin=ORIGIN
  → PHP returns { url: "https://accounts.google.com/o/oauth2/auth?..." }
  → Frontend navigates to that URL (window.location.href)
  → Google authenticates the user and redirects to:
      /api/auth/oauth/google/callback?app=APP&code=...&state=...
  → PHP verifies state, exchanges code, resolves user
  → Match found:     issues a JWT, redirects to
                        {origin}/oauth-callback?token=JWT&app=APP
  → No match found:   redirects to
                        {origin}/oauth-callback?error=no_account&app=APP&pending=TOKEN
                      (see below — the frontend offers to link or self-signup)
  → Any other failure: {origin}/oauth-callback?error=CODE&app=APP
  → Frontend stores the JWT and navigates home
```

The state token is HMAC-SHA256 signed with the app's JWT secret and carries a
10-minute TTL, preventing CSRF and replay attacks. The `pending` token below
uses the same signing scheme and TTL.

---

## Configuration

The credentials live in an `oauth` section of `projects/{app}/config.json` —
the same file that holds the app's DB credentials, and read the same way
(`Controllers\OAuth::getCredentials()` reads the file directly on every
request, so a saved change takes effect immediately, no restart needed):

```json
{
  "name": "myapp",
  "db_engine": "sqlite",
  "oauth": {
    "google": {
      "client_id":     "YOUR_CLIENT_ID.apps.googleusercontent.com",
      "client_secret": "YOUR_CLIENT_SECRET"
    },
    "orcid": {
      "client_id":     "APP-XXXXXXXXXXXXXXXXXXXX",
      "client_secret": "YOUR_CLIENT_SECRET"
    }
  }
}
```

Only configure the providers you actually use — a provider is shown to users
only when both `client_id` and `client_secret` are non-empty.

**Editing the file by hand is no longer necessary.** A super-admin can set
both fields per provider from **Config → App settings → OAuth2 / SSO** — see
[App settings → OAuth2 / SSO](/guide/setup/main-app-config#oauth2-sso). The
panel also shows the exact **Redirect URI** to paste into the provider's
console for that app, computed from the current host — see
[Google setup](#google-setup) / [ORCID setup](#orcid-setup) below for where it
goes. `PUT /api/config/app` persists the section to `config.json` exactly as
shown above; editing the file directly still works and is useful for
scripted/bulk provisioning.

---

## Google setup

1. Go to [Google Cloud Console → Credentials](https://console.cloud.google.com/apis/credentials).
2. Create an **OAuth 2.0 Client ID** of type *Web application*.
3. Add to **Authorised redirect URIs**:
   ```
   https://your-host/api/auth/oauth/google/callback?app=YOUR_APP
   ```
4. Copy the Client ID and Client Secret into `config.json`.

**User lookup**: on first login BraDypUS matches by email address (auto-links
the Google identity to the existing account). On subsequent logins it matches
by `(oauth_provider, oauth_sub)`.

---

## ORCID setup

1. Go to [ORCID Developer Tools](https://orcid.org/developer-tools) (requires
   an ORCID account).
2. Register a new application with redirect URI:
   ```
   https://your-host/api/auth/oauth/orcid/callback?app=YOUR_APP
   ```
3. Copy the Client ID (`APP-…`) and Client Secret into `config.json`.

**User lookup**: ORCID's public API (`/authenticate` scope) does not expose
the user's email, so it never gets the Google-style auto-link-by-email. On
first login it always falls through to the `no_account` recovery flow below
— an admin no longer needs to pre-set `oauth_sub` by hand, though the fields
below remain editable in the Users admin panel for anyone who wants to link
an identity that way instead:

| Field           | Value                                          |
|-----------------|------------------------------------------------|
| `oauth_provider`| `orcid`                                        |
| `oauth_sub`     | The user's ORCID iD, e.g. `0000-0002-1825-0097`|

---

## Recovering from no_account

When `resolveUser()` finds no match — always the case for a first-time ORCID
user, or any genuinely new user on either provider — the callback doesn't
dead-end. It signs a short-lived (~10 min) `pending` token carrying
`{provider, sub, name, app}` and redirects to:

```
{origin}/oauth-callback?error=no_account&app=APP&pending=TOKEN
```

The frontend ([`OAuthCallbackView.vue`](https://github.com/lad-sapienza/BraDypUS/blob/v5/bdus-app/src/views/OAuthCallbackView.vue))
then offers two ways to redeem that token:

**Link to an existing account** — `POST /api/auth/oauth/link`
`{ app, pending, email, password }`. Verifies the password against an
existing account (reusing `Login::authenticate()`, so the same anti-brute-force
throttling applies) and, on success, sets `oauth_provider`/`oauth_sub` on that
row. A password is required here specifically because a self-reported email
alone proves nothing — anyone who completes any OAuth flow could type someone
else's address.

**Self-signup** — `POST /api/auth/oauth/register`
`{ app, pending, email }`. Only an email is asked for (the provider already
proved the identity — ORCID just doesn't hand one back). Same
`allow_self_registration` + `Mail\Mailer::isConfigured()` gate and
privilege-40 ("waiting") outcome as `POST /api/auth/register` — see
[Self-registration](/guide/usage/authentication#self-registration). The new
row gets an inert, unguessable password hash: the account is OAuth-only
unless a future password reset ever sets a real one.

The `pending` token itself is stateless (just signed, not tracked server-side),
so it can be retried within its TTL — a wrong password on `link` doesn't burn
it. What *is* enforced is the identity itself: once `(oauth_provider, oauth_sub)`
is actually written to one row, any further `link`/`register` call for that
same identity fails with `oauth_identity_already_linked` (the unique index
from M022, see below).

---

## Database columns

M022 (applied automatically on first login after upgrade) adds two nullable
columns to `bdus_users`:

| Column           | Type | Description                            |
|------------------|------|----------------------------------------|
| `oauth_provider` | TEXT | Provider slug: `google` \| `orcid`     |
| `oauth_sub`      | TEXT | Provider-issued unique subject ID      |

A partial unique index on `(oauth_provider, oauth_sub) WHERE oauth_sub IS NOT NULL`
prevents two accounts from being linked to the same external identity.

Existing password-based accounts are not affected: both columns remain `NULL`
until the user first logs in via OAuth.

---

## Error codes

The frontend receives one of these `?error=` values on callback failure:

| Code                     | Meaning                                                  |
|--------------------------|----------------------------------------------------------|
| `no_account`             | No BraDypUS account found for this identity — comes with a `pending` token, see above |
| `invalid_state`          | State token expired (> 10 min) or tampered               |
| `invalid_request`        | Missing required parameters                              |
| `provider_not_configured`| Provider credentials missing from `config.json`          |
| `oauth_error`            | Unexpected error during token exchange (check server log) |

`POST /api/auth/oauth/link` and `POST /api/auth/oauth/register` return their
own `code` in the JSON body (200 status either way, `status: "error"` on
failure) rather than a redirect:

| Code                             | Meaning                                                        |
|----------------------------------|-----------------------------------------------------------------|
| `invalid_or_expired_pending`     | `pending` token missing, tampered, or past its ~10 min TTL       |
| `login_data_not_valid`           | (`link`) Wrong email/password                                   |
| `account_locked`                 | (`link`) Anti-brute-force lock active — see [Too many failed attempts](/guide/usage/authentication#too-many-failed-attempts) |
| `oauth_identity_already_linked`  | This `(provider, sub)` pair is already on a different row       |
| `registration_not_available`     | (`register`) `allow_self_registration` is off for this app      |
| `email_not_configured`           | (`register`) Mail\Mailer::isConfigured() is false                 |
| `email_present`                  | (`register`) An account already has this email                  |

---

## Security notes

- Redirect URIs **must** use HTTPS in production.
- `projects/{app}/config.json` must not be web-accessible (enforced by the
  `.htaccess` in `projects/{app}/cfg/` and the filesystem validation check).
- The state token is bound to the app, origin, and a random nonce, so it
  cannot be replayed across apps or origins.
- No OAuth tokens are stored server-side; only the BraDypUS JWT is issued.
