# ADR 0019: Core keeps its own sign-in and email; Whity ID is optional

- **Status:** Accepted
- **Date:** 2026-11-16
- **Task / Issue:** WHIT-1056 (the decision), WHIT-1160 (writing it down)
- **Deciders:** Owner ("Core keeps its own", 2026-11-16), Whity-Core maintainers

## Context

whity-core and Whity ID (whity-org/whity-id) both have password reset, email verification, two-step sign-in and branded email:

- Core has about 17,000 lines in `src/Auth`, `src/Core/Identity` and `src/Core/Mail`.
- Whity ID has about 5,000 lines, plus its brand-package email templates.

Part of the overlap is on purpose, because core can be self-hosted without Whity ID. But the line was never written down, so a fix could land in one copy and not the other.

## Decision

1. **Self-hosted core keeps its own sign-in:** passwords, reset, email verification and two-step sign-in. Whity ID is optional, and core must work fully without it.
2. **Whity ID's emails stay separate for now.**
   - Core builds its emails with `src/Core/Mail/EmailLayout.php`, following EM.1 and `docs/design/handoff/emails/`.
   - Whity ID renders the brand package's ready-made templates (`app/Mail/BrandTemplate.php`).
   - Sharing core's layout would mean a new shared PHP package and rewriting Whity ID's 25 templates. Revisit when Whity ID's emails next need a design change.
3. **What stays on one side:**

   | Only in core | Only in Whity ID |
   |---|---|
   | Workspace-branded email (the workspace's logo and colour) | OIDC sign-in for Whity's paid services |
   | Invitations to a workspace | Accounts and licences for the store and Whity Pay |
   | Workspace sign-in on a self-hosted install | Whity's own brand emails |

## Consequences

- Reset, verification and two-step exist in both repositories on purpose.
- A change to one of those flows, above all a security fix, is checked against the same flow in the other repository. The pull request template asks for that.

## Addendum: an operator may sign in only through an identity provider (WHIT-1168, 2026-11-16)

The owner asked for app.whity.dev to sign people in and up only with Whity ID. This stays inside the decision above: it is a server setting, `auth.password_sign_in`, which is **on by default**, so a self-hosted core keeps its own sign-in unless its operator turns it off.

- Off, password sign-in, password reset, registration with a password and the local two-step set-up refuse. Sign-in and sign-up pages show only the identity provider, and invitations are accepted by signing in with it.
- Sessions, API tokens, MCP tokens and agent credentials that already exist keep working.
- The operator keeps a way back in: `whity-cli auth password-sign-in on`, or `WHITY_PASSWORD_SIGN_IN=force` in the environment. The setting can't be turned off without an enabled server-wide provider, and an operator can't turn it off before signing in with that provider once.

See `docs/wiki/Whity-ID-Only-Sign-In.md`.
