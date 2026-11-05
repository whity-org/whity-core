'use client';

import { useEffect, useMemo, useState } from 'react';
import { LoadProblem } from '@/components/load-problem';
import { throwUnlessOk } from '@/lib/forbidden';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Badge } from '@whity/ui/badge';
import { Card, CardContent } from '@whity/ui/card';
import { TechnicalDetails, useDisplayPreferences } from '@whity/ui/display-preferences';
import { EmptyFiltered, LoadingRows } from '@whity/ui/page-states';
import { SearchField } from '@whity/ui/search-field';
import { Switch } from '@whity/ui/switch';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { undoWindowWords, type ProposalWords } from '@/components/agents/proposals';
import { localized } from '@/components/agents/workspace-agents';
import type { McpAgentAccess, McpListSize, McpTool, McpToolListResponse, McpToolVisibility } from './types';

/** The setting that holds the plugin tools this workspace lists directly (WHIT-754). */
const ALWAYS_ON_SETTING = 'mcp.always_on_plugin_tools';

type PolicyFilter = 'all' | McpAgentAccess;

const ALL_AREAS = 'all';

/**
 * What AI can do (A22, TplWhatAICanDo; was MCP tools): every action AI can
 * take in this workspace, its area, how agents may use it (can do it, proposes
 * and a person approves, suggests only, never) and what happens if it goes
 * wrong. `agentAccess` is the server's own answer, from the check every call
 * runs. Tool names, toolsets, how apps discover each one, what it needs and the
 * list sizes show only with Show technical details (A.3). A plugin tool that asks
 * to be listed directly is listed only once an administrator turns it on, in
 * the technical view (docs/design/MCP-TOOL-SURFACE.md §5.10, owner decision 5).
 */
export default function McpToolsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const language = useFormattingLocale();
  const arabic = language === 'ar';
  const { showTechnicalDetails } = useDisplayPreferences();
  const [saving, setSaving] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [policy, setPolicy] = useState<PolicyFilter>('all');
  const [area, setArea] = useState(ALL_AREAS);

  const { data, loading: isLoading, error, forbidden, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/admin/mcp/tools');
    throwUnlessOk(response, t('mcpTools.error.load', 'What AI can do could not be loaded.'));
    return (await response.json()) as McpToolListResponse;
  }, [apiClient]);

  const tools = useMemo(() => data?.data ?? [], [data]);
  const sizes = data?.sizes;
  const nameOf = (tool: McpTool): string => localized(tool.plainName, tool.plainNameAr, arabic);
  const areaOf = (tool: McpTool): string => localized(tool.area.title, tool.area.titleAr, arabic);
  const areas = useMemo(() => {
    const byId = new Map<string, string>();
    for (const tool of tools) byId.set(tool.area.id, localized(tool.area.title, tool.area.titleAr, arabic));
    return [...byId.entries()].sort((a, b) => a[1].localeCompare(b[1]));
  }, [tools, arabic]);

  useEffect(() => {
    if (error && !forbidden) addToast(error, 'error');
  }, [error, forbidden, addToast]);

  const shown = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return tools.filter((tool) => {
      if (policy !== 'all' && tool.agentAccess !== policy) return false;
      if (area !== ALL_AREAS && tool.area.id !== area) return false;
      if (needle === '') return true;
      return [tool.plainName, tool.plainNameAr ?? '', showTechnicalDetails ? tool.name : '', showTechnicalDetails ? tool.toolset : ''].some((text) =>
        text.toLowerCase().includes(needle),
      );
    });
  }, [tools, query, policy, area, showTechnicalDetails]);

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

  const accessLabelOf = (value: McpAgentAccess): string =>
    ({
      can: t('mcpTools.agentPolicy.allow', 'Can do it'),
      proposes: t('mcpTools.agentPolicy.propose', 'Proposes, a person approves'),
      suggests: t('mcpTools.agentPolicy.suggestOnly', 'Suggests only'),
      never: t('mcpTools.agentPolicy.refuse', 'Never'),
    })[value];

  const accessVariant = (value: McpAgentAccess) => (value === 'can' ? 'success' : value === 'never' ? 'outline' : value === 'suggests' ? 'secondary' : 'warning');

  const undoWords = {
    minute: t('agentActivity.undo.minute', '1 minute'),
    minutes: t('agentActivity.undo.minutes', '{n} minutes'),
    hour: t('agentActivity.undo.hour', '1 hour'),
    hours: t('agentActivity.undo.hours', '{n} hours'),
    day: t('agentActivity.undo.day', '1 day'),
    days: t('agentActivity.undo.days', '{n} days'),
  } as ProposalWords;

  /** "If it goes wrong" (AI.4). */
  const ifItGoesWrong = (tool: McpTool): string => {
    if (tool.readOnly) return t('mcpTools.wrong.read', 'Undo not needed');
    if (tool.agentAccess === 'suggests') return t('mcpTools.wrong.suggests', 'A person accepts each suggestion');
    const window = undoWindowWords(tool.undoWindowSeconds, undoWords);
    return window !== undefined ? t('mcpTools.wrong.undo', 'Undo for {time}', { time: window }) : t('mcpTools.wrong.none', 'Can’t be undone');
  };

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
      accessorKey: 'plainName',
      header: t('mcpTools.table.action', 'Action'),
      enableSorting: true,
      cell: (tool) => (
        <span className="block max-w-xl space-y-0.5">
          <span className="block text-sm font-medium text-foreground">{nameOf(tool)}</span>
          <TechnicalDetails>
            <code dir="ltr" className="block text-xs text-muted-foreground">
              {tool.name}
            </code>
          </TechnicalDetails>
        </span>
      ),
    },
    {
      id: 'area',
      header: t('mcpTools.table.area', 'Area'),
      cell: (tool) => <span className="text-sm">{areaOf(tool)}</span>,
    },
    {
      accessorKey: 'agentAccess',
      header: t('mcpTools.table.agentPolicy', 'Agents'),
      enableSorting: true,
      cell: (tool) => <Badge variant={accessVariant(tool.agentAccess)}>{accessLabelOf(tool.agentAccess)}</Badge>,
    },
    {
      id: 'wrong',
      header: t('mcpTools.table.wrong', 'If it goes wrong'),
      cell: (tool) => <span className="text-sm">{ifItGoesWrong(tool)}</span>,
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
        <LoadProblem forbidden={forbidden} message={error} onRetry={refetch} area={t('mcpTools.area', 'what AI can do')} />
      ) : isLoading ? (
        <LoadingRows
          headers={[t('mcpTools.table.action', 'Action'), t('mcpTools.table.area', 'Area'), t('mcpTools.table.agentPolicy', 'Agents')]}
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
            <Select value={area} onValueChange={setArea}>
              <SelectTrigger aria-label={t('mcpTools.table.area', 'Area')} className="w-56">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL_AREAS}>{t('mcpTools.filter.allAreas', 'All areas')}</SelectItem>
                {areas.map(([id, title]) => (
                  <SelectItem key={id} value={id}>
                    {title}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <ToggleGroup
              label={t('mcpTools.filter.label', 'Agents may')}
              value={policy}
              required
              onChange={(value) => typeof value === 'string' && setPolicy(value as PolicyFilter)}
              options={[
                { value: 'all', label: t('mcpTools.filter.all', 'All') },
                { value: 'can', label: t('mcpTools.filter.allow', 'Do it') },
                { value: 'proposes', label: t('mcpTools.filter.propose', 'Propose') },
                { value: 'suggests', label: t('mcpTools.filter.suggest', 'Suggest') },
                { value: 'never', label: t('mcpTools.filter.refuse', 'Never') },
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
                setArea(ALL_AREAS);
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
