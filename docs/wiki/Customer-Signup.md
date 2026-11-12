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

- **Mode.** Off (the default), Approval required, or Open. Sign-up can only be turned on once the workspace has a web address (slug), because its sign-up page lives at `/join/<slug>`.
- **Starting role.** The role everyone gets when they join. Only customer-safe roles can be chosen. If none qualifies yet, create a role in Roles with just what customers need (for example `forms:submit`).
- **Allowed email domains.** Optional. When you list domains, only addresses on those domains can sign up. Domain names follow the email-domain policy's rules: no IP addresses, no wildcards. A leading `@` is dropped.
- **Requests.** People who confirmed their email and are waiting for an admin, when the mode is Approval required.

### Customer-safe roles

A role is customer-safe when it holds no privileged permission, counting every role it inherits from. Privileged means any of these:

- a permission on an administration area: people, roles, workspaces, units, settings, audit, plugins, billing, sign-up and the rest;
- an action that changes access, decides on someone's behalf, or removes things: `manage`, `approve`, `assign`, `delete`, `issue`, `publish`, `review`, `route` and similar;
- a wildcard.

One exception: a plugin permission that the plugin itself marks **customer-grade** (see [For plugins](#for-plugins)) is not privileged, whatever its action. So a dental lab plugin can let a clinic that signed up add its own patients (`dlaas_patient:manage`) or upload its own scans (`dlaas_scan:upload`).

The role is checked when an admin saves the settings and again each time someone signs up. If a role gains an admin permission after it was saved, sign-ups stop until an admin chooses a role again. The settings screen shows a warning while that is the case.

### Customers are never staff

Each membership has a **kind**: `member` (staff) or `customer`. When every active membership a person holds in a workspace is a customer one, RBAC drops privileged permissions from what they can do, whatever their role, unit, resource grants or delegations say. So putting a customer into the admin role does not make them an admin. `GET /api/v1/me` returns `kind` on each membership, and the app hides admin screens and "create workspace" from customers.

Profiles stay global (ADR 0005). The same email can be a customer in one workspace and staff in another. A person who already has an account, including one who signs in with Whity ID, joins with that account and keeps its password.

## Customers invite their own team

Settings → Sign-up has a switch, **Customers can invite their team**. It is off by default. When it is on, a person who is only a customer in this workspace can invite colleagues by email, for example a clinic owner bringing in their dentists.

- **Into what.** The colleague joins in the inviter's own role, as a customer. Nobody can choose a different role, a unit or staff. If the inviter's role has since gained an admin permission, or they hold more than one role here, they can't invite (409 `role_not_customer_safe`).
- **Who.** People only, never an agent acting for them, and never staff: staff invite from People.
- **Nothing is revealed.** Every address the workspace's domain list allows gets the same answer (202), the email is sent after the answer so timing doesn't tell either, and the inviter's own list shows every address they tried as pending. Nothing is sent when the address already has a membership here, was turned away from sign-up, or has an invitation someone else sent. A customer's invitation never replaces an admin's or another customer's, and never completes a membership an admin staged.
- **The domain list** in Sign-up settings applies to invitations too.
- **No approval queue.** Invited people join when they accept, even when sign-up is Off or Approval required. The switch is separate from the sign-up mode.
- **Links stop working** when an admin turns the switch off, suspends or removes the inviter, or gives the inviter's role an admin permission. A link that wasn't used works again if things are put back.
- **Limits.** 10 an hour and 30 a day per customer, 100 an hour per workspace.
- **Audit.** Each invitation is recorded with the inviter as the actor (`tenant.invitation.created`, `kind: customer`). Staff see customer invitations in People → Invitations with `kind: customer` and who sent them, and can withdraw them there.
- **Email.** Invitations are emailed, so they need the instance's mail transport and the Invitation email turned on (Settings → Email). Without it nothing reaches the colleague.

| Method | Path | Who |
|---|---|---|
| GET | `/api/v1/team-invitations` | a customer: whether they may invite (`enabled`) and what they sent |
| POST | `/api/v1/team-invitations` | a customer: `{ "email": "…" }` |
| DELETE | `/api/v1/team-invitations/{id}` | a customer: withdraw one they sent |

Promoting a customer to staff stays an admin's decision: it is a membership change, and there is no API or plugin seam that does it.

## The pages

- `/join/<workspace slug>`: the workspace's sign-up page (name, email, the bot check). Settings → Sign-up shows the full link to share. On a custom domain set up in Branding, the same path works on that domain.
- `/join/confirm`: where the email link lands.
- `/admin/settings/customer-signup`: Settings → Sign-up for workspace admins. In the system workspace, Settings → Sign-up is still the operator's platform sign-up.

## How a sign-up goes

1. **Ask.** `POST /api/v1/public/signup/workspaces/{slug}` with `email`, `display_name` and `bot_token`. The answer is always `202 Check your email` for any address the workspace accepts. Nothing about the address is looked up at this step.
2. **Confirm.** The emailed link opens `/join/confirm?token=…` (`SIGNUP_CONFIRM_URL` to change it). `GET /api/v1/public/signup/confirm?token=` says whether a password is needed. A new address chooses one; an existing account does not. `POST /api/v1/public/signup/confirm` uses the link once. Links last 24 hours, and only the newest one for an address works.
3. **Join.**
   - **Open:** the customer membership is created now (`joined`).
   - **Approval required:** the person waits (`pending_approval`). No membership exists until an admin approves them in Settings → Sign-up.

A rejected person, or one whose membership is suspended, can't get in by signing up again. Reject asks first. A rejected request stays under **Turned away** in the queue, and can still be approved from there.

## Joining with a sign-in provider

The sign-up page also offers **Continue with <provider>** for the operator's enabled, global sign-in providers (Settings → Single sign-on in the system workspace; on app.whity.dev that is Whity ID). A workspace's own provider is not offered: it signs in that workspace's existing members only.

- The link is `/api/v1/auth/sso/<provider>/start?join=<workspace slug>`. The workspace is checked when the visitor leaves and then travels only inside the signed sign-in flow cookie, so the return trip can't be pointed at another workspace.
- On return, the ordinary federated sign-in finds or creates the profile. A new one has no password (`auth_method` `idp`), and a verified email links to the existing account, as at sign-in.
- Then the same rules as the email path apply: the mode, the domain list checked against the email the provider verified, the customer-safe starting role, and the person's existing membership, suspension, invitation or rejection. Open signs them straight into the workspace. Approval required puts them in the queue and sends them back to the sign-up page.
- The provider round trip stands in for the bot check and the emailed link. A provider email that isn't verified can't join.
- No workspace is ever created on this path, even when the operator lets federated sign-ins create one.

## When sign-up can't work yet

Email sign-up needs the instance's bot check (Settings → Public forms), unless the operator allowed going without. Until then:

- Settings → Sign-up warns as soon as Open or Approval required is chosen, and links to the bot check settings for the operator. If a sign-in provider is available, it says people can still join with it.
- The sign-up page learns this up front (`email_signup: false`, `email_signup_reason: bot_check_unavailable`) and shows only the provider buttons. With no provider either, it says the workspace isn't taking sign-ups yet. Sending the form anyway is refused with 409 `email_signup_unavailable`. A 503 means only that the bot check provider couldn't be reached.

## Safety

- **Which workspace.** The slug in the path names it. When the request's host also names a workspace (its custom domain or slug subdomain, the same resolution branding uses), the two must agree. After the request, the workspace is read only from the stored row. A sign-up for workspace A can't create a membership in workspace B.
- **No enumeration.** The request endpoint gives the same answer and does the same work for every address. An unknown workspace and a workspace with sign-up off both get the same 404. Only the person holding the emailed link finds out whether the address already has an account.
- **Limits.** Per IP, per address and per workspace. Over the per-address limit the answer is still 202, but no email is sent.
- **Bot check.** The instance's public-form bot check (Cloudflare Turnstile, Settings → Public forms). With no provider configured, sign-up is refused unless the operator has allowed public forms without one.
- **Audit.** Requests, confirmations, settings changes, approvals and rejections are recorded. Each grant is also recorded as `user.membership.added` with `kind: customer`.

## Errors

Refusals from the settings and queue endpoints carry a stable `code` in `details` beside the English sentence, so a client can show them in the reader's language: `mode_invalid`, `role_required`, `role_not_customer_safe`, `workspace_slug_missing`, `domain_invalid` (with `value`, the line at fault), `domains_too_many` (with `max`), `membership_conflict` and `request_not_found`.

## For plugins

### Customer-grade permissions (SDK 1.104)

The customer rule drops `manage`, `upload`, `review` and the other privileged actions on every resource, because core can't tell whether a plugin's `manage` reaches one record or all of them. A plugin that scopes a permission to the caller's own records says so by implementing `Whity\Sdk\PluginCustomerPermissionsInterface`:

```php
final class DlaasPlugin implements PluginInterface, PluginRolesInterface, PluginCustomerPermissionsInterface
{
    public function getPermissions(): array
    {
        return ['dlaas_patient:read', 'dlaas_patient:manage', 'dlaas_scan:upload', 'dlaas_case:review'];
    }

    /** Every route checking these binds the caller's own clinic and answers 404 for any other. */
    public function getCustomerPermissions(): array
    {
        return ['dlaas_patient:manage', 'dlaas_scan:upload', 'dlaas_case:review'];
    }
    // ...
}
```

What happens then:

- A customer keeps these permissions instead of losing them.
- Settings → Sign-up can offer a role made of customer-safe core permissions plus these. Seed one with `PluginRolesInterface` (for example `clinic_owner`) or build it in Roles. The workspace admin still chooses it.
- The plugin promises the scoping. Core can't check it, so every route that checks one of these permissions must limit the caller to their own records.

The host refuses, skips and logs:

- a slug the plugin doesn't declare in `getPermissions()`, or one another plugin claimed first;
- a resource outside the plugin's own namespace. The resource must be the plugin's slug, or start with it and `_`: `dlaas_patient` for a plugin named DLaaS. Workspace plugins can't take a name inside an installed plugin's namespace, so none can reuse these slugs;
- a core permission, or anything under a core or administration resource (`users`, `roles`, `settings`, `billing`, ...), whatever the action;
- a resource another active plugin also declares permissions under. A plugin vouches only for resources it alone owns.

When the plugin is disabled or uninstalled, its list is withdrawn at once: customers lose those permissions, and a sign-up role that needs them stops being offered and stops letting people join, until the plugin is back.

Renaming actions to get past the rule (`dlaas_case:respond` instead of `dlaas_case:review`) is not the way: declare the permission customer-grade instead.

Workspace (data-only) plugins can't declare customer-grade permissions. They have no routes of their own to do the scoping.

### Events

`membership.customer_inviting` (SDK 1.105) runs before a customer's team invitation is written. The payload is `tenant_id`, `inviter_profile_id`, `email`, `role_id` and `annotations`. Refuse with `HookVetoException` and the customer sees your reason. Or return `annotations`, a small JSON object keyed by your plugin's name: DLaaS would check that the inviter owns a clinic and attach `{"dlaas": {"clinic_id": 42}}`. The email, role and inviter can't be changed.

When the colleague accepts, `membership.customer_joined` fires with `via: invitation`, plus `invitation_id`, `invited_by` and the `annotations`, so the plugin can bind the newcomer to the inviter's clinic.

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
