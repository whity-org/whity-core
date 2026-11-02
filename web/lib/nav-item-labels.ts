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
      inbox: t('sidebar.item.inbox', 'Inbox'),
      approvals: t('sidebar.item.approvals', 'Approvals'),
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
      'plugin-store': t('sidebar.item.pluginStore', 'Plugin Store'),
      'website-settings': t('sidebar.item.websiteSettings', 'Website Settings'),
      'document-library': t('sidebar.item.documentLibrary', 'Documents'),
      'document-templates': t('sidebar.item.documentTemplates', 'Document templates'),
      'document-sources': t('sidebar.item.documentSources', 'Notes and wiki'),
      'approval-gating': t('sidebar.item.approvalGating', 'Approval Gating'),
      'my-agents': t('sidebar.item.myAgents', 'My agents'),
      agents: t('sidebar.item.agents', 'Agents'),
      'ai-principals': t('sidebar.item.aiPrincipals', 'AI Principals'),
      'mcp-tools': t('sidebar.item.mcpTools', 'MCP Tools'),
      languages: t('sidebar.item.languages', 'Languages'),
      translations: t('sidebar.item.translations', 'Translations'),
      settings: t('sidebar.item.settings', 'Settings'),
    }),
    [t],
  );
}
