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
     */
    public const MEMBERSHIP_CUSTOMER_JOINED = 'membership.customer_joined';

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

    // ---- AI connections (SDK 1.77, WHIT-992) ----
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

    // ---- platform ----
    public const NAVIGATION_REGISTER = 'navigation.register';
    public const PERMISSION_REGISTERED = 'permission.registered';
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
