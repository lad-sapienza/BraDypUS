# OAuth2 / SSO authentication

BraDypUS supports OAuth2 Authorization Code flow for Google and ORCID.
Users still need an account in `bdus_users` — OAuth handles **authentication**
(who you are), BraDypUS handles **authorisation** (what you can do).

---

## How it works

```
User clicks "Sign in with Google"
  → Frontend calls GET /api/auth/oauth/google/redirect?app=APP&origin=ORIGIN
  → PHP returns { url: "https://accounts.google.com/o/oauth2/auth?..." }
  → Frontend navigates to that URL (window.location.href)
  → Google authenticates the user and redirects to:
      /api/auth/oauth/google/callback?app=APP&code=...&state=...
  → PHP verifies state, exchanges code, resolves user, issues JWT
  → PHP redirects browser to:
      {origin}/#/oauth-callback?token=JWT&app=APP   (success)
      {origin}/#/oauth-callback?error=CODE&app=APP  (failure)
  → Frontend stores the JWT and navigates home
```

The state token is HMAC-SHA256 signed with the app's JWT secret and carries a
10-minute TTL, preventing CSRF and replay attacks.

---

## Configuration

Add an `oauth` section to `projects/{app}/config.json`:

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
the user's email. Matching is therefore only possible by ORCID iD
(`oauth_sub`). An admin must set the `oauth_sub` field for each ORCID user
before they can log in via ORCID:

| Field           | Value                                          |
|-----------------|------------------------------------------------|
| `oauth_provider`| `orcid`                                        |
| `oauth_sub`     | The user's ORCID iD, e.g. `0000-0002-1825-0097`|

These fields are editable in the Users admin panel.

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
| `no_account`             | No BraDypUS account found for this identity              |
| `invalid_state`          | State token expired (> 10 min) or tampered               |
| `invalid_request`        | Missing required parameters                              |
| `provider_not_configured`| Provider credentials missing from `config.json`          |
| `oauth_error`            | Unexpected error during token exchange (check server log) |

---

## Security notes

- Redirect URIs **must** use HTTPS in production.
- `projects/{app}/config.json` must not be web-accessible (enforced by the
  `.htaccess` in `projects/{app}/cfg/` and the filesystem validation check).
- The state token is bound to the app, origin, and a random nonce, so it
  cannot be replayed across apps or origins.
- No OAuth tokens are stored server-side; only the BraDypUS JWT is issued.
