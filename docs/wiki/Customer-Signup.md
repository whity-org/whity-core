# Customer sign-up for a workspace

Whity has two kinds of self-service sign-up.

| | Platform sign-up | Workspace sign-up |
|---|---|---|
| What it does | Creates a new workspace, with the person as its owner | Joins one existing workspace as a **customer** |
| Who controls it | The operator (Settings → Instance: `auth.self_registration_enabled`, approval, payment) | That workspace's own admins (Settings → Sign-up) |
| Where it is served | `/register` | The workspace's own sign-up page, on its host, slug or custom domain |
| API | `POST /api/v1/register` | `/api/v1/public/signup/...` |

Workspace sign-up never creates a workspace.

## Settings → Sign-up

Admins with the `signup:manage` permission manage it. Migration 222 gave that permission to every role that already holds `settings:write`.

- **Mode.** Off (the default), Approval required, or Open.
- **Starting role.** The role everyone gets when they join. Only customer-safe roles can be chosen. If none qualifies yet, create a role in Roles with just what customers need (for example `forms:submit`).
- **Allowed email domains.** Optional. When you list domains, only addresses on those domains can sign up. Domain names follow the email-domain policy's rules: no IP addresses, no wildcards. A leading `@` is dropped.
- **Requests.** People who confirmed their email and are waiting for an admin, when the mode is Approval required.

### Customer-safe roles

A role is customer-safe when it holds no privileged permission, counting every role it inherits from. Privileged means any of these:

- a permission on an administration area: people, roles, workspaces, units, settings, audit, plugins, billing, sign-up and the rest;
- an action that changes access, decides on someone's behalf, or removes things: `manage`, `approve`, `assign`, `delete`, `issue`, `publish`, `review`, `route` and similar;
- a wildcard.

The role is checked when an admin saves the settings and again each time someone signs up. If a role gains an admin permission after it was saved, sign-ups stop until an admin chooses a role again. The settings screen shows a warning while that is the case.

### Customers are never staff

Each membership has a **kind**: `member` (staff) or `customer`. When every active membership a person holds in a workspace is a customer one, RBAC drops privileged permissions from what they can do, whatever their role, unit, resource grants or delegations say. So putting a customer into the admin role does not make them an admin. `GET /api/v1/me` returns `kind` on each membership, and the app hides admin screens and "create workspace" from customers.

Profiles stay global (ADR 0005). The same email can be a customer in one workspace and staff in another. A person who already has an account, including one who signs in with Whity ID, joins with that account and keeps its password.

## How a sign-up goes

1. **Ask.** `POST /api/v1/public/signup/workspaces/{slug}` with `email`, `display_name` and `bot_token`. The answer is always `202 Check your email` for any address the workspace accepts. Nothing about the address is looked up at this step.
2. **Confirm.** The emailed link opens `/join/confirm?token=…` (`SIGNUP_CONFIRM_URL` to change it). `GET /api/v1/public/signup/confirm?token=` says whether a password is needed. A new address chooses one; an existing account does not. `POST /api/v1/public/signup/confirm` uses the link once. Links last 24 hours, and only the newest one for an address works.
3. **Join.**
   - **Open:** the customer membership is created now (`joined`).
   - **Approval required:** the person waits (`pending_approval`). No membership exists until an admin approves them in Settings → Sign-up.

A rejected person, or one whose membership is suspended, can't get in by signing up again. A rejected request can still be approved later.

## Safety

- **Which workspace.** The slug in the path names it. When the request's host also names a workspace (its custom domain or slug subdomain, the same resolution branding uses), the two must agree. After the request, the workspace is read only from the stored row. A sign-up for workspace A can't create a membership in workspace B.
- **No enumeration.** The request endpoint gives the same answer and does the same work for every address. An unknown workspace and a workspace with sign-up off both get the same 404. Only the person holding the emailed link finds out whether the address already has an account.
- **Limits.** Per IP, per address and per workspace. Over the per-address limit the answer is still 202, but no email is sent.
- **Bot check.** The instance's public-form bot check (Cloudflare Turnstile, Settings → Public forms). With no provider configured, sign-up is refused unless the operator has allowed public forms without one.
- **Audit.** Requests, confirmations, settings changes, approvals and rejections are recorded. Each grant is also recorded as `user.membership.added` with `kind: customer`.

## For plugins

`membership.customer_joined` (SDK 1.73, `Whity\Sdk\Hooks\Events::MEMBERSHIP_CUSTOMER_JOINED`) fires after a customer membership is committed: at once in Open mode, or on approval. The payload is ids only: `tenant_id`, `profile_id`, `membership_id`, `role_id`, `role_name`, `via` (`open` or `approval`) and `approved_by`. The return value is ignored.

## API reference

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/public/signup/workspaces/{slug}` | public |
| POST | `/api/v1/public/signup/workspaces/{slug}` | public |
| GET | `/api/v1/public/signup/confirm?token=` | public |
| POST | `/api/v1/public/signup/confirm` | public |
| GET | `/api/v1/signup/settings` | `signup:manage` |
| PUT | `/api/v1/signup/settings` | `signup:manage` |
| GET | `/api/v1/signup/requests?status=pending` | `signup:manage` |
| POST | `/api/v1/signup/requests/{id}/approve` | `signup:manage` |
| POST | `/api/v1/signup/requests/{id}/reject` | `signup:manage` |

The operator's queue for platform sign-ups (`/api/v1/registrations`) stays system-workspace only.
