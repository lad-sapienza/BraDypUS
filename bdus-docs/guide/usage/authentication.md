---
title: Login & authentication
---

# Login & authentication

The login screen ([`/login`](/)) is the entry point to every BraDypUS
application hosted on an instance. Pick an application from the **Application**
dropdown, then sign in with email + password, or with a configured SSO
provider.

![Login form with email and password fields, and the Forgot password? / Create account links below the Login button](/images/v5/usage/login-auth-links.png)

## Single sign-on

If an administrator has configured Google or ORCID for the selected
application, a matching button appears below the login form. Signing in with
SSO the first time links that identity to your BraDypUS account by email
(Google) or requires an admin to have already linked it (ORCID, which doesn't
expose an email address).

## Forgot password <Badge type="tip" text="v5.6.0" />

The **Forgot password?** link only appears when the instance has email
sending configured (see [Deploy → environment variables](/guide/deploy/)) —
on an installation that hasn't set this up, the link is hidden rather than
leading to a broken form.

![Reset your password panel with an email field and Send reset link button](/images/v5/usage/forgot-password-form.png)

1. Click **Forgot password?**, enter your email, and click **Send reset link**.
2. You always see the same confirmation message, whether or not that email
   matches an account — this is deliberate, so the form can't be used to
   check which addresses are registered.
3. If it matches, an email arrives with a reset link, valid for **one hour**
   and usable only once.
4. Opening the link takes you to a page to choose a new password:

![Choose a new password page with two password fields and a Reset password button](/images/v5/usage/reset-password-page.png)

Successfully resetting your password also lifts any temporary lock from too
many failed login attempts (see [below](#too-many-failed-attempts)) — proving
you own the account this way is just as good as a correct password.

## Self-registration <Badge type="tip" text="v5.6.0" />

The **Create account** link, like **Forgot password?**, only appears when
email sending is configured — and additionally only when an administrator has
turned it on for that specific application (**Config → App settings → Allow
self-registration**, off by default; see
[App settings → Access](/guide/setup/main-app-config#access)).

![Create account form with name, email, password and confirm password fields](/images/v5/usage/register-form.png)

Filling in the form creates an account immediately, but with the **Pending**
privilege level (see [Users & privileges](/guide/setup/users#privilege-levels))
— it has no access to anything until an administrator reviews it and raises
the privilege. Both you and every admin get a confirmation email.

## Too many failed attempts <Badge type="tip" text="v5.5.0" />

After 5 consecutive failed login attempts, an account is locked for 15
minutes — the login form shows a message instead of attempting the password
check. This is per-account, not per-IP, and resets on the next successful
login (or password reset). It's a second line of defense in addition to any
rate-limiting configured on your reverse proxy.
