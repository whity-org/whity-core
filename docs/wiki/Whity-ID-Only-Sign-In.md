# Sign in only with an identity provider

A server can sign everyone in and up through its identity provider and nothing else. app.whity.dev does this with Whity ID. The setting is `auth.password_sign_in`. It is **on by default**: a self-hosted Whity keeps its own sign-in unless you turn this off ([ADR 0019](../adr/0019-sign-in-and-email-stay-in-core.md)).

## What changes when it's off

| Before | Off |
|---|---|
| Sign-in page: identity provider buttons, then email and password | Only the identity provider buttons |
| Sign-up page: email, password and workspace name | Only the identity provider buttons; the workspace is made after the first sign-in |
| Forgot password, two-step recovery | Refused: the provider handles the person's credentials |
| Turning on two-step sign-in in Whity | Refused: the provider does it (Whity ID asks for a passkey or its own authenticator) |
| Changing email or password in Whity | Refused: change them at the provider |
| Accepting an invitation: choose a password | "Continue with …": the invitation is accepted for the address it was sent to, and the person lands in the workspace |
| Admin "send a password reset link" | Refused |
| A workspace's join page (`/join/<workspace>`): provider buttons or email | Only the provider buttons |

These keep working:

- **Sessions:** people who are signed in stay signed in.
- **Tokens and credentials:** API tokens, MCP tokens, agent credentials and desktop device credentials.

Every refused call answers `403` with `details.code = "password_sign_in_off"`.

## Before you turn it off

1. **Provider:** turn on an identity provider for the whole server (Settings › Sign-in & SSO, workspace 0).
2. **Your own sign-in:** sign in with it once yourself. The setting refuses to turn off until both are true, so you can't lock yourself out.
3. **New people:** to give a new person their own workspace on first sign-in, turn on `auth.sso_self_provision_workspace`. Otherwise new people need an invitation.
4. **Existing people:** they are linked on their first sign-in when the provider's **verified** email matches their address here. An unverified email is never linked. Check that everyone who signs in today has an account at the provider with the same address.

Then turn **Password sign-in** off in Settings › Sign-in & SSO, or run:

```bash
whity-cli auth password-sign-in off
```

## Break-glass: when the provider is down

Either of these brings password sign-in back:

```bash
whity-cli auth password-sign-in on
```

- **The command** turns the setting back on until you turn it off again.
- **The environment variable** `WHITY_PASSWORD_SIGN_IN=force` keeps password sign-in on whatever the setting says. It's for when you can't reach the database from a shell. Remove it afterwards.

`whity-cli auth password-sign-in status` shows both.

Accounts that only ever signed in through the provider have no password. The break-glass helps accounts that still have one, such as the operator account made at install.

## Sign-up links with a plugin

A link like `/register?plan=free&plugin=salons` keeps working. The sign-up page remembers the plugin and sends the person to the provider. After they create their workspace on the welcome step, the plugin's trial starts and its setup opens, as it does after registering with a password.
