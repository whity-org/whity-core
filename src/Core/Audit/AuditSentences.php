<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

/**
 * What an audit action says in words, and which area it belongs to (W.4).
 *
 * A template names its people with `{actor}` and `{target}`, so a client can
 * set them in bold and a CSV can print them plain. An action this table doesn't
 * know (a plugin's own event) reads as the actor and the action's words; it is
 * never dropped from the trail.
 */
final class AuditSentences
{
    public const AREAS = ['security', 'people', 'documents', 'agents', 'plugins', 'billing', 'workspace', 'other'];

    /**
     * @i18n-keys admin
     *   audit.actor.agent = An agent
     *   audit.actor.agentFor = {agent} for {person}
     *   audit.actor.someone = Someone
     *   audit.actor.system = Whity
     *   audit.area.agents = AI and agents
     *   audit.area.billing = Billing
     *   audit.area.documents = Documents
     *   audit.area.other = Other
     *   audit.area.people = People
     *   audit.area.plugins = Plugins
     *   audit.area.security = Sign-in and security
     *   audit.area.workspace = Workspace
     *   audit.csv.agent = Agent
     *   audit.csv.area = Area
     *   audit.csv.code = Event code
     *   audit.csv.how = How
     *   audit.csv.ip = IP address
     *   audit.csv.targetId = Target id
     *   audit.csv.targetType = Target type
     *   audit.csv.what = What happened
     *   audit.csv.when = When (UTC)
     *   audit.csv.who = Who
     *   audit.csv.workspace = Workspace
     *   audit.fallback = {actor}: {what}
     *   audit.how.agent = Through a connected agent
     *   audit.how.cli = From the command line
     *   audit.how.job = By a scheduled job
     *   audit.sentence.agent_action_undone = {actor} undid an action by {target}
     *   audit.sentence.agent_assignment_created = {actor} assigned agent {target}
     *   audit.sentence.agent_assignment_grant_changed = {actor} changed what agent {target} may do
     *   audit.sentence.agent_assignment_revoked = {actor} removed an assignment of agent {target}
     *   audit.sentence.agent_created = {actor} created agent {target}
     *   audit.sentence.agent_credential_issued = {actor} issued a credential for agent {target}
     *   audit.sentence.agent_proposal_approved = {actor} approved a proposal
     *   audit.sentence.agent_proposal_declined = {actor} declined a proposal
     *   audit.sentence.agent_updated = {actor} changed agent {target}
     *   audit.sentence.audit_exported = {actor} exported the audit log
     *   audit.sentence.auth_2fa_disabled = {actor} turned off two-step sign-in
     *   audit.sentence.auth_2fa_enabled = {actor} turned on two-step sign-in
     *   audit.sentence.auth_2fa_recovery_approved = {actor} approved a two-step recovery
     *   audit.sentence.auth_2fa_recovery_confirm_failed = A two-step recovery link failed
     *   audit.sentence.auth_2fa_recovery_forced = {actor} reset two-step sign-in for {target}
     *   audit.sentence.auth_2fa_recovery_rejected = {actor} rejected a two-step recovery
     *   audit.sentence.auth_2fa_recovery_requested = {actor} asked to recover two-step sign-in
     *   audit.sentence.auth_2fa_recovery_reset_link_sent = A two-step recovery link was sent
     *   audit.sentence.auth_2fa_recovery_submitted = {actor} sent a two-step recovery for approval
     *   audit.sentence.auth_2fa_recovery_two_factor_cleared = Two-step sign-in was cleared for {target}
     *   audit.sentence.auth_2fa_verify_failure = {actor} entered a wrong two-step code
     *   audit.sentence.auth_2fa_verify_success = {actor} confirmed a two-step code
     *   audit.sentence.auth_login_2fa_required = {actor} was asked for a two-step code
     *   audit.sentence.auth_login_failure = {actor} failed to sign in
     *   audit.sentence.auth_login_success = {actor} signed in
     *   audit.sentence.auth_password_reset_admin_requested = {actor} asked for a password reset for {target}
     *   audit.sentence.auth_password_reset_approved = {actor} approved a password reset
     *   audit.sentence.auth_password_reset_awaiting_approval_notified = Approvers were told about a password reset
     *   audit.sentence.auth_password_reset_completed = {actor} reset a password
     *   audit.sentence.auth_password_reset_failed = A password reset failed
     *   audit.sentence.auth_password_reset_rejected = {actor} rejected a password reset
     *   audit.sentence.auth_password_reset_requested = {actor} asked to reset a password
     *   audit.sentence.auth_password_reset_submitted_for_approval = A password reset is waiting for approval
     *   audit.sentence.auth_refresh_failure = {actor} could not stay signed in
     *   audit.sentence.auth_refresh_token_reuse_detected = A used sign-in token was tried again for {actor}, so the session was ended
     *   audit.sentence.auth_two_factor_policy_enrollment_required = {actor} must set up two-step sign-in
     *   audit.sentence.auth_two_factor_policy_login_refused = {actor} was refused sign-in because two-step sign-in is not set up
     *   audit.sentence.billing_plan_changed = {actor} changed the plan
     *   audit.sentence.documents_comment_reopened = {actor} reopened a comment
     *   audit.sentence.documents_comment_resolved = {actor} resolved a comment
     *   audit.sentence.documents_suggestion_created = {actor} suggested a change
     *   audit.sentence.email_verification_confirmed = {actor} confirmed an email address
     *   audit.sentence.email_verification_failed = An email confirmation failed
     *   audit.sentence.email_verification_requested = {actor} asked to confirm an email address
     *   audit.sentence.mcp_resources_read = {actor} read data through a connected AI app
     *   audit.sentence.mcp_token_grant_changed = {actor} changed what a connected AI app may do
     *   audit.sentence.mcp_tools_call = {actor} used a tool through a connected AI app
     *   audit.sentence.ou_created = {actor} created unit {target}
     *   audit.sentence.ou_deleted = {actor} deleted unit {target}
     *   audit.sentence.ou_role_assigned = {actor} gave unit {target} a role
     *   audit.sentence.ou_role_removed = {actor} took a role from unit {target}
     *   audit.sentence.ou_updated = {actor} changed unit {target}
     *   audit.sentence.plan_subscriber_moved = {actor} moved a workspace to another plan
     *   audit.sentence.plugin_enable = {actor} turned on a plugin
     *   audit.sentence.plugin_enable_migrate_failed = A plugin could not be turned on
     *   audit.sentence.plugin_secret_deleted = {actor} removed a plugin secret
     *   audit.sentence.plugin_secret_set = {actor} set a plugin secret
     *   audit.sentence.plugin_upload = {actor} uploaded a plugin
     *   audit.sentence.profile_email_added = {actor} added an email address
     *   audit.sentence.profile_email_primary_set = {actor} changed their main email address
     *   audit.sentence.profile_email_removed = {actor} removed an email address
     *   audit.sentence.profile_email_verification_resent = {actor} asked for another confirmation email
     *   audit.sentence.role_created = {actor} created role {target}
     *   audit.sentence.role_deleted = {actor} deleted role {target}
     *   audit.sentence.documents_document_withdrawn = {actor} withdrew a document
     *   audit.sentence.notification_delivery_queued = {actor} queued a notification
     *   audit.sentence.notification_delivery_sent = {actor} sent a notification
     *   audit.sentence.notification_delivery_failed = {actor}: a notification could not be sent
     *   audit.sentence.role_updated = {actor} changed role {target}
     *   audit.sentence.security_2fa_policy_created = {actor} added a two-step rule
     *   audit.sentence.security_2fa_policy_deleted = {actor} removed a two-step rule
     *   audit.sentence.security_2fa_policy_reminded = {actor} reminded {target} to set up two-step sign-in
     *   audit.sentence.security_2fa_policy_updated = {actor} changed a two-step rule
     *   audit.sentence.settings_mail_oauth_credentials_changed = {actor} changed the OAuth sign-in for outgoing email
     *   audit.sentence.taxonomy_tag_deleted = {actor} deleted a tag
     *   audit.sentence.taxonomy_tag_group_deleted = {actor} deleted a tag group
     *   audit.sentence.tenant_created = {actor} created workspace {target}
     *   audit.sentence.tenant_deleted = {actor} deleted workspace {target}
     *   audit.sentence.tenant_invitation_accept_failed = An invitation could not be accepted
     *   audit.sentence.tenant_invitation_accepted = {actor} accepted an invitation
     *   audit.sentence.tenant_invitation_created = {actor} invited someone
     *   audit.sentence.tenant_invitation_resent = {actor} sent an invitation again
     *   audit.sentence.tenant_invitation_revoked = {actor} cancelled an invitation
     *   audit.sentence.tenant_updated = {actor} changed workspace {target}
     *   audit.sentence.user_created = {actor} added {target}
     *   audit.sentence.user_deleted = {actor} removed {target}
     *   audit.sentence.user_group_deleted = {actor} deleted a group
     *   audit.sentence.user_membership_added = {actor} added {target} to the workspace
     *   audit.sentence.user_membership_removed = {actor} removed {target} from the workspace
     *   audit.sentence.user_updated = {actor} changed {target}
     *   audit.target.agent = an agent
     *   audit.target.person = a person
     *   audit.target.record = a record
     *   audit.target.role = a role
     *   audit.target.unit = a unit
     *   audit.target.workspace = a workspace
     *
     * @var array<string, string>
     */
    private const TEMPLATES = [
        'agent.action.undone' => '{actor} undid an action by {target}',
        'agent.assignment.created' => '{actor} assigned agent {target}',
        'agent.assignment.grant_changed' => '{actor} changed what agent {target} may do',
        'agent.assignment.revoked' => '{actor} removed an assignment of agent {target}',
        'agent.created' => '{actor} created agent {target}',
        'agent.credential.issued' => '{actor} issued a credential for agent {target}',
        'agent.proposal.approved' => '{actor} approved a proposal',
        'agent.proposal.declined' => '{actor} declined a proposal',
        'agent.updated' => '{actor} changed agent {target}',
        'audit.exported' => '{actor} exported the audit log',
        'auth.2fa.disabled' => '{actor} turned off two-step sign-in',
        'auth.2fa.enabled' => '{actor} turned on two-step sign-in',
        'auth.2fa.verify_failure' => '{actor} entered a wrong two-step code',
        'auth.2fa.verify_success' => '{actor} confirmed a two-step code',
        'auth.2fa_recovery.approved' => '{actor} approved a two-step recovery',
        'auth.2fa_recovery.confirm_failed' => 'A two-step recovery link failed',
        'auth.2fa_recovery.forced' => '{actor} reset two-step sign-in for {target}',
        'auth.2fa_recovery.rejected' => '{actor} rejected a two-step recovery',
        'auth.2fa_recovery.requested' => '{actor} asked to recover two-step sign-in',
        'auth.2fa_recovery.reset_link_sent' => 'A two-step recovery link was sent',
        'auth.2fa_recovery.submitted' => '{actor} sent a two-step recovery for approval',
        'auth.2fa_recovery.two_factor_cleared' => 'Two-step sign-in was cleared for {target}',
        'auth.login.2fa_required' => '{actor} was asked for a two-step code',
        'auth.login.failure' => '{actor} failed to sign in',
        'auth.login.success' => '{actor} signed in',
        'auth.password_reset.admin_requested' => '{actor} asked for a password reset for {target}',
        'auth.password_reset.approved' => '{actor} approved a password reset',
        'auth.password_reset.awaiting_approval_notified' => 'Approvers were told about a password reset',
        'auth.password_reset.completed' => '{actor} reset a password',
        'auth.password_reset.failed' => 'A password reset failed',
        'auth.password_reset.rejected' => '{actor} rejected a password reset',
        'auth.password_reset.requested' => '{actor} asked to reset a password',
        'auth.password_reset.submitted_for_approval' => 'A password reset is waiting for approval',
        'auth.refresh.failure' => '{actor} could not stay signed in',
        'auth.refresh_token_reuse_detected' => 'A used sign-in token was tried again for {actor}, so the session was ended',
        'auth.two_factor_policy.enrollment_required' => '{actor} must set up two-step sign-in',
        'auth.two_factor_policy.login_refused' => '{actor} was refused sign-in because two-step sign-in is not set up',
        'billing.plan_changed' => '{actor} changed the plan',
        'documents.comment.reopened' => '{actor} reopened a comment',
        'documents.comment.resolved' => '{actor} resolved a comment',
        'documents.suggestion.created' => '{actor} suggested a change',
        'email.verification.confirmed' => '{actor} confirmed an email address',
        'email.verification.failed' => 'An email confirmation failed',
        'email.verification.requested' => '{actor} asked to confirm an email address',
        'mcp.resources.read' => '{actor} read data through a connected AI app',
        'mcp.token.grant_changed' => '{actor} changed what a connected AI app may do',
        'mcp.tools.call' => '{actor} used a tool through a connected AI app',
        'ou.created' => '{actor} created unit {target}',
        'ou.deleted' => '{actor} deleted unit {target}',
        'ou.role_assigned' => '{actor} gave unit {target} a role',
        'ou.role_removed' => '{actor} took a role from unit {target}',
        'ou.updated' => '{actor} changed unit {target}',
        'plan.subscriber.moved' => '{actor} moved a workspace to another plan',
        'plugin.enable' => '{actor} turned on a plugin',
        'plugin.enable.migrate_failed' => 'A plugin could not be turned on',
        'plugin.secret.deleted' => '{actor} removed a plugin secret',
        'plugin.secret.set' => '{actor} set a plugin secret',
        'plugin.upload' => '{actor} uploaded a plugin',
        'profile_email.added' => '{actor} added an email address',
        'profile_email.primary_set' => '{actor} changed their main email address',
        'profile_email.removed' => '{actor} removed an email address',
        'profile_email.verification_resent' => '{actor} asked for another confirmation email',
        'role.created' => '{actor} created role {target}',
        'role.deleted' => '{actor} deleted role {target}',
        'documents.document.withdrawn' => '{actor} withdrew a document',
        'notification.delivery.queued' => '{actor} queued a notification',
        'notification.delivery.sent' => '{actor} sent a notification',
        'notification.delivery.failed' => '{actor}: a notification could not be sent',
        'role.updated' => '{actor} changed role {target}',
        'security.2fa_policy.created' => '{actor} added a two-step rule',
        'security.2fa_policy.deleted' => '{actor} removed a two-step rule',
        'security.2fa_policy.reminded' => '{actor} reminded {target} to set up two-step sign-in',
        'security.2fa_policy.updated' => '{actor} changed a two-step rule',
        'settings.mail_oauth_credentials_changed' => '{actor} changed the OAuth sign-in for outgoing email',
        'taxonomy.tag.deleted' => '{actor} deleted a tag',
        'taxonomy.tag_group.deleted' => '{actor} deleted a tag group',
        'tenant.created' => '{actor} created workspace {target}',
        'tenant.deleted' => '{actor} deleted workspace {target}',
        'tenant.invitation.accept_failed' => 'An invitation could not be accepted',
        'tenant.invitation.accepted' => '{actor} accepted an invitation',
        'tenant.invitation.created' => '{actor} invited someone',
        'tenant.invitation.resent' => '{actor} sent an invitation again',
        'tenant.invitation.revoked' => '{actor} cancelled an invitation',
        'tenant.updated' => '{actor} changed workspace {target}',
        'user.created' => '{actor} added {target}',
        'user.deleted' => '{actor} removed {target}',
        'user.membership.added' => '{actor} added {target} to the workspace',
        'user.membership.removed' => '{actor} removed {target} from the workspace',
        'user.updated' => '{actor} changed {target}',
        'user_group.deleted' => '{actor} deleted a group',
    ];

    /**
     * Action prefixes per area. First match wins, so `tenant.invitation.` sits
     * before `tenant.`; anything else is `other`.
     *
     * @var array<string, list<string>>
     */
    private const AREA_PREFIXES = [
        'security' => ['auth.', 'security.', 'email.verification.', 'profile_email.'],
        'people' => ['tenant.invitation.', 'user.', 'user_group.', 'role.', 'ou.', 'delegation.', 'relation.'],
        'documents' => ['documents.', 'document.', 'data_type.', 'forms.', 'form.'],
        'agents' => ['agent.', 'mcp.'],
        'plugins' => ['plugin.'],
        'billing' => ['billing.', 'plan.', 'subscription.'],
        'workspace' => ['tenant.', 'taxonomy.', 'settings.', 'audit.', 'notification.'],
    ];

    public static function template(string $action): ?string
    {
        return self::TEMPLATES[$action] ?? null;
    }

    public static function key(string $action): string
    {
        return 'audit.sentence.' . str_replace('.', '_', $action);
    }

    public static function areaOf(string $action): string
    {
        foreach (self::AREA_PREFIXES as $area => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $area;
                }
            }
        }

        return 'other';
    }

    /**
     * The action prefixes of one area, or null for `other` or an unknown area.
     *
     * @return list<string>|null
     */
    public static function prefixes(string $area): ?array
    {
        return self::AREA_PREFIXES[$area] ?? null;
    }

    /**
     * The prefixes of the areas listed before this one. Filtering by an area has
     * to skip them, or `workspace`'s `tenant.` would also match the invitations
     * that `people` claims first.
     *
     * @return list<string>
     */
    public static function earlierPrefixes(string $area): array
    {
        $earlier = [];
        foreach (self::AREA_PREFIXES as $name => $prefixes) {
            if ($name === $area) {
                break;
            }
            array_push($earlier, ...$prefixes);
        }

        return $earlier;
    }

    /**
     * Every prefix of the named areas, which `other` is the opposite of.
     *
     * @return list<string>
     */
    public static function allPrefixes(): array
    {
        return array_merge(...array_values(self::AREA_PREFIXES));
    }

    /** "plugin.acme.thing_done" -> "Plugin acme thing done". */
    public static function humanize(string $action): string
    {
        $words = trim((string) preg_replace('/[._:-]+/', ' ', $action));

        return $words === '' ? $action : ucfirst($words);
    }
}
