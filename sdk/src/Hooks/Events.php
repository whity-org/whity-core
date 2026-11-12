<?php

declare(strict_types=1);

namespace Whity\Sdk\Hooks;

use Whity\Sdk\PluginNamespace;

/**
 * Catalogue of hook event names dispatched by the Whity platform (SDK v1.0).
 *
 * Plugins subscribe to these via {@see \Whity\Sdk\PluginInterface::getHooks()}.
 * Two flavours exist:
 *
 * - Synchronous FILTER hooks (`*.creating` / `*.updating` / `*.deleting`):
 *   listeners receive the mutable payload plus a context array and MUST return
 *   the (possibly modified) payload.
 * - Notification hooks (`*.created` / `*.updated` / `*.deleted` and the
 *   `*.async` variants): listeners observe; async variants are queued.
 *
 * Any listener can be OBSERVE-ONLY (SDK 1.89): subscribe with
 * `['callback' => $fn, 'observe' => true]`. The host ignores what it returns,
 * a change to its copy of the payload reaches nobody, and it cannot veto: a
 * {@see HookVetoException} it throws is logged and the operation carries on. Use
 * it whenever a listener only needs to know something happened.
 *
 * `user.creating` (SDK 1.70) is the exception to the FILTER rule above: it is
 * read-only. Listeners receive `email` and `role_id`, the host ignores what they
 * return, and the only way to affect the create is to refuse it with
 * {@see HookVetoException} (`409 Conflict`, nothing written). The new user's
 * password is never part of any hook payload.
 *
 * Deletion ordering + transactionality (SDK 1.15, WC-713)
 * -------------------------------------------------------
 * For `tenant.*`, `ou.*` and `role.*`, the host runs
 *
 *     `*.deleting`  →  DELETE  →  `*.deleted`
 *
 * inside a SINGLE database transaction, and only dispatches `*.deleted.async`
 * once that transaction has COMMITTED. Three consequences a plugin can rely on:
 *
 *  1. During `*.deleting` the row still exists and is readable — that is where
 *     to look up anything you need about it. During `*.deleted` it is gone
 *     (within the transaction), so read first, act second.
 *  2. Throwing {@see HookVetoException} from EITHER hook rolls the whole
 *     transaction back — the entity survives — and the API caller gets
 *     `409 Conflict` with your `reason()` in the error details. This is the only
 *     Throwable that crosses the host's per-plugin error boundary; anything else
 *     you throw is logged, isolated, and the deletion proceeds regardless.
 *  3. `*.deleted.async` fires only for a deletion that actually committed, so a
 *     relay handler never has to reason about a delete that was undone.
 *
 * Before 1.15 the DELETE committed on its own and `*.deleted` fired afterwards,
 * so a plugin's cleanup was best-effort: it could neither veto the deletion nor
 * undo it, and a failure left its rows orphaned against a parent that no longer
 * existed. Plugins targeting `^1.15` may rely on the guarantees above; on an
 * older host, treat `*.deleted` as a pure notification.
 *
 * `user.deleted` is deliberately NOT part of this contract: it has no
 * `user.deleting` counterpart, and its core listeners send mail, which must not
 * hold a transaction open nor be able to undo a membership removal.
 *
 * The string values are the wire contract; the constants exist so plugin code
 * gets IDE completion and typo safety. New events are added in minor SDK
 * versions (additive policy).
 */
final class Events
{
    // ---- users ----
    public const USER_CREATING = 'user.creating';
    public const USER_CREATED = 'user.created';
    public const USER_CREATED_ASYNC = 'user.created.async';
    public const USER_UPDATED = 'user.updated';
    public const USER_DELETED = 'user.deleted';
    // Fired after the change, the return value ignored (SDK 1.89). Payload:
    // `profile_id`, `tenant_id`, `membership_id`, `role_id`, `role_name`, `ou_id`.
    // `added` may also carry `status`, `kind`, `via` or `is_primary`; `removed`
    // also carries `status` and `granted_at`.
    public const USER_MEMBERSHIP_ADDED = 'user.membership.added';
    public const USER_MEMBERSHIP_REMOVED = 'user.membership.removed';

    // ---- registration (SDK 1.89) ----
    // Fired after the change, the return value ignored. These carry the
    // person's address, so treat the payload as personal data.
    /** Payload: `email`, `name`, `tenant_name`, `approval_required`, `verification_required`. */
    public const REGISTRATION_COMPLETED = 'registration.completed';
    /** Payload: `email`, `name`. */
    public const REGISTRATION_APPROVED = 'registration.approved';
    /** Payload: `email`, `name`. */
    public const REGISTRATION_REJECTED = 'registration.rejected';

    // ---- federated sign-in (SDK 1.89) ----
    /**
     * Somebody signed in through a federated provider. Fired after the sign-in,
     * the return value ignored. Payload: `profile_id`, `email`, `provider_key`,
     * `issuer`, `subject`, `global_trust`, `provider_tenant_id`,
     * `provisioned_tenant_id`. Never a token.
     */
    public const AUTH_FEDERATED_SIGNED_IN = 'auth.federated.signed_in';

    // ---- resource grants (SDK 1.89) ----
    /** After the grant. Payload: `id`, `tenant_id`, `resource_type`, `resource_id`, `role_id`, `profile_id`. */
    public const RBAC_RESOURCE_GRANT_CREATED = 'rbac.resource_grant.created';
    /** After the revoke. Payload: `id`, `tenant_id`. */
    public const RBAC_RESOURCE_GRANT_REVOKED = 'rbac.resource_grant.revoked';
    /** After every grant at one resource is revoked. Payload: `tenant_id`, `resource_type`, `resource_id`, `revoked`. */
    public const RBAC_RESOURCE_GRANT_REVOKED_ALL = 'rbac.resource_grant.revoked_all';
    /**
     * A FILTER: the host asks whether a plugin-owned resource exists before
     * granting on it. Payload: `tenant_id`, `resource_type`, `resource_id`,
     * `exists`. Return it with `exists` true for a resource you own. An
     * observe-only listener cannot answer.
     */
    public const RBAC_RESOURCE_GRANT_VERIFY_RESOURCE = 'rbac.resource_grant.verify_resource';

    // ---- time windows (SDK 1.89) ----
    /**
     * A FILTER: the host collects what is unfinished before a period closes.
     * Payload: `tenant_id`, `window_id`, `window_type_id`, `starts_on`, `ends_on`,
     * `unfinished`. Append `['label' => string, 'count' => int, 'source' => string]`
     * to `unfinished`. An observe-only listener cannot contribute.
     */
    public const TIME_WINDOW_CLOSE_REPORT = 'time_window.close_report';

    // ---- data type lifecycle (SDK 1.89) ----
    /** Before a declared data type's record changes state. A listener may veto. */
    public const DATATYPE_LIFECYCLE_CHANGING = 'datatype.lifecycle.changing';
    /** After the change, the return value ignored. */
    public const DATATYPE_LIFECYCLE_CHANGED = 'datatype.lifecycle.changed';

    // ---- tenants ----
    public const TENANT_CREATING = 'tenant.creating';
    public const TENANT_CREATED = 'tenant.created';
    public const TENANT_CREATED_ASYNC = 'tenant.created.async';
    public const TENANT_UPDATING = 'tenant.updating';
    public const TENANT_UPDATED = 'tenant.updated';
    public const TENANT_DELETING = 'tenant.deleting';
    public const TENANT_DELETED = 'tenant.deleted';
    public const TENANT_DELETED_ASYNC = 'tenant.deleted.async';

    // ---- roles ----
    public const ROLE_CREATING = 'role.creating';
    public const ROLE_CREATED = 'role.created';
    public const ROLE_CREATED_ASYNC = 'role.created.async';
    public const ROLE_UPDATING = 'role.updating';
    public const ROLE_UPDATED = 'role.updated';
    public const ROLE_DELETING = 'role.deleting';
    public const ROLE_DELETED = 'role.deleted';
    public const ROLE_DELETED_ASYNC = 'role.deleted.async';

    // ---- organizational units ----
    public const OU_CREATING = 'ou.creating';
    public const OU_CREATED = 'ou.created';
    public const OU_CREATED_ASYNC = 'ou.created.async';
    public const OU_UPDATING = 'ou.updating';
    public const OU_UPDATED = 'ou.updated';
    public const OU_UPDATED_ASYNC = 'ou.updated.async';
    public const OU_DELETING = 'ou.deleting';
    public const OU_DELETED = 'ou.deleted';
    public const OU_DELETED_ASYNC = 'ou.deleted.async';
    public const OU_ROLE_ASSIGNED = 'ou.role_assigned';
    public const OU_ROLE_REMOVED = 'ou.role_removed';

    // ---- memberships (SDK 1.73) ----
    /**
     * Somebody joined a workspace as a CUSTOMER through the workspace's own
     * sign-up: at once when sign-up is Open, or when an admin approves them
     * when it is Approval required. Fired after the membership is committed,
     * and the return value is ignored, so a listener cannot change or undo it.
     * Payload: `tenant_id`, `profile_id`, `membership_id`, `role_id`,
     * `role_name`, `via` (`open` or `approval`), `approved_by` (the approving
     * admin's profile id, or null). Ids only: read anything else under your
     * own permissions.
     *
     * Since SDK 1.105 it also fires when somebody accepts a customer's team
     * invitation, with `via` `invitation`, `approved_by` null, and three more
     * keys: `invitation_id`, `invited_by` (the inviting customer's profile id)
     * and `annotations` (what listeners of {@see self::MEMBERSHIP_CUSTOMER_INVITING}
     * attached, or an empty array). A plugin binds the newcomer here, for
     * example to the inviter's clinic.
     */
    public const MEMBERSHIP_CUSTOMER_JOINED = 'membership.customer_joined';

    /**
     * A customer is about to invite a colleague into the workspace (SDK 1.105,
     * #1872). Customers may invite only when an admin turned it on in Settings
     * > Sign-up, only into their own role, and only as customers. Runs before
     * anything is written.
     *
     * Payload: `tenant_id`, `inviter_profile_id`, `email` (lowercased),
     * `role_id`, `annotations` (an empty array, or what earlier listeners set).
     *
     * A listener may:
     * - refuse with {@see HookVetoException}: nothing is written or sent, and the
     *   customer sees your `reason()` (409);
     * - return the payload with `annotations` set to a small JSON object (4 KB
     *   at most; key it by your plugin's name). It is stored on the invitation
     *   and handed back in {@see self::MEMBERSHIP_CUSTOMER_JOINED} when the
     *   invitation is accepted. Core never reads it.
     * Anything else a listener returns is ignored: the email, role and inviter
     * can't be changed here.
     *
     * The hook runs even when the host then sends nothing (the address is
     * already here, was turned away, or has an invitation someone else sent):
     * the customer gets the same answer either way.
     */
    public const MEMBERSHIP_CUSTOMER_INVITING = 'membership.customer_inviting';

    // ---- documents (SDK 1.49; DOCUMENT-AUTOMATION.md) ----
    // Before a document is issued: a listener may veto with HookVetoException,
    // and nothing is written. Payload: tenant_id, source_id, version, record,
    // origin_key, actor — ids only, never field values.
    //
    // SDK 1.66: it also fires for a preflight, with `check_only: true`, `checks: []`
    // and `missing_fields` in the payload, and nothing will be issued. Change
    // nothing, append {label, ok, why?} to `checks` for each thing you check, and
    // return the payload, so a person sees every reason at once. A veto still counts,
    // as one failed check. The real issue fires this again, so check again there.
    public const DOCUMENT_ISSUING = 'document.issuing';
    // A document was issued (its record exists, with its QR code when the
    // tenant has them on). Payload as above, plus id.
    public const DOCUMENT_ISSUED = 'document.issued';
    public const DOCUMENT_ISSUED_ASYNC = 'document.issued.async';
    // A PDF was stored for a document, on issue or on a correction. Payload:
    // id, tenant_id, artifact_id, source_id, version.
    public const DOCUMENT_RENDERED = 'document.rendered';
    public const DOCUMENT_RENDERED_ASYNC = 'document.rendered.async';

    /**
     * SDK 1.65: a number handed out at final approval, once per document. A document
     * numbered at issue carries its number on `document.issued` instead. Payload:
     * `id`, `number`, `route_id`, `actor_profile_id`.
     */
    public const DOCUMENT_NUMBERED = 'document.numbered';
    public const DOCUMENT_NUMBERED_ASYNC = 'document.numbered.async';

    /**
     * SDK 1.68: a revision replaced a document. Payload: `id`, `supersedes_id`, `reason`,
     * `actor`, `number`, `revision`. It also fires `document.issued` for the new document.
     */
    public const DOCUMENT_REVISED = 'document.revised';
    public const DOCUMENT_REVISED_ASYNC = 'document.revised.async';

    // ---- routes and forms (SDK 1.94, WHIT-923) ----
    // Fired after the change is saved. Payloads carry ids, never content: read
    // what you need through the API, as the person or plugin you act for.
    // Every route payload has `route_id`, `subject_type`, `subject_id`,
    // `document_id` (null for a form submission with no document yet),
    // `tenant_id`, `actor_profile_id` and `event_id`.

    /** A route was started on a document or a form submission. Payload also has `action`, `recipients`. */
    public const DOCUMENT_ROUTED = 'document.routed';
    public const DOCUMENT_ROUTED_ASYNC = 'document.routed.async';

    /** Somebody acted on a route. Payload also has `action`, `verdict`, `decided`, `step_id`, `recipients`. */
    public const DOCUMENT_ROUTE_ACTED = 'document.route_acted';
    public const DOCUMENT_ROUTE_ACTED_ASYNC = 'document.route_acted.async';

    /** A route was sent back to its author for changes. Payload also has `step_id`, `resume_from`. */
    public const ROUTE_SENT_BACK = 'route.sent_back';
    public const ROUTE_SENT_BACK_ASYNC = 'route.sent_back.async';

    /** The author sent the changes back in, and the route went on. Payload also has `step_id`. */
    public const ROUTE_RESUBMITTED = 'route.resubmitted';
    public const ROUTE_RESUBMITTED_ASYNC = 'route.resubmitted.async';

    /** Nothing is left open on a route. Payload also has `outcome`: `approved`, `rejected` or null. */
    public const ROUTE_COMPLETED = 'route.completed';
    public const ROUTE_COMPLETED_ASYNC = 'route.completed.async';

    /** Somebody signed on a signing step. Payload also has `step_id`. */
    public const ROUTE_SIGNED = 'route.signed';
    public const ROUTE_SIGNED_ASYNC = 'route.signed.async';

    /**
     * A route's finalise step made its final document. Payload: `id` (the document),
     * `document_id`, `route_id`, `subject_type`, `subject_id`, `artifact_id`, `tenant_id`.
     */
    public const DOCUMENT_FINALISED = 'document.finalised';
    public const DOCUMENT_FINALISED_ASYNC = 'document.finalised.async';

    /**
     * Somebody sent a form. Payload: `id` (the submission), `form_id`, `tenant_id`,
     * `actor_profile_id` (null when it was anonymous), `route_id` (null when it has none).
     */
    public const FORM_SUBMITTED = 'form.submitted';
    public const FORM_SUBMITTED_ASYNC = 'form.submitted.async';

    /** The office recorded its answers on a submission. Payload: `id`, `form_id`, `tenant_id`, `actor_profile_id`. */
    public const SUBMISSION_REVIEWED = 'submission.reviewed';
    public const SUBMISSION_REVIEWED_ASYNC = 'submission.reviewed.async';

    /** The person who sent a submission withdrew it. Payload: `id`, `form_id`, `tenant_id`, `actor_profile_id`. */
    public const SUBMISSION_WITHDRAWN = 'submission.withdrawn';
    public const SUBMISSION_WITHDRAWN_ASYNC = 'submission.withdrawn.async';

    // ---- calendar and bookings (SDK 1.98, ADR 0017) ----
    // Fired after the write commits; the return value is ignored, so a
    // listener cannot refuse or change anything. Each has an `.async` twin on
    // the event spine. Ids only: `id`, `tenant_id`, `profile_id` (the member
    // of staff whose calendar it is), and for bookings `service_id` and
    // `status`. A sync plugin writes outwards from these.
    public const CALENDAR_EVENT_CREATED = 'calendar.event.created';
    public const CALENDAR_EVENT_CREATED_ASYNC = 'calendar.event.created.async';
    public const CALENDAR_EVENT_UPDATED = 'calendar.event.updated';
    public const CALENDAR_EVENT_UPDATED_ASYNC = 'calendar.event.updated.async';
    public const CALENDAR_EVENT_DELETED = 'calendar.event.deleted';
    public const CALENDAR_EVENT_DELETED_ASYNC = 'calendar.event.deleted.async';
    /** A booking was made: `status` is `confirmed`, or `pending_payment` while a payment step runs. */
    public const BOOKING_CREATED = 'booking.created';
    public const BOOKING_CREATED_ASYNC = 'booking.created.async';
    /** A booking became confirmed: at once, or when its payment came in. */
    public const BOOKING_CONFIRMED = 'booking.confirmed';
    public const BOOKING_CONFIRMED_ASYNC = 'booking.confirmed.async';
    public const BOOKING_RESCHEDULED = 'booking.rescheduled';
    public const BOOKING_RESCHEDULED_ASYNC = 'booking.rescheduled.async';
    public const BOOKING_CANCELLED = 'booking.cancelled';
    public const BOOKING_CANCELLED_ASYNC = 'booking.cancelled.async';
    /** A payment hold lapsed and the time is free again. */
    public const BOOKING_EXPIRED = 'booking.expired';
    public const BOOKING_EXPIRED_ASYNC = 'booking.expired.async';

    // ---- agents (SDK 1.75, WHIT-990) ----
    // A workspace's agent changed. Fired after the change is saved, and the
    // return value is ignored: a listener can't refuse or undo it. Payload:
    // `tenant_id`, `agent_id`, `actor_profile_id` (the person who made the
    // change). Ids only: read the agent with {@see \Whity\Sdk\Agents\Agents}.
    public const AGENT_CREATED = 'agent.created';
    public const AGENT_UPDATED = 'agent.updated';
    public const AGENT_ARCHIVED = 'agent.archived';
    public const AGENT_RESTORED = 'agent.restored';
    /** Paused: refused on every call, and its waiting proposals can't run, until resumed. */
    public const AGENT_PAUSED = 'agent.paused';
    public const AGENT_RESUMED = 'agent.resumed';

    // ---- agent proposals (SDK 1.78, WHIT-991) ----
    // Notifications only: each fires after the change is saved, the return value
    // is ignored, and a listener can't refuse or change anything. Only the person
    // who granted the agent decides a proposal, never a plugin. Ids and the
    // tool's name only, never the call's arguments.
    /**
     * An agent asked to do something and it waits for its person. Payload:
     * `tenant_id`, `proposal_id`, `agent_assignment_id` (null for an agent that
     * isn't a workspace agent), `on_behalf_of_profile_id`, `tool`.
     */
    public const AGENT_PROPOSAL_CREATED = 'agent.proposal.created';
    /**
     * A proposal stopped waiting. Payload: `tenant_id`, `proposal_id`,
     * `decision` (`approved`, `declined` or `expired`), `decided_by_profile_id`
     * (null when it expired), `on_behalf_of_profile_id`, `tool`, `failed` (it was
     * approved and a check refused it when it ran, so nothing changed).
     */
    public const AGENT_PROPOSAL_DECIDED = 'agent.proposal.decided';
    /**
     * A person undid what an agent did for them. Payload: `tenant_id`,
     * `action_id`, `proposal_id` (null when it ran without asking),
     * `on_behalf_of_profile_id`, `tool`.
     */
    public const AGENT_ACTION_UNDONE = 'agent.action.undone';

    // ---- AI connections (SDK 1.79, WHIT-992) ----
    // An app or agent connected over MCP. Fired after the change, the return
    // value ignored. Payload: `tenant_id`, `connection_id` (as
    // {@see \Whity\Sdk\Mcp\McpConnections} lists it), `profile_id` (whose
    // connection), `kind` (`user` or `ai-agent`), `agent_assignment_id` (null
    // unless it is a workspace agent's), `actor_profile_id` (who did it). Never
    // the token or its id.
    public const MCP_CONNECTION_CREATED = 'mcp.connection.created';
    public const MCP_CONNECTION_GRANT_CHANGED = 'mcp.connection.grant_changed';
    /** Also `by`: `owner`, `admin`, or `agent_removed` (the person stopped using the agent). */
    public const MCP_CONNECTION_REVOKED = 'mcp.connection.revoked';

    // A plugin setting changed (SDK 1.88). Fired after the change, the return
    // value ignored. Payload: `tenant_id` (null when the deployment's own value
    // changed, which every workspace without its own follows), `plugin` (slug),
    // `key` (bare), `actor_profile_id` (null with no person behind it). Never
    // the value: read your own with {@see \Whity\Sdk\Settings\PluginSettingsStore}.
    public const PLUGIN_SETTINGS_CHANGED = 'plugin.settings.changed';

    // ---- platform ----
    public const NAVIGATION_REGISTER = 'navigation.register';
    public const PERMISSION_REGISTERED = 'permission.registered';
    // Boot-time announcements (SDK 1.89). Each names the `source` that registered
    // and what it registered; the return value is ignored.
    public const DATATYPE_REGISTERED = 'datatype.registered';
    public const OU_TYPE_REGISTERED = 'ou_type.registered';
    public const ROUTING_RULE_REGISTERED = 'routing_rule.registered';
    public const WINDOW_TYPE_REGISTERED = 'window_type.registered';
    public const RBAC_RESOURCE_TYPES_REGISTERED = 'rbac.resource_types.registered';
    public const HEALTH_PROBES_REGISTERED = 'health.probes.registered';
    public const SETTINGS_DECLARED = 'settings.declared';
    public const TENANT_TABLES_REGISTERED = 'tenant.tables.registered';
    public const WORKER_BOOT = 'worker.boot';
    public const WORKER_REQUEST_START = 'worker.request.start';
    public const WORKER_REQUEST_END = 'worker.request.end';

    /**
     * The event name to dispatch for one of THIS plugin's own events (SDK 1.29).
     *
     * The constants above name events the HOST dispatches. A plugin's own
     * events live in the same flat event space and are namespaced under the
     * plugin, so `Events::forPlugin('Acme', 'task.completed')` is
     * `acme:task.completed` — the name to dispatch, and the name
     * {@see \Whity\Sdk\PluginEventsInterface} declarations are bound to.
     *
     * Spelling it by hand is the thing this method exists to prevent. The
     * prefix is a SLUG of the plugin name rather than the name itself
     * ({@see \Whity\Sdk\PluginNamespace::slug()}), so `Acme\Widgets\Plugin`
     * prefixes as `plugin` and a hand-written `Acme\Widgets\Plugin:…` matches
     * no listener at all — silently, since a dispatch nobody listens to is
     * indistinguishable from one whose listeners did nothing.
     *
     * Namespacing a plugin's events is worth doing even without an audit
     * declaration: two plugins that both dispatch `item.created` otherwise run
     * each other's listeners, which is a bug neither author can see in their
     * own repository.
     *
     * @param string $pluginName The declaring plugin's name ({@see \Whity\Sdk\PluginInterface::getName()}).
     * @param string $bareEvent  The BARE event name, e.g. `task.completed`.
     * @return string The namespaced event name, e.g. `acme:task.completed`.
     *
     * @throws \InvalidArgumentException When the plugin name yields no usable namespace slug.
     */
    public static function forPlugin(string $pluginName, string $bareEvent): string
    {
        return PluginNamespace::qualify($pluginName, $bareEvent);
    }

    /**
     * Static catalogue only — never instantiated.
     */
    private function __construct()
    {
    }
}
