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
2. **Your own sign-in:** sign in with it once yourself.
3. **Every platform operator:** everyone who is an active member of the system workspace needs their provider account linked. Each of them links it in **Settings › Security › Sign-in accounts** (see [Linking while signed in](#linking-while-signed-in)). The CLI's own service account doesn't count: it never signs in.
4. **New people:** to give a new person their own workspace on first sign-in, turn on `auth.sso_self_provision_workspace`. Otherwise new people need an invitation.
5. **Existing people:** they are linked on their first sign-in when the provider's **verified** email matches their address here. An unverified email is never linked. If their provider address is different, they link it themselves from Settings › Security while they can still sign in.

Check who is still missing a link:

```bash
whity-cli auth password-sign-in status
```

It shows the setting, whether the break-glass variable is set, and lists every platform operator with no provider linked.

Then turn **Password sign-in** off in Settings › Sign-in & SSO, or run:

```bash
whity-cli auth password-sign-in off
```

Both refuse until steps 1 to 3 are done, so nobody who runs the server is locked out. The refusal names the operators who have no link.

`--force` turns it off anyway, with the operators unlinked. It prints a warning naming them, and the audit log records `auth.password_sign_in.forced_off`. Use it only when you will give those operators a one-time link straight away (below). The settings screen has no force: use the CLI.

```bash
whity-cli auth password-sign-in off --force
```

## Linking while signed in

**Settings › Security › Sign-in accounts** lists the provider accounts linked to you and has a **Link Whity ID** button (one per provider the server has).

- The provider asks you to sign in. The account you pick is linked to the one you're signed in to here, **whatever its email**. A first sign-in links only on a matching verified email; this is the way to link one that doesn't match.
- An account already linked to somebody else here is refused.
- One account per provider: remove the old one first to link a different one.
- With password sign-in off, you can't remove your last link to the server's provider: it is your only way in.
- Every link is in the audit log as `auth.identity.linked`.

A session that a workspace's own provider signed in can't link: it never reaches the person's account on the server.

## When an operator can't sign in

An operator with no provider linked can't sign in while password sign-in is off. On the server, print a one-time sign-in link for them:

```bash
whity-cli auth operator-link amro@example.com
```

- **Who:** only an active member of the system workspace with no provider linked. Anyone else is refused.
- **How long:** the link works **once**, for **15 minutes**.
- **What it does:** opens the link's page, where the operator presses **Sign in**. They get a session in the system workspace and land on Settings › Security to link Whity ID. From then on they sign in with Whity ID.
- **Audit:** printing the link (`auth.operator_link.issued`), using it (`auth.operator_link.used`) and every refusal (`auth.operator_link.failed`) are in the audit log.
- **Keep it private:** anyone holding the link can use it while it is valid. Send it only to that operator, over a channel you trust. The token sits after `#` in the address, so it never reaches a server log, and it is sent only when the button is pressed, so a chat app previewing it can't use it up.

Why a link from the shell, rather than keeping password sign-in for operators: running the command needs a shell on the server, which already holds every secret, so the link adds no new way in for anybody who doesn't have one. Keeping passwords for operators would leave the public sign-in form open on the most powerful accounts for as long as one of them stays unlinked.

## Break-glass: when the provider is down

When the provider itself is down, a one-time link doesn't help: the operator still can't link. Either of these brings password sign-in back for everyone:

```bash
whity-cli auth password-sign-in on
```

- **The command** turns the setting back on until you turn it off again.
- **The environment variable** `WHITY_PASSWORD_SIGN_IN=force` keeps password sign-in on whatever the setting says. It's for when you can't reach the database from a shell. Remove it afterwards.

`whity-cli auth password-sign-in status` shows both.

Accounts that only ever signed in through the provider have no password. The break-glass helps accounts that still have one, such as the operator account made at install.

## Sign-up links with a plugin

A link like `/register?plan=free&plugin=salons` keeps working. The sign-up page remembers the plugin and sends the person to the provider. After they create their workspace on the welcome step, the plugin's trial starts and its setup opens, as it does after registering with a password.
