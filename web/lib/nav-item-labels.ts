'use client';

import { useMemo } from 'react';
import { useTranslation } from '@whity/features/i18n';

/**
 * Translated names for core navigation items, by item id: the sidebar and the
 * command palette (⌘K) both show them, and the server sends English only.
 *
 * Literal `t()` calls, not a computed key: `i18n:extract` rebuilds the
 * catalogue by scanning the source for them, so a dynamic
 * `t('sidebar.item.' + id)` would never reach the translations table.
 * An id missing here falls back to the label the server sent.
 */
export function useNavItemLabels(): Record<string, string> {
  const t = useTranslation('common');
  return useMemo<Record<string, string>>(
    () => ({
      dashboard: t('sidebar.item.dashboard', 'Dashboard'),
      system: t('sidebar.item.system', 'System'),
      inbox: t('sidebar.item.inbox', 'Inbox'),
      calendar: t('sidebar.item.calendar', 'Calendar'),
      tasks: t('sidebar.item.tasks', 'Tasks'),
      approvals: t('sidebar.item.approvals', 'Approvals'),
      messages: t('sidebar.item.messages', 'Messages'),
      'my-messages': t('sidebar.item.myMessages', 'Messages'),
      users: t('sidebar.item.users', 'Users'),
      roles: t('sidebar.item.roles', 'Roles'),
      ous: t('sidebar.item.ous', 'Organizational Units'),
      delegations: t('sidebar.item.delegations', 'Delegations'),
      relations: t('sidebar.item.relations', 'Family Relations'),
      'tag-groups': t('sidebar.item.tagGroups', 'Tag Groups'),
      tags: t('sidebar.item.tags', 'Tags'),
      tenants: t('sidebar.item.tenants', 'Tenants'),
      'audit-logs': t('sidebar.item.auditLogs', 'Audit Logs'),
      errors: t('sidebar.item.errors', 'Errors'),
      plugins: t('sidebar.item.plugins', 'Plugins'),
      'website-settings': t('sidebar.item.websiteSettings', 'Website Settings'),
      'document-library': t('sidebar.item.documentLibrary', 'Documents'),
      'document-templates': t('sidebar.item.documentTemplates', 'Document templates'),
      'document-sources': t('sidebar.item.documentSources', 'Notes and wiki'),
      'approval-gating': t('sidebar.item.approvalGating', 'Approval Gating'),
      'my-agents': t('sidebar.item.myAgents', 'Agents'),
      agents: t('sidebar.item.agents', 'Agents'),
      'ai-principals': t('sidebar.item.aiPrincipals', 'Connected AI apps'),
      'mcp-tools': t('sidebar.item.mcpTools', 'What AI can do'),
      languages: t('sidebar.item.languages', 'Languages'),
      'platform-email': t('sidebar.item.platformEmail', 'Email'),
      'platform-storage': t('sidebar.item.platformStorage', 'Storage'),
      'platform-feature-flags': t('sidebar.item.platformFeatureFlags', 'Feature flags'),
      'member-forms': t('sidebar.item.memberForms', 'Forms'),
      translations: t('sidebar.item.translations', 'Translations'),
      settings: t('sidebar.item.settings', 'Settings'),
      'forms-builder': t('sidebar.item.formsBuilder', 'Form builder'),
      'forms-catalog': t('sidebar.item.formsCatalog', 'Forms'),
      'my-form-submissions': t('sidebar.item.myFormSubmissions', 'My submissions'),
      'tenant-billing': t('sidebar.item.tenantBilling', 'Billing'),
      'user-groups': t('sidebar.item.userGroups', 'User groups'),
      notifications: t('sidebar.item.notifications', 'Notification delivery'),
      'document-route-templates': t('sidebar.item.documentRouteTemplates', 'Route templates'),
      'convening-bodies': t('sidebar.item.conveningBodies', 'Convening bodies'),
      'convening-meetings': t('sidebar.item.conveningMeetings', 'Meetings'),
      'convening-meeting': t('sidebar.item.conveningMeeting', 'Meeting record'),
    }),
    [t],
  );
}
