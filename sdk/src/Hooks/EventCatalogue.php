<?php

declare(strict_types=1);

namespace Whity\Sdk\Hooks;

/**
 * Every event core publishes, with what a listener can do with it (SDK 1.164,
 * docs/wiki/Plugin-SDK-Streamline-Proposal.md §2.3).
 *
 * ```php
 * $info = \Whity\Sdk\Hooks\EventCatalogue::get(Events::TENANT_HANDLE_CHANGED);
 * $info->kind;      // 'observe'
 * $info->payload;   // ['id', 'old_handle', 'new_handle', 'by_profile_id']
 * $info->async;     // the `.async` twin, or null (none here)
 * $info->since;     // '1.160'
 * ```
 *
 * The kinds:
 *  - `observe`: the host ignores what the listener returns.
 *  - `filter`: the host reads the returned payload; return it, changed or not.
 *  - `veto`: throw {@see HookVetoException} to refuse (the API answers 409).
 *    A filter that may also veto has `vetoable` true.
 *  - `queued`: an `.async` twin, persisted and delivered by the relay after
 *    the change commits.
 *
 * `payload` lists the keys the event documents; empty means none are written
 * down yet, so read the dispatching code before relying on one. `since` is
 * exact from 1.164 on; for older events it was reconstructed from
 * sdk/CHANGELOG.md and the comments in {@see Events}, and may be later than
 * the event really is.
 *
 * docs/wiki/HOOK_SYSTEM.md's event tables are generated from this class by
 * `php scripts/hook-catalogue.php`, and `tests/Sdk/EventCatalogueTest.php`
 * fails when an event in {@see Events} has no entry here, or the page is stale.
 * A new event gets its constant, an entry here and a regenerated page in the
 * same change.
 */
final class EventCatalogue
{
    public const OBSERVE = 'observe';
    public const FILTER = 'filter';
    public const VETO = 'veto';
    public const QUEUED = 'queued';

    /** @var list<string> */
    public const KINDS = [self::OBSERVE, self::FILTER, self::VETO, self::QUEUED];

    /**
     * name => entry. `vetoable`, `payload`, `async` and `notes` are left out when
     * false, empty or null.
     *
     * @var array<string, array{group: string, kind: string, vetoable?: bool, payload?: list<string>, async?: string, since: string, notes?: string}>
     */
    private const EVENTS = [
        'user.creating' => ['group' => 'Users', 'kind' => 'veto', 'since' => '1.70'],
        'user.created' => ['group' => 'Users', 'kind' => 'observe', 'async' => 'user.created.async', 'since' => '1.0'],
        'user.created.async' => ['group' => 'Users', 'kind' => 'queued', 'since' => '1.0'],
        'user.updated' => ['group' => 'Users', 'kind' => 'observe', 'since' => '1.0'],
        'user.deleted' => ['group' => 'Users', 'kind' => 'observe', 'since' => '1.29'],
        'user.membership.added' => ['group' => 'Users', 'kind' => 'observe', 'payload' => ['profile_id', 'tenant_id', 'membership_id', 'role_id', 'role_name', 'ou_id'], 'since' => '1.89', 'notes' => 'Fired after the change, the return value ignored (SDK 1.89). Payload: `profile_id`, `tenant_id`, `membership_id`, `role_id`, `role_name`, `ou_id`. `added` may also carry `status`, `kind`, `via` or `is_primary`; `removed` also carries `status` and `granted_at`.'],
        'user.membership.removed' => ['group' => 'Users', 'kind' => 'observe', 'since' => '1.0'],
        'registration.completed' => ['group' => 'Registration', 'kind' => 'observe', 'payload' => ['email', 'name', 'tenant_name', 'approval_required', 'verification_required'], 'since' => '1.89', 'notes' => 'Fired after the change, the return value ignored. These carry the person\'s address, so treat the payload as personal data. Payload: `email`, `name`, `tenant_name`, `approval_required`, `verification_required`.'],
        'registration.approved' => ['group' => 'Registration', 'kind' => 'observe', 'payload' => ['email', 'name'], 'since' => '1.89', 'notes' => 'Payload: `email`, `name`.'],
        'registration.rejected' => ['group' => 'Registration', 'kind' => 'observe', 'payload' => ['email', 'name'], 'since' => '1.89', 'notes' => 'Payload: `email`, `name`.'],
        'auth.federated.signed_in' => ['group' => 'Federated sign-in', 'kind' => 'observe', 'payload' => ['profile_id', 'email', 'provider_key', 'issuer', 'subject', 'global_trust', 'provider_tenant_id', 'provisioned_tenant_id'], 'since' => '1.89', 'notes' => 'Somebody signed in through a federated provider. Fired after the sign-in, the return value ignored. Payload: `profile_id`, `email`, `provider_key`, `issuer`, `subject`, `global_trust`, `provider_tenant_id`, `provisioned_tenant_id`. Never a token.'],
        'rbac.resource_grant.created' => ['group' => 'Resource grants', 'kind' => 'observe', 'payload' => ['id', 'tenant_id', 'resource_type', 'resource_id', 'role_id', 'profile_id'], 'since' => '1.89', 'notes' => 'After the grant. Payload: `id`, `tenant_id`, `resource_type`, `resource_id`, `role_id`, `profile_id`.'],
        'rbac.resource_grant.revoked' => ['group' => 'Resource grants', 'kind' => 'observe', 'payload' => ['id', 'tenant_id'], 'since' => '1.89', 'notes' => 'After the revoke. Payload: `id`, `tenant_id`.'],
        'rbac.resource_grant.revoked_all' => ['group' => 'Resource grants', 'kind' => 'observe', 'payload' => ['tenant_id', 'resource_type', 'resource_id', 'revoked'], 'since' => '1.89', 'notes' => 'After every grant at one resource is revoked. Payload: `tenant_id`, `resource_type`, `resource_id`, `revoked`.'],
        'rbac.resource_grant.verify_resource' => ['group' => 'Resource grants', 'kind' => 'filter', 'payload' => ['tenant_id', 'resource_type', 'resource_id', 'exists'], 'since' => '1.89', 'notes' => 'A FILTER: the host asks whether a plugin-owned resource exists before granting on it. Payload: `tenant_id`, `resource_type`, `resource_id`, `exists`. Return it with `exists` true for a resource you own. An observe-only listener cannot answer.'],
        'time_window.close_report' => ['group' => 'Time windows', 'kind' => 'filter', 'payload' => ['tenant_id', 'window_id', 'window_type_id', 'starts_on', 'ends_on', 'unfinished'], 'since' => '1.39', 'notes' => 'A FILTER: the host collects what is unfinished before a period closes. Payload: `tenant_id`, `window_id`, `window_type_id`, `starts_on`, `ends_on`, `unfinished`. Append `[\'label\' => string, \'count\' => int, \'source\' => string]` to `unfinished`. An observe-only …'],
        'datatype.lifecycle.changing' => ['group' => 'Data type lifecycle', 'kind' => 'veto', 'since' => '1.89', 'notes' => 'Before a declared data type\'s record changes state. A listener may veto.'],
        'datatype.lifecycle.changed' => ['group' => 'Data type lifecycle', 'kind' => 'observe', 'since' => '1.89', 'notes' => 'After the change, the return value ignored.'],
        'tenant.creating' => ['group' => 'Tenants', 'kind' => 'filter', 'since' => '1.0'],
        'tenant.created' => ['group' => 'Tenants', 'kind' => 'observe', 'async' => 'tenant.created.async', 'since' => '1.0'],
        'tenant.created.async' => ['group' => 'Tenants', 'kind' => 'queued', 'since' => '1.0'],
        'tenant.updating' => ['group' => 'Tenants', 'kind' => 'observe', 'since' => '1.0'],
        'tenant.updated' => ['group' => 'Tenants', 'kind' => 'observe', 'since' => '1.0'],
        'tenant.deleting' => ['group' => 'Tenants', 'kind' => 'veto', 'since' => '1.0'],
        'tenant.deleted' => ['group' => 'Tenants', 'kind' => 'veto', 'async' => 'tenant.deleted.async', 'since' => '1.0'],
        'tenant.deleted.async' => ['group' => 'Tenants', 'kind' => 'queued', 'since' => '1.0'],
        'tenant.handle_changed' => ['group' => 'Tenants', 'kind' => 'observe', 'payload' => ['id', 'old_handle', 'new_handle', 'by_profile_id'], 'since' => '1.160', 'notes' => 'A workspace\'s @handle changed (design section 36, HD.4); the old one still leads to the workspace for 90 days. Payload: `id`, `old_handle` (null when it had none), `new_handle`, `by_profile_id`.'],
        'role.creating' => ['group' => 'Roles', 'kind' => 'filter', 'since' => '1.0'],
        'role.created' => ['group' => 'Roles', 'kind' => 'observe', 'async' => 'role.created.async', 'since' => '1.0'],
        'role.created.async' => ['group' => 'Roles', 'kind' => 'queued', 'since' => '1.0'],
        'role.updating' => ['group' => 'Roles', 'kind' => 'observe', 'since' => '1.0'],
        'role.updated' => ['group' => 'Roles', 'kind' => 'observe', 'since' => '1.0'],
        'role.deleting' => ['group' => 'Roles', 'kind' => 'veto', 'since' => '1.0'],
        'role.deleted' => ['group' => 'Roles', 'kind' => 'veto', 'async' => 'role.deleted.async', 'since' => '1.0'],
        'role.deleted.async' => ['group' => 'Roles', 'kind' => 'queued', 'since' => '1.0'],
        'ou.creating' => ['group' => 'Organizational units', 'kind' => 'filter', 'since' => '1.0'],
        'ou.created' => ['group' => 'Organizational units', 'kind' => 'observe', 'async' => 'ou.created.async', 'since' => '1.0'],
        'ou.created.async' => ['group' => 'Organizational units', 'kind' => 'queued', 'since' => '1.0'],
        'ou.updating' => ['group' => 'Organizational units', 'kind' => 'observe', 'since' => '1.0'],
        'ou.updated' => ['group' => 'Organizational units', 'kind' => 'observe', 'async' => 'ou.updated.async', 'since' => '1.0'],
        'ou.updated.async' => ['group' => 'Organizational units', 'kind' => 'queued', 'since' => '1.0'],
        'ou.deleting' => ['group' => 'Organizational units', 'kind' => 'veto', 'since' => '1.0'],
        'ou.deleted' => ['group' => 'Organizational units', 'kind' => 'veto', 'async' => 'ou.deleted.async', 'since' => '1.0'],
        'ou.deleted.async' => ['group' => 'Organizational units', 'kind' => 'queued', 'since' => '1.0'],
        'ou.role_assigned' => ['group' => 'Organizational units', 'kind' => 'observe', 'since' => '1.0'],
        'ou.role_removed' => ['group' => 'Organizational units', 'kind' => 'observe', 'since' => '1.0'],
        'membership.customer_joined' => ['group' => 'Memberships', 'kind' => 'observe', 'payload' => ['tenant_id', 'profile_id', 'membership_id', 'role_id', 'role_name', 'via', 'approved_by'], 'since' => '1.73', 'notes' => 'Somebody joined a workspace as a CUSTOMER through the workspace\'s own sign-up: at once when sign-up is Open, or when an admin approves them when it is Approval required. Fired after the membership is committed, and the return value is ignored, so a listener …'],
        'membership.customer_inviting' => ['group' => 'Memberships', 'kind' => 'filter', 'vetoable' => true, 'since' => '1.105', 'notes' => 'A customer is about to invite a colleague into the workspace (SDK 1.105, #1872). Customers may invite only when an admin turned it on in Settings > Sign-up, only into their own role, and only as customers. Runs before anything is written. Payload: …'],
        'workspace.owner_added' => ['group' => 'Workspace owners', 'kind' => 'observe', 'payload' => ['tenant_id', 'profile_id', 'added_by', 'offer_id'], 'since' => '1.112', 'notes' => 'Someone became an owner of a workspace: they accepted an offer to take it over or to co-own it. Payload: `tenant_id`, `profile_id`, `added_by` (the owner who offered), `offer_id`.'],
        'workspace.owner_removed' => ['group' => 'Workspace owners', 'kind' => 'observe', 'payload' => ['tenant_id', 'profile_id', 'removed_by', 'via'], 'since' => '1.112', 'notes' => 'Someone stopped being an owner of a workspace. Payload: `tenant_id`, `profile_id`, `removed_by` (who caused it: the new owner on a hand-over, another owner, or themselves), `via` (`transfer`, `removed` or `left`).'],
        'workspace.ownership_transferred' => ['group' => 'Workspace owners', 'kind' => 'observe', 'payload' => ['tenant_id', 'from_profile_id', 'to_profile_id', 'offer_id', 'from_left'], 'since' => '1.112', 'notes' => 'A workspace was handed over: fired after {@see self::WORKSPACE_OWNER_ADDED} and {@see self::WORKSPACE_OWNER_REMOVED} for the same change. Payload: `tenant_id`, `from_profile_id`, `to_profile_id`, `offer_id`, `from_left` (true when the old owner chose to leave …'],
        'document.issuing' => ['group' => 'Documents', 'kind' => 'filter', 'vetoable' => true, 'since' => '1.66', 'notes' => 'Before a document is issued: a listener may veto with HookVetoException, and nothing is written. Payload: tenant_id, source_id, version, record, origin_key, actor — ids only, never field values. SDK 1.66: it also fires for a preflight, with `check_only: …'],
        'document.issued' => ['group' => 'Documents', 'kind' => 'observe', 'async' => 'document.issued.async', 'since' => '1.94', 'notes' => 'A document was issued (its record exists, with its QR code when the tenant has them on). Payload as above, plus id.'],
        'document.issued.async' => ['group' => 'Documents', 'kind' => 'queued', 'since' => '1.94'],
        'document.rendered' => ['group' => 'Documents', 'kind' => 'observe', 'async' => 'document.rendered.async', 'since' => '1.49', 'notes' => 'A PDF was stored for a document, on issue or on a correction. Payload: id, tenant_id, artifact_id, source_id, version.'],
        'document.rendered.async' => ['group' => 'Documents', 'kind' => 'queued', 'since' => '1.49'],
        'document.numbered' => ['group' => 'Documents', 'kind' => 'observe', 'payload' => ['id', 'number', 'route_id', 'actor_profile_id'], 'async' => 'document.numbered.async', 'since' => '1.65', 'notes' => 'SDK 1.65: a number handed out at final approval, once per document. A document numbered at issue carries its number on `document.issued` instead. Payload: `id`, `number`, `route_id`, `actor_profile_id`.'],
        'document.numbered.async' => ['group' => 'Documents', 'kind' => 'queued', 'since' => '1.49'],
        'document.revised' => ['group' => 'Documents', 'kind' => 'observe', 'payload' => ['id', 'supersedes_id', 'reason', 'actor', 'number', 'revision'], 'async' => 'document.revised.async', 'since' => '1.68', 'notes' => 'SDK 1.68: a revision replaced a document. Payload: `id`, `supersedes_id`, `reason`, `actor`, `number`, `revision`. It also fires `document.issued` for the new document.'],
        'document.revised.async' => ['group' => 'Documents', 'kind' => 'queued', 'since' => '1.49'],
        'document.routed' => ['group' => 'Routes and forms', 'kind' => 'observe', 'async' => 'document.routed.async', 'since' => '1.94', 'notes' => 'A route was started on a document or a form submission. Payload also has `action`, `recipients`.'],
        'document.routed.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'document.route_acted' => ['group' => 'Routes and forms', 'kind' => 'observe', 'async' => 'document.route_acted.async', 'since' => '1.94', 'notes' => 'Somebody acted on a route. Payload also has `action`, `verdict`, `decided`, `step_id`, `recipients`.'],
        'document.route_acted.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'route.sent_back' => ['group' => 'Routes and forms', 'kind' => 'observe', 'async' => 'route.sent_back.async', 'since' => '1.94', 'notes' => 'A route was sent back to its author for changes. Payload also has `step_id`, `resume_from`.'],
        'route.sent_back.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'route.resubmitted' => ['group' => 'Routes and forms', 'kind' => 'observe', 'async' => 'route.resubmitted.async', 'since' => '1.94', 'notes' => 'The author sent the changes back in, and the route went on. Payload also has `step_id`.'],
        'route.resubmitted.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'route.completed' => ['group' => 'Routes and forms', 'kind' => 'observe', 'async' => 'route.completed.async', 'since' => '1.94', 'notes' => 'Nothing is left open on a route. Payload also has `outcome`: `approved`, `rejected` or null.'],
        'route.completed.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'route.signed' => ['group' => 'Routes and forms', 'kind' => 'observe', 'async' => 'route.signed.async', 'since' => '1.94', 'notes' => 'Somebody signed on a signing step. Payload also has `step_id`.'],
        'route.signed.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'route.stamped' => ['group' => 'Routes and forms', 'kind' => 'observe', 'async' => 'route.stamped.async', 'since' => '1.153', 'notes' => 'A holder applied a company stamp on a stamp step (#1729). Payload also has `step_id` and `stamp_id`.'],
        'route.stamped.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.153', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'document.finalised' => ['group' => 'Routes and forms', 'kind' => 'observe', 'payload' => ['id', 'document_id', 'route_id', 'subject_type', 'subject_id', 'artifact_id', 'tenant_id'], 'async' => 'document.finalised.async', 'since' => '1.94', 'notes' => 'A route\'s finalise step made its final document. Payload: `id` (the document), `document_id`, `route_id`, `subject_type`, `subject_id`, `artifact_id`, `tenant_id`.'],
        'document.finalised.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'form.submitted' => ['group' => 'Routes and forms', 'kind' => 'observe', 'payload' => ['id', 'form_id', 'tenant_id', 'actor_profile_id', 'route_id'], 'async' => 'form.submitted.async', 'since' => '1.94', 'notes' => 'Somebody sent a form. Payload: `id` (the submission), `form_id`, `tenant_id`, `actor_profile_id` (null when it was anonymous), `route_id` (null when it has none).'],
        'form.submitted.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'submission.reviewed' => ['group' => 'Routes and forms', 'kind' => 'observe', 'payload' => ['id', 'form_id', 'tenant_id', 'actor_profile_id', 'route_id', 'step_id', 'decided'], 'async' => 'submission.reviewed.async', 'since' => '1.94', 'notes' => 'The office recorded its answers on a submission, at a step of its route, after the act committed. Payload: `id`, `form_id`, `tenant_id`, `actor_profile_id`, `route_id`, `step_id`, `decided` (the step\'s outcome, or null while it is still open). Never the answers.'],
        'submission.reviewed.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'submission.withdrawn' => ['group' => 'Routes and forms', 'kind' => 'observe', 'payload' => ['id', 'form_id', 'tenant_id', 'actor_profile_id'], 'async' => 'submission.withdrawn.async', 'since' => '1.94', 'notes' => 'The person who sent a submission withdrew it. Payload: `id`, `form_id`, `tenant_id`, `actor_profile_id`.'],
        'submission.withdrawn.async' => ['group' => 'Routes and forms', 'kind' => 'queued', 'since' => '1.94', 'notes' => 'Fired after the change is saved. Payloads carry ids, never content: read what you need through the API, as the person or plugin you act for. Every route payload has `route_id`, `subject_type`, `subject_id`, `document_id` (null for a form submission with no …'],
        'calendar.event.created' => ['group' => 'Calendar and bookings', 'kind' => 'observe', 'async' => 'calendar.event.created.async', 'since' => '1.98', 'notes' => 'Fired after the write commits; the return value is ignored, so a listener cannot refuse or change anything. Each has an `.async` twin on the event spine. Ids only: `id`, `tenant_id`, `profile_id` (the member of staff whose calendar it is), and for bookings …'],
        'calendar.event.created.async' => ['group' => 'Calendar and bookings', 'kind' => 'queued', 'since' => '1.98'],
        'calendar.event.updated' => ['group' => 'Calendar and bookings', 'kind' => 'observe', 'async' => 'calendar.event.updated.async', 'since' => '1.98'],
        'calendar.event.updated.async' => ['group' => 'Calendar and bookings', 'kind' => 'queued', 'since' => '1.98'],
        'calendar.event.deleted' => ['group' => 'Calendar and bookings', 'kind' => 'observe', 'async' => 'calendar.event.deleted.async', 'since' => '1.98'],
        'calendar.event.deleted.async' => ['group' => 'Calendar and bookings', 'kind' => 'queued', 'since' => '1.98'],
        'booking.created' => ['group' => 'Calendar and bookings', 'kind' => 'observe', 'async' => 'booking.created.async', 'since' => '1.98', 'notes' => 'A booking was made: `status` is `confirmed`, or `pending_payment` while a payment step runs.'],
        'booking.created.async' => ['group' => 'Calendar and bookings', 'kind' => 'queued', 'since' => '1.98'],
        'booking.confirmed' => ['group' => 'Calendar and bookings', 'kind' => 'observe', 'async' => 'booking.confirmed.async', 'since' => '1.98', 'notes' => 'A booking became confirmed: at once, or when its payment came in.'],
        'booking.confirmed.async' => ['group' => 'Calendar and bookings', 'kind' => 'queued', 'since' => '1.98'],
        'booking.rescheduled' => ['group' => 'Calendar and bookings', 'kind' => 'observe', 'async' => 'booking.rescheduled.async', 'since' => '1.98'],
        'booking.rescheduled.async' => ['group' => 'Calendar and bookings', 'kind' => 'queued', 'since' => '1.98'],
        'booking.cancelled' => ['group' => 'Calendar and bookings', 'kind' => 'observe', 'async' => 'booking.cancelled.async', 'since' => '1.98'],
        'booking.cancelled.async' => ['group' => 'Calendar and bookings', 'kind' => 'queued', 'since' => '1.98'],
        'booking.expired' => ['group' => 'Calendar and bookings', 'kind' => 'observe', 'async' => 'booking.expired.async', 'since' => '1.98', 'notes' => 'A payment hold lapsed and the time is free again.'],
        'booking.expired.async' => ['group' => 'Calendar and bookings', 'kind' => 'queued', 'since' => '1.98'],
        'agent.created' => ['group' => 'Agents', 'kind' => 'observe', 'payload' => ['tenant_id', 'agent_id', 'actor_profile_id'], 'since' => '1.75', 'notes' => 'A workspace\'s agent changed. Fired after the change is saved, and the return value is ignored: a listener can\'t refuse or undo it. Payload: `tenant_id`, `agent_id`, `actor_profile_id` (the person who made the change). Ids only: read the agent with {@see …'],
        'agent.updated' => ['group' => 'Agents', 'kind' => 'observe', 'since' => '1.75'],
        'agent.archived' => ['group' => 'Agents', 'kind' => 'observe', 'since' => '1.75'],
        'agent.restored' => ['group' => 'Agents', 'kind' => 'observe', 'since' => '1.75'],
        'agent.paused' => ['group' => 'Agents', 'kind' => 'observe', 'since' => '1.75', 'notes' => 'Paused: refused on every call, and its waiting proposals can\'t run, until resumed.'],
        'agent.resumed' => ['group' => 'Agents', 'kind' => 'observe', 'since' => '1.75'],
        'agent.proposal.created' => ['group' => 'Agent proposals', 'kind' => 'observe', 'payload' => ['tenant_id', 'proposal_id', 'agent_assignment_id', 'on_behalf_of_profile_id', 'tool'], 'since' => '1.78', 'notes' => 'Notifications only: each fires after the change is saved, the return value is ignored, and a listener can\'t refuse or change anything. Only the person who granted the agent decides a proposal, never a plugin. Ids and the tool\'s name only, never the call\'s …'],
        'agent.proposal.decided' => ['group' => 'Agent proposals', 'kind' => 'observe', 'payload' => ['tenant_id', 'proposal_id', 'decision', 'decided_by_profile_id', 'on_behalf_of_profile_id', 'tool', 'failed'], 'since' => '1.78', 'notes' => 'A proposal stopped waiting. Payload: `tenant_id`, `proposal_id`, `decision` (`approved`, `declined` or `expired`), `decided_by_profile_id` (null when it expired), `on_behalf_of_profile_id`, `tool`, `failed` (it was approved and a check refused it when it ran, …'],
        'agent.action.undone' => ['group' => 'Agent proposals', 'kind' => 'observe', 'payload' => ['tenant_id', 'action_id', 'proposal_id', 'on_behalf_of_profile_id', 'tool'], 'since' => '1.78', 'notes' => 'A person undid what an agent did for them. Payload: `tenant_id`, `action_id`, `proposal_id` (null when it ran without asking), `on_behalf_of_profile_id`, `tool`.'],
        'mcp.connection.created' => ['group' => 'AI connections', 'kind' => 'observe', 'payload' => ['tenant_id', 'connection_id', 'profile_id', 'kind'], 'since' => '1.79', 'notes' => 'An app or agent connected over MCP. Fired after the change, the return value ignored. Payload: `tenant_id`, `connection_id` (as {@see \\Whity\\Sdk\\Mcp\\McpConnections} lists it), `profile_id` (whose connection), `kind` (`user` or `ai-agent`), …'],
        'mcp.connection.grant_changed' => ['group' => 'AI connections', 'kind' => 'observe', 'since' => '1.79'],
        'mcp.connection.revoked' => ['group' => 'AI connections', 'kind' => 'observe', 'since' => '1.79', 'notes' => 'Also `by`: `owner`, `admin`, or `agent_removed` (the person stopped using the agent).'],
        'plugin.settings.changed' => ['group' => 'AI connections', 'kind' => 'observe', 'payload' => ['tenant_id', 'plugin', 'key', 'actor_profile_id'], 'since' => '1.88', 'notes' => 'A plugin setting changed (SDK 1.88). Fired after the change, the return value ignored. Payload: `tenant_id` (null when the deployment\'s own value changed, which every workspace without its own follows), `plugin` (slug), `key` (bare), `actor_profile_id` (null …'],
        'navigation.register' => ['group' => 'Platform', 'kind' => 'filter', 'since' => '1.0'],
        'permission.registered' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'datatype.registered' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.89', 'notes' => 'Boot-time announcements (SDK 1.89). Each names the `source` that registered and what it registered; the return value is ignored.'],
        'ou_type.registered' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'routing_rule.registered' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'window_type.registered' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'rbac.resource_types.registered' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'health.probes.registered' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'settings.declared' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'tenant.tables.registered' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'worker.boot' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'worker.request.start' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'worker.request.end' => ['group' => 'Platform', 'kind' => 'observe', 'since' => '1.0'],
        'stock.moving' => ['group' => 'Inventory', 'kind' => 'veto', 'payload' => ['tenant_id', 'batch_key', 'source_type', 'source_id', 'reason_code', 'acting_profile_id', 'occurred_at', 'lines'], 'since' => '1.127', 'notes' => 'Before stock moves post, inside their transaction (SDK 1.127). Payload: `tenant_id`, `batch_key`, `source_type`, `source_id`, `reason_code`, `acting_profile_id`, `occurred_at`, `lines` (`variant_id`, `from_location_id`, `to_location_id`, `quantity` in base-unit steps). Throw `HookVetoException` to refuse the whole post.'],
        'stock.moved' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'batch_key', 'source_type', 'source_id', 'acting_profile_id', 'move_ids', 'moves'], 'async' => 'stock.moved.async', 'since' => '1.127', 'notes' => 'Inside the transaction, after the moves and levels are written. Payload: `tenant_id`, `batch_key`, `source_type`, `source_id`, `acting_profile_id`, `move_ids`, `moves`. One event per post.'],
        'stock.moved.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.127'],
        'inventory.item.created' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'item_id', 'variant_ids', 'acting_profile_id'], 'async' => 'inventory.item.created.async', 'since' => '1.127', 'notes' => 'Payload: `tenant_id`, `item_id`, `variant_ids`, `acting_profile_id`.'],
        'inventory.item.created.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.127'],
        'inventory.item.updated' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'item_id', 'changed', 'acting_profile_id'], 'async' => 'inventory.item.updated.async', 'since' => '1.127', 'notes' => 'Payload: `tenant_id`, `item_id`, `changed` (field names), `acting_profile_id`.'],
        'inventory.item.updated.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.127'],
        'inventory.item.archived' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'item_id', 'acting_profile_id'], 'async' => 'inventory.item.archived.async', 'since' => '1.127', 'notes' => 'Payload: `tenant_id`, `item_id`, `acting_profile_id`.'],
        'inventory.item.archived.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.127'],
        'inventory.operation.done' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'operation_id', 'kind', 'number', 'batch_key', 'acting_profile_id'], 'async' => 'inventory.operation.done.async', 'since' => '1.128', 'notes' => 'An adjustment, count or transfer is done: its stock has moved, or for a transfer arrived (SDK 1.128). Payload: `tenant_id`, `operation_id`, `kind`, `number`, `batch_key`, `acting_profile_id`.'],
        'inventory.operation.done.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.128'],
        'inventory.count.differences' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'operation_id', 'number', 'location_id', 'waiting_approval', 'lines'], 'async' => 'inventory.count.differences.async', 'since' => '1.128', 'notes' => 'A finished count found differences, before they post or wait for approval. Payload: `tenant_id`, `operation_id`, `number`, `location_id`, `waiting_approval`, `lines` (`variant_id`, `expected`, `counted`, `difference`, `value_minor`, `currency`).'],
        'inventory.count.differences.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.128'],
        'stock.level.below_reorder' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'rule_id', 'variant_id', 'location_id', 'available', 'min_quantity', 'order_up_to', 'suggested_quantity', 'supplier_contact_id'], 'async' => 'stock.level.below_reorder.async', 'since' => '1.128', 'notes' => 'Available stock at a place went under its reorder level; once, until it comes back. Payload: `tenant_id`, `rule_id`, `variant_id`, `location_id`, `available`, `min_quantity`, `order_up_to`, `suggested_quantity`, `supplier_contact_id`.'],
        'stock.level.below_reorder.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.128'],
        'stock.level.restored' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'rule_id', 'variant_id', 'location_id', 'available', 'min_quantity'], 'async' => 'stock.level.restored.async', 'since' => '1.128', 'notes' => 'Back at or above the reorder level. Payload: `tenant_id`, `rule_id`, `variant_id`, `location_id`, `available`, `min_quantity`.'],
        'stock.level.restored.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.128'],
        'stock.lot.expiring' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'lot_id', 'variant_id', 'item_id', 'code', 'expires_on', 'on_hand'], 'async' => 'stock.lot.expiring.async', 'since' => '1.137', 'notes' => 'A batch with stock expires within the workspace\'s warning window (7 days unless changed); once per batch, from the daily job. Payload: `tenant_id`, `lot_id`, `variant_id`, `item_id`, `code`, `expires_on`, `on_hand`.'],
        'stock.lot.expiring.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.137'],
        'stock.lot.expired' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'stock.lot.expired.async', 'since' => '1.137', 'notes' => 'A batch with stock passed its expiry date; once, the day after. Payload as `stock.lot.expiring`.'],
        'stock.lot.expired.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.137'],
        'inventory.order.submitting' => ['group' => 'Inventory', 'kind' => 'veto', 'payload' => ['tenant_id', 'order_id', 'kind', 'number', 'contact_id', 'location_id', 'currency', 'total_minor', 'acting_profile_id', 'lines'], 'since' => '1.129', 'notes' => 'Before an order goes to its route or is approved (SDK 1.129). Payload: `tenant_id`, `order_id`, `kind`, `number`, `contact_id`, `location_id`, `currency`, `total_minor`, `acting_profile_id`, `lines` (`variant_id`, `quantity`, `price_minor`, `line_total_minor`). Throw `HookVetoException` to refuse it (409 with your reason).'],
        'inventory.order.created' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'inventory.order.created.async', 'since' => '1.129', 'notes' => 'An order was drafted. Every `inventory.order.*` notification carries `tenant_id`, `order_id`, `kind`, `number`, `contact_id`, `status`, `acting_profile_id`.'],
        'inventory.order.created.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.129'],
        'inventory.order.approved' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'inventory.order.approved.async', 'since' => '1.129', 'notes' => 'Approved by its route, or on sending when the workspace has none.'],
        'inventory.order.approved.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.129'],
        'inventory.order.rejected' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'inventory.order.rejected.async', 'since' => '1.129', 'notes' => 'Turned down on its route; back in draft.'],
        'inventory.order.rejected.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.129'],
        'inventory.order.partly_done' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'inventory.order.partly_done.async', 'since' => '1.129', 'notes' => 'Part of it was received. Also `operation_id` (the receipt).'],
        'inventory.order.partly_done.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.129'],
        'inventory.order.done' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'inventory.order.done.async', 'since' => '1.129', 'notes' => 'Every line received or closed. Also `operation_id` when a receipt finished it.'],
        'inventory.order.done.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.129'],
        'inventory.order.cancelled' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'inventory.order.cancelled.async', 'since' => '1.129', 'notes' => 'Cancelled before anything arrived.'],
        'inventory.order.cancelled.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.129'],
        'sales.quote.sending' => ['group' => 'Inventory', 'kind' => 'veto', 'payload' => ['tenant_id', 'quote_id', 'number', 'revision', 'contact_id', 'currency', 'total_minor', 'valid_until', 'acting_profile_id', 'lines'], 'since' => '1.132', 'notes' => 'Before a quote goes to the customer, its first send or a revision (SDK 1.132). Payload: `tenant_id`, `quote_id`, `number`, `revision`, `contact_id`, `currency`, `total_minor`, `valid_until`, `acting_profile_id`, `lines` (`variant_id` or null, `description`, `quantity`, `price_minor`, `line_total_minor`). Throw `HookVetoException` to refuse it (409 with your reason).'],
        'sales.quote.created' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'sales.quote.created.async', 'since' => '1.132', 'notes' => 'A quote or a revision was drafted. Every `sales.quote.*` notification carries `tenant_id`, `quote_id`, `number` (null until sent), `revision`, `contact_id`, `status`, `total_minor`, `currency`, `acting_profile_id` (null when the customer answered online).'],
        'sales.quote.created.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.132'],
        'sales.quote.sent' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'sales.quote.sent.async', 'since' => '1.132', 'notes' => 'Sent to the customer, with its number.'],
        'sales.quote.sent.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.132'],
        'sales.quote.accepted' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'sales.quote.accepted.async', 'since' => '1.132', 'notes' => 'The customer accepted, online or as a person recorded. Also `decided_by_name`, `online`.'],
        'sales.quote.accepted.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.132'],
        'sales.quote.declined' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'sales.quote.declined.async', 'since' => '1.132', 'notes' => 'The customer declined. Also `decided_by_name`, `online`, `reason`.'],
        'sales.quote.declined.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.132'],
        'sales.quote.expired' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'sales.quote.expired.async', 'since' => '1.132', 'notes' => 'Past its valid-until date without an answer.'],
        'sales.quote.expired.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.132'],
        'sales.quote.converted' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'sales.quote.converted.async', 'since' => '1.132', 'notes' => 'Turned into a target. Also `target` (`sales_order`, `invoice`) and `target_id`.'],
        'sales.quote.converted.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.132'],
        'sales.quote.cancelled' => ['group' => 'Inventory', 'kind' => 'observe', 'async' => 'sales.quote.cancelled.async', 'since' => '1.132', 'notes' => 'Withdrawn before the customer answered.'],
        'sales.quote.cancelled.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.132'],
        'calendar.commission.closed' => ['group' => 'Inventory', 'kind' => 'observe', 'payload' => ['tenant_id', 'close_id', 'from', 'to', 'acting_profile_id', 'lines'], 'async' => 'calendar.commission.closed.async', 'since' => '1.148', 'notes' => 'An owner closed a commission period (WHIT-1334). Carries `tenant_id`, `close_id`, `from`, `to`, `acting_profile_id` and `lines` (`profile_id`, `currency`, `bookings`, `sales_minor`, `commission_minor`).'],
        'calendar.commission.closed.async' => ['group' => 'Inventory', 'kind' => 'queued', 'since' => '1.148'],
        'accounting.entry.posting' => ['group' => 'Accounting', 'kind' => 'veto', 'payload' => ['tenant_id', 'journal', 'date', 'memo', 'source_type', 'source_id', 'acting_profile_id', 'reverses_entry_id', 'lines'], 'since' => '1.154', 'notes' => 'Before a journal entry posts, inside its transaction (SDK 1.154). Payload: `tenant_id`, `journal`, `date`, `memo`, `source_type`, `source_id`, `acting_profile_id`, `reverses_entry_id`, `lines` (`account_id`, `debit_minor`, `credit_minor`, `contact_id`, `ou_id`). Throw `HookVetoException` to refuse the entry.'],
        'accounting.entry.posted' => ['group' => 'Accounting', 'kind' => 'observe', 'payload' => ['tenant_id', 'entry_id', 'number', 'journal', 'date', 'source_type', 'source_id', 'reverses_entry_id', 'total_minor', 'currency', 'acting_profile_id'], 'async' => 'accounting.entry.posted.async', 'since' => '1.154', 'notes' => 'Inside the transaction, after the entry, its lines and the balances are written. Payload: `tenant_id`, `entry_id`, `number`, `journal`, `date`, `source_type`, `source_id`, `reverses_entry_id`, `total_minor`, `currency`, `acting_profile_id`.'],
        'accounting.entry.posted.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.154'],
        'accounting.entry.reversed' => ['group' => 'Accounting', 'kind' => 'observe', 'payload' => ['tenant_id', 'entry_id', 'reversal_entry_id', 'acting_profile_id'], 'async' => 'accounting.entry.reversed.async', 'since' => '1.154', 'notes' => 'Payload: `tenant_id`, `entry_id` (the original), `reversal_entry_id`, `acting_profile_id`.'],
        'accounting.entry.reversed.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.154'],
        'accounting.period.closed' => ['group' => 'Accounting', 'kind' => 'observe', 'payload' => ['tenant_id', 'period_id', 'starts_on', 'ends_on', 'acting_profile_id'], 'async' => 'accounting.period.closed.async', 'since' => '1.154', 'notes' => 'A month was closed. Payload: `tenant_id`, `period_id`, `starts_on`, `ends_on`, `acting_profile_id`.'],
        'accounting.period.closed.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.154'],
        'accounting.period.reopened' => ['group' => 'Accounting', 'kind' => 'observe', 'async' => 'accounting.period.reopened.async', 'since' => '1.154', 'notes' => 'A closed month was reopened. Payload as closed, plus `reason`.'],
        'accounting.period.reopened.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.154'],
        'accounting.invoice.issuing' => ['group' => 'Accounting', 'kind' => 'veto', 'payload' => ['lines'], 'since' => '1.155', 'notes' => 'Before an invoice or credit note is issued (numbered, frozen and posted) (SDK 1.155). Payload as `accounting.invoice.issued`, plus `lines` (`variant_id`, `description`, `quantity`, `unit_price_minor`, `net_minor`, `tax_code`, `tax_minor`, `total_minor`). Throw `HookVetoException` to refuse it (a credit limit).'],
        'accounting.invoice.issued' => ['group' => 'Accounting', 'kind' => 'observe', 'payload' => ['tenant_id', 'invoice_id', 'kind', 'number', 'contact_id', 'status', 'issue_date', 'due_date', 'currency', 'total_minor', 'tax_minor', 'due_minor', 'credits_invoice_id', 'acting_profile_id'], 'async' => 'accounting.invoice.issued.async', 'since' => '1.155', 'notes' => 'An invoice or credit note was issued. Payload: `tenant_id`, `invoice_id`, `kind` (`sale`, `sale_credit`), `number`, `contact_id`, `status`, `issue_date`, `due_date`, `currency`, `total_minor`, `tax_minor`, `due_minor`, `credits_invoice_id`, `acting_profile_id`.'],
        'accounting.invoice.issued.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.155'],
        'accounting.invoice.credited' => ['group' => 'Accounting', 'kind' => 'observe', 'async' => 'accounting.invoice.credited.async', 'since' => '1.155', 'notes' => 'A credit note settled an invoice. Payload as issued (the invoice), plus `credit_note_id` and `amount_minor`.'],
        'accounting.invoice.credited.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.155'],
        'accounting.invoice.paid' => ['group' => 'Accounting', 'kind' => 'observe', 'async' => 'accounting.invoice.paid.async', 'since' => '1.156', 'notes' => 'Payments settled all of an invoice (SDK 1.156). Payload as `accounting.invoice.issued`.'],
        'accounting.invoice.paid.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.156'],
        'accounting.invoice.partly_paid' => ['group' => 'Accounting', 'kind' => 'observe', 'async' => 'accounting.invoice.partly_paid.async', 'since' => '1.156', 'notes' => 'A payment settled part of an invoice. Payload as `accounting.invoice.issued`.'],
        'accounting.invoice.partly_paid.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.156'],
        'accounting.payment.recorded' => ['group' => 'Accounting', 'kind' => 'observe', 'payload' => ['tenant_id', 'payment_id', 'direction', 'number', 'contact_id', 'paid_on', 'amount_minor', 'currency', 'method', 'status', 'cheque_status', 'unallocated_minor', 'acting_profile_id'], 'async' => 'accounting.payment.recorded.async', 'since' => '1.156', 'notes' => 'A payment was recorded and posted (money received or a transfer). Payload: `tenant_id`, `payment_id`, `direction`, `number`, `contact_id`, `paid_on`, `amount_minor`, `currency`, `method`, `status`, `cheque_status`, `unallocated_minor`, `acting_profile_id`.'],
        'accounting.payment.recorded.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.156'],
        'accounting.payment.allocated' => ['group' => 'Accounting', 'kind' => 'observe', 'async' => 'accounting.payment.allocated.async', 'since' => '1.156', 'notes' => 'A payment settled an invoice. Payload as recorded, plus `invoice_id` and `amount_minor` (the share).'],
        'accounting.payment.allocated.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.156'],
        'accounting.cheque.bounced' => ['group' => 'Accounting', 'kind' => 'observe', 'async' => 'accounting.cheque.bounced.async', 'since' => '1.156', 'notes' => 'A cheque bounced: its entries were reversed and the invoices it paid reopened. Payload as recorded, plus `fee_minor` and `reopened_invoice_ids`.'],
        'accounting.cheque.bounced.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.156'],
        'accounting.bill.posting' => ['group' => 'Accounting', 'kind' => 'veto', 'since' => '1.157', 'notes' => 'Before a supplier bill or credit is posted (SDK 1.157). Payload as `accounting.invoice.issuing`, with `kind` `purchase` or `purchase_credit`. Throw `HookVetoException` to refuse it.'],
        'accounting.bill.posted' => ['group' => 'Accounting', 'kind' => 'observe', 'async' => 'accounting.bill.posted.async', 'since' => '1.157', 'notes' => 'A supplier bill or credit was posted. Payload as `accounting.invoice.issued`.'],
        'accounting.bill.posted.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.157'],
        'accounting.bill.paid' => ['group' => 'Accounting', 'kind' => 'observe', 'async' => 'accounting.bill.paid.async', 'since' => '1.157', 'notes' => 'Payments settled all of a bill. Payload as `accounting.invoice.issued`.'],
        'accounting.bill.paid.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.157'],
        'accounting.expense.claimed' => ['group' => 'Accounting', 'kind' => 'observe', 'async' => 'accounting.expense.claimed.async', 'since' => '1.158', 'notes' => 'A person filed an expense claim (SDK 1.158). Payload as `accounting.invoice.issued` (a `purchase`), plus `claimant_profile_id`.'],
        'accounting.expense.claimed.async' => ['group' => 'Accounting', 'kind' => 'queued', 'since' => '1.158'],
    ];

    private function __construct()
    {
    }

    /** @return array<string, EventInfo> every event, in the order of {@see Events} */
    public static function all(): array
    {
        $all = [];
        foreach (array_keys(self::EVENTS) as $name) {
            $all[$name] = self::build($name);
        }

        return $all;
    }

    /** The event's entry, or null for a name core doesn't publish. */
    public static function get(string $name): ?EventInfo
    {
        return isset(self::EVENTS[$name]) ? self::build($name) : null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::EVENTS);
    }

    private static function build(string $name): EventInfo
    {
        $entry = self::EVENTS[$name];
        $base = str_ends_with($name, '.async') ? substr($name, 0, -6) : null;
        $payload = $entry['payload'] ?? [];
        $async = $entry['async'] ?? null;
        $notes = $entry['notes'] ?? '';

        return new EventInfo(
            $name,
            $entry['group'],
            $entry['kind'],
            $entry['kind'] === self::VETO || ($entry['vetoable'] ?? false) === true,
            is_array($payload) ? array_values(array_filter($payload, 'is_string')) : [],
            is_string($async) ? $async : null,
            $base !== null && isset(self::EVENTS[$base]) ? $base : null,
            $entry['since'],
            is_string($notes) ? $notes : '',
        );
    }
}
