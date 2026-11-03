'use client';

import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Badge } from '@whity/ui/badge';
import { Card, CardContent } from '@whity/ui/card';
import { TechnicalDetails, useDisplayPreferences } from '@whity/ui/display-preferences';
import { AreaError, EmptyFiltered, LoadingRows } from '@whity/ui/page-states';
import { SearchField } from '@whity/ui/search-field';
import { Switch } from '@whity/ui/switch';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import type { McpAgentPolicy, McpListSize, McpTool, McpToolListResponse, McpToolVisibility } from './types';

/** The setting that holds the plugin tools this workspace lists directly (WHIT-754). */
const ALWAYS_ON_SETTING = 'mcp.always_on_plugin_tools';

type PolicyFilter = 'all' | McpAgentPolicy;

/**
 * What AI can do (A22, TplWhatAICanDo; was MCP tools): every action AI can
 * take in this workspace and how agents may use it (can do it, proposes and a
 * person approves, suggests only, never), from `agentPolicy`. Tool names,
 * toolsets, how apps discover each one, what it needs and the list sizes show
 * only with Show technical details (A.3). A plain name and area per action,
 * and how long Undo lasts ("If it goes wrong"), are not returned by the API
 * yet (S6), so the action reads from its description. A plugin tool that asks
 * to be listed directly is listed only once an administrator turns it on, in
 * the technical view (docs/design/MCP-TOOL-SURFACE.md §5.10, owner decision 5).
 */
export default function McpToolsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const language = useFormattingLocale();
  const { showTechnicalDetails } = useDisplayPreferences();
  const [saving, setSaving] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [policy, setPolicy] = useState<PolicyFilter>('all');

  const { data, loading: isLoading, error, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/admin/mcp/tools');
    if (!response.ok) {
      throw new Error(t('mcpTools.error.load', 'What AI can do could not be loaded.'));
    }
    return (await response.json()) as McpToolListResponse;
  }, [apiClient]);

  const tools = useMemo(() => data?.data ?? [], [data]);
  const sizes = data?.sizes;

  useEffect(() => {
    if (error) addToast(error, 'error');
  }, [error, addToast]);

  const shown = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return tools.filter((tool) => {
      if (policy !== 'all' && tool.agentPolicy !== policy) return false;
      if (needle === '') return true;
      return [tool.description, showTechnicalDetails ? tool.name : '', showTechnicalDetails ? tool.toolset : ''].some((text) =>
        text.toLowerCase().includes(needle),
      );
    });
  }, [tools, query, policy, showTechnicalDetails]);

  const setAlwaysOn = async (tool: McpTool, on: boolean) => {
    const enabled = tools
      .filter((other) => other.alwaysOnRequested && other.alwaysOnEnabled && other.name !== tool.name)
      .map((other) => other.name);
    if (on) enabled.push(tool.name);
    setSaving(tool.name);
    try {
      const response = await apiClient('/api/v1/settings', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ settings: { [ALWAYS_ON_SETTING]: enabled.join(',') } }),
      });
      if (!response.ok) {
        const body = (await response.json().catch(() => ({}))) as { message?: string };
        throw new Error(body.message ?? t('mcpTools.alwaysOn.saveFailed', 'Could not change how {name} is listed', { name: tool.name }));
      }
      addToast(
        on
          ? t('mcpTools.alwaysOn.turnedOn', 'Apps now see {name} without searching.', { name: tool.name })
          : t('mcpTools.alwaysOn.turnedOff', 'Apps now find {name} through search.', { name: tool.name }),
        'success',
      );
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error ? err.message : t('mcpTools.alwaysOn.saveFailed', 'Could not change how {name} is listed', { name: tool.name }),
        'error',
      );
    } finally {
      setSaving(null);
    }
  };

  const accessLabel = (tool: McpTool): string => {
    if (tool.requiredPermission) return tool.requiredPermission;
    if (tool.requiredRole) return t('mcpTools.access.role', 'role: {role}', { role: tool.requiredRole });
    return t('mcpTools.access.open', 'open');
  };

  const visibilityLabel = (visibility: McpToolVisibility): string =>
    ({
      'always-on': t('mcpTools.visibility.alwaysOn', 'Always listed'),
      discoverable: t('mcpTools.visibility.discoverable', 'Found by search'),
      off: t('mcpTools.visibility.off', 'Not offered'),
    })[visibility] ?? visibility;

  const policyLabel = (value: McpAgentPolicy): string =>
    ({
      allow: t('mcpTools.agentPolicy.allow', 'Can do it'),
      propose: t('mcpTools.agentPolicy.propose', 'Proposes, a person approves'),
      'suggest-only': t('mcpTools.agentPolicy.suggestOnly', 'Suggests only'),
      refuse: t('mcpTools.agentPolicy.refuse', 'Never'),
    })[value] ?? value;

  const policyVariant = (value: McpAgentPolicy) => (value === 'allow' ? 'success' : value === 'refuse' ? 'outline' : 'warning');

  const technicalColumns: DataTableColumn<McpTool>[] = [
    {
      id: 'toolset',
      header: t('mcpTools.table.toolset', 'Toolset'),
      cell: (tool) => <code className="text-xs">{tool.toolset || '-'}</code>,
    },
    {
      id: 'listed',
      header: t('mcpTools.table.listed', 'How apps see it'),
      cell: (tool) =>
        tool.alwaysOnRequested ? (
          <span className="inline-flex items-center gap-2">
            <Switch
              checked={tool.alwaysOnEnabled}
              disabled={saving !== null}
              onCheckedChange={(checked) => void setAlwaysOn(tool, checked)}
              aria-label={t('mcpTools.alwaysOn.toggle', 'List {name} without searching', { name: tool.name })}
            />
            <span className="text-sm">{tool.alwaysOnEnabled ? visibilityLabel('always-on') : visibilityLabel('discoverable')}</span>
          </span>
        ) : (
          <span className="text-sm">
            {visibilityLabel(tool.alwaysOnEnabled ? 'always-on' : tool.visibility === 'always-on' ? 'discoverable' : tool.visibility)}
          </span>
        ),
    },
    {
      id: 'access',
      header: t('mcpTools.table.access', 'Needs'),
      cell: (tool) => (
        <Badge variant={tool.requiredPermission !== null || tool.requiredRole !== null ? 'secondary' : 'outline'} className="font-mono">
          {accessLabel(tool)}
        </Badge>
      ),
    },
  ];

  const columns: DataTableColumn<McpTool>[] = [
    {
      accessorKey: 'description',
      header: t('mcpTools.table.action', 'Action'),
      enableSorting: true,
      cell: (tool) => (
        <span className="block max-w-xl space-y-0.5">
          <span className="block text-sm font-medium text-foreground">{tool.description || tool.name}</span>
          <TechnicalDetails>
            <code dir="ltr" className="block text-xs text-muted-foreground">
              {tool.name}
            </code>
          </TechnicalDetails>
        </span>
      ),
    },
    {
      accessorKey: 'agentPolicy',
      header: t('mcpTools.table.agentPolicy', 'Agents'),
      enableSorting: true,
      cell: (tool) => <Badge variant={policyVariant(tool.agentPolicy)}>{policyLabel(tool.agentPolicy)}</Badge>,
    },
    ...(showTechnicalDetails ? technicalColumns : []),
  ];

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('mcpTools.title', 'What AI can do')}
        description={t(
          'mcpTools.description',
          'Every action AI can take here, and how agents may use it. An app never does more than the person who connected it can.',
        )}
      />

      <TechnicalDetails>
        {sizes !== undefined && (
          <div className="grid gap-3 sm:grid-cols-3">
            <SizeCard
              title={t('mcpTools.sizes.default', 'Standard connection')}
              help={t('mcpTools.sizes.defaultHelp', 'A few everyday actions, plus search for the rest. What the connect wizard hands out.')}
              size={sizes.default}
              language={language ?? 'en'}
            />
            <SizeCard
              title={t('mcpTools.sizes.full', 'Every action at once')}
              help={t('mcpTools.sizes.fullHelp', 'Only for apps that need the whole list (?tools=full). Slower, and some apps drop actions past a limit.')}
              size={sizes.full}
              language={language ?? 'en'}
            />
            <SizeCard
              title={t('mcpTools.sizes.discover', 'Search only')}
              help={t('mcpTools.sizes.discoverHelp', 'Search and use, nothing else (?tools=discover).')}
              size={sizes.discover}
              language={language ?? 'en'}
            />
          </div>
        )}
      </TechnicalDetails>

      {error !== null ? (
        <AreaError message={error} retryLabel={t('agents.retry', 'Try again')} onRetry={refetch} />
      ) : isLoading ? (
        <LoadingRows
          headers={[t('mcpTools.table.action', 'Action'), t('mcpTools.table.agentPolicy', 'Agents')]}
          label={t('agents.loading', 'Loading…')}
        />
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <SearchField
              className="w-full max-w-sm"
              value={query}
              onValueChange={setQuery}
              label={t('mcpTools.searchPlaceholder', 'Search actions')}
              placeholder={t('mcpTools.searchPlaceholder', 'Search actions')}
              clearLabel={t('agents.search.clear', 'Clear search')}
            />
            <ToggleGroup
              label={t('mcpTools.filter.label', 'Agents may')}
              value={policy}
              required
              onChange={(value) => typeof value === 'string' && setPolicy(value as PolicyFilter)}
              options={[
                { value: 'all', label: t('mcpTools.filter.all', 'All') },
                { value: 'allow', label: t('mcpTools.filter.allow', 'Do it') },
                { value: 'propose', label: t('mcpTools.filter.propose', 'Propose') },
                { value: 'suggest-only', label: t('mcpTools.filter.suggest', 'Suggest') },
                { value: 'refuse', label: t('mcpTools.filter.refuse', 'Never') },
              ]}
            />
          </div>
          {shown.length === 0 ? (
            <EmptyFiltered
              title={t('mcpTools.empty.title', 'No actions match')}
              description={t('mcpTools.empty.description', 'Try another search or show all actions.')}
              clearLabel={t('agents.filtered.clear', 'Clear filters')}
              onClear={() => {
                setQuery('');
                setPolicy('all');
              }}
            />
          ) : (
            <DataTable columns={columns} data={shown} getRowId={(tool) => tool.name} pagination={{ pageSize: 15 }} />
          )}
        </>
      )}
    </div>
  );
}

/** What one list mode costs an app before it does anything; tokens are roughly bytes / 4. */
function SizeCard({ title, help, size, language }: { title: string; help: string; size: McpListSize; language: string }) {
  const t = useTranslation('admin');
  return (
    <Card>
      <CardContent className="space-y-1 p-4">
        <p className="text-sm font-medium">{title}</p>
        <p className="text-sm">
          {t('mcpTools.sizes.value', '{tools} actions, about {tokens} tokens', {
            tools: size.tools,
            tokens: Math.round(size.bytes / 4).toLocaleString(language === 'ar' ? 'ar-u-nu-latn' : 'en'),
          })}
        </p>
        <p className="text-xs text-muted-foreground">{help}</p>
      </CardContent>
    </Card>
  );
}
