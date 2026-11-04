'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { FieldSelect } from '@/components/field-select';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import { Pagination } from '@/components/ui/pagination';
import { TechnicalDetails, useDisplayPreferences } from '@whity/ui/display-preferences';
import { AccessDeniedPanel, AreaError, EmptyFiltered, EmptyFirstTime, LoadingRows } from '@whity/ui/page-states';
import { IconDownload, IconRefresh } from '@tabler/icons-react';
import { AuditFeed } from './audit-feed';
import type { AuditLogEntry, AuditLogFacets, AuditLogFilters, AuditLogResponse } from './types';

const EMPTY_FILTERS: AuditLogFilters = {
  action: '',
  targetType: '',
  actor: '',
  agent: '',
  area: '',
  when: '',
  from: '',
  to: '',
};

const PER_PAGE = 25;

/** "When" as the card draws it. Picking one fills `from`; a deep link can set `from` and `to` itself. */
const WHEN_DAYS: Readonly<Record<string, number>> = { today: 0, week: 7, month: 30 };

/** Radix forbids an empty item value, so "everyone" and friends need one of their own. */
const ANY = 'any';

function startOfDayDaysAgo(days: number): string {
  const day = new Date();
  day.setDate(day.getDate() - days);
  day.setHours(0, 0, 0, 0);
  return day.toISOString();
}

function queryOf(filters: AuditLogFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.action) params.set('action', filters.action);
  if (filters.targetType) params.set('target_type', filters.targetType);
  if (filters.actor) params.set('actor', filters.actor);
  if (filters.agent) params.set('agent', filters.agent);
  if (filters.area) params.set('area', filters.area);
  const from = filters.when in WHEN_DAYS ? startOfDayDaysAgo(WHEN_DAYS[filters.when]) : filters.from;
  if (from) params.set('from', from);
  if (filters.to) params.set('to', filters.to);
  return params;
}

/**
 * The audit log (W.4, TplAuditLog): what happened, in sentences grouped by day,
 * with filters for who, agent, area and when, and a CSV of the same rows.
 *
 * The server writes the sentences and scopes every row: a workspace sees its own,
 * the system tenant sees all. Codes, ids and addresses need Show technical details.
 */
export default function AuditLogsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const { showTechnicalDetails } = useDisplayPreferences();

  // DEEP-LINKABLE FILTERS. Another screen can send somebody here already narrowed:
  // `/admin/audit-logs?action=notification.` is how the delivery page hands off
  // its trail. Read once, as the starting point; the page is theirs from then on.
  const searchParams = useSearchParams();
  const [filters, setFilters] = useState<AuditLogFilters>(() => ({
    ...EMPTY_FILTERS,
    action: searchParams.get('action') ?? '',
    targetType: searchParams.get('target_type') ?? '',
    actor: searchParams.get('actor') ?? '',
    agent: searchParams.get('agent') ?? '',
    area: searchParams.get('area') ?? '',
    from: searchParams.get('from') ?? '',
    to: searchParams.get('to') ?? '',
  }));
  const [page, setPage] = useState(1);
  const [exporting, setExporting] = useState(false);

  const [entries, setEntries] = useState<AuditLogEntry[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadFailed, setLoadFailed] = useState<'denied' | 'failed' | null>(null);
  const [total, setTotal] = useState(0);

  const { data: facets } = useFetch<AuditLogFacets | null>(async () => {
    const response = await apiClient('/api/v1/audit-logs/facets');
    if (!response.ok) return null;
    return ((await response.json()) as { data: AuditLogFacets }).data;
  }, []);

  const fetchEntries = useCallback(async () => {
    setIsLoading(true);
    const params = queryOf(filters);
    params.set('page', String(page));
    params.set('per_page', String(PER_PAGE));
    try {
      const response = await apiClient(`/api/v1/audit-logs?${params.toString()}`);
      if (!response.ok) {
        setLoadFailed(response.status === 403 ? 'denied' : 'failed');
        return;
      }
      const data: AuditLogResponse = await response.json();
      setLoadFailed(null);
      setEntries(data.data ?? []);
      setTotal(data.pagination?.total ?? 0);
    } catch {
      setLoadFailed('failed');
    } finally {
      setIsLoading(false);
    }
  }, [apiClient, filters, page]);

  useEffect(() => {
    // Deferred so the loading state isn't set synchronously inside the effect.
    let active = true;
    void Promise.resolve().then(() => {
      if (active) void fetchEntries();
    });
    return () => {
      active = false;
    };
  }, [fetchEntries]);

  const setFilter = (key: keyof AuditLogFilters, value: string) => {
    setPage(1);
    setFilters((previous) => ({ ...previous, [key]: value === ANY ? '' : value }));
  };

  const isFiltered = useMemo(() => Object.values(filters).some((value) => value !== ''), [filters]);

  const clearFilters = () => {
    setPage(1);
    setFilters(EMPTY_FILTERS);
  };

  const exportCsv = async () => {
    setExporting(true);
    const params = queryOf(filters);
    if (showTechnicalDetails) params.set('technical', '1');
    try {
      const response = await apiClient(`/api/v1/audit-logs/export?${params.toString()}`);
      if (response.status === 422) {
        addToast(t('auditLogs.export.tooMany', 'That is too many entries to export at once. Choose a shorter period.'), 'error');
        return;
      }
      if (!response.ok) {
        addToast(t('auditLogs.export.failed', 'The export did not work. Try again.'), 'error');
        return;
      }
      const url = URL.createObjectURL(await response.blob());
      const link = window.document.createElement('a');
      link.href = url;
      link.download = 'audit-log.csv';
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 10_000);
    } catch {
      addToast(t('auditLogs.export.failed', 'The export did not work. Try again.'), 'error');
    } finally {
      setExporting(false);
    }
  };

  const whoOptions = [
    { value: ANY, label: t('auditLogs.filter.anyone', 'Anyone') },
    ...(facets?.people ?? []).map((person) => ({ value: String(person.id), label: person.name })),
  ];
  const agentOptions = [
    { value: ANY, label: t('auditLogs.filter.anyAgent', 'Any agent') },
    ...(facets?.agents ?? []).map((agent) => ({ value: agent.id, label: agent.name })),
  ];
  const areaOptions = [
    { value: ANY, label: t('auditLogs.filter.anyArea', 'Every area') },
    ...(facets?.areas ?? []).map((area) => ({ value: area.id, label: area.name })),
  ];
  const whenOptions = [
    { value: ANY, label: t('auditLogs.filter.anyTime', 'Any time') },
    { value: 'today', label: t('auditLogs.filter.today', 'Today') },
    { value: 'week', label: t('auditLogs.filter.week', 'Last 7 days') },
    { value: 'month', label: t('auditLogs.filter.month', 'Last 30 days') },
  ];

  const body = () => {
    if (loadFailed === 'denied') {
      return (
        <AccessDeniedPanel
          title={t('auditLogs.denied.title', 'You can’t open the audit log')}
          description={t('auditLogs.denied.description', 'Ask someone who manages this workspace to give you permission to read it.')}
        />
      );
    }
    if (loadFailed === 'failed') {
      return (
        <AreaError
          message={t('auditLogs.error.fetch', 'Failed to fetch audit logs')}
          retryLabel={t('auditLogs.error.retry', 'Try again')}
          onRetry={() => void fetchEntries()}
        />
      );
    }
    if (isLoading && entries.length === 0) {
      return (
        <LoadingRows
          headers={[t('auditLogs.table.time', 'Time'), t('auditLogs.loading.what', 'What happened')]}
          label={t('auditLogs.loading.label', 'Loading the audit log')}
        />
      );
    }
    if (entries.length === 0) {
      return isFiltered ? (
        <EmptyFiltered
          title={t('auditLogs.emptyFiltered', 'Nothing matches these filters')}
          clearLabel={t('auditLogs.filter.clear', 'Clear')}
          onClear={clearFilters}
        />
      ) : (
        <EmptyFirstTime
          title={t('auditLogs.empty', 'No audit entries found')}
          description={t('auditLogs.emptyDescription', 'Changes to access, settings and records will show here.')}
        />
      );
    }
    return (
      <>
        <AuditFeed entries={entries} />
        <Pagination page={page} total={total} perPage={PER_PAGE} onPageChange={setPage} />
      </>
    );
  };

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('auditLogs.title', 'Audit Logs')}
        description={t(
          'auditLogs.descriptionPlain',
          'Everything people and agents did that changes access, settings or records.'
        )}
        action={
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => void fetchEntries()} className="gap-2">
              <IconRefresh size={18} />
              {t('auditLogs.action.refresh', 'Refresh')}
            </Button>
            <Button variant="outline" onClick={() => void exportCsv()} disabled={exporting} className="gap-2">
              <IconDownload size={18} />
              {t('auditLogs.action.export', 'Export')}
            </Button>
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="audit-who" className="text-sm font-medium text-muted-foreground">
            {t('auditLogs.filter.who', 'Who')}
          </label>
          <FieldSelect id="audit-who" value={filters.actor || ANY} options={whoOptions} onChange={(v) => setFilter('actor', v)} />
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor="audit-agent" className="text-sm font-medium text-muted-foreground">
            {t('auditLogs.filter.agent', 'Agent')}
          </label>
          <FieldSelect id="audit-agent" value={filters.agent || ANY} options={agentOptions} onChange={(v) => setFilter('agent', v)} />
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor="audit-area" className="text-sm font-medium text-muted-foreground">
            {t('auditLogs.filter.area', 'Area')}
          </label>
          <FieldSelect id="audit-area" value={filters.area || ANY} options={areaOptions} onChange={(v) => setFilter('area', v)} />
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor="audit-when" className="text-sm font-medium text-muted-foreground">
            {t('auditLogs.filter.when', 'When')}
          </label>
          <FieldSelect id="audit-when" value={filters.when || ANY} options={whenOptions} onChange={(v) => setFilter('when', v)} />
        </div>
        <TechnicalDetails>
          <div className="flex flex-col gap-1">
            <label htmlFor="audit-action" className="text-sm font-medium text-muted-foreground">
              {t('auditLogs.filter.action', 'Action')}
            </label>
            <Input
              id="audit-action"
              dir="ltr"
              value={filters.action}
              onChange={(e) => setFilter('action', e.target.value)}
              placeholder={t('auditLogs.filter.actionPlaceholder', 'e.g. auth.login.success')}
            />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="audit-target-type" className="text-sm font-medium text-muted-foreground">
              {t('auditLogs.filter.targetType', 'Target Type')}
            </label>
            <Input
              id="audit-target-type"
              dir="ltr"
              value={filters.targetType}
              onChange={(e) => setFilter('targetType', e.target.value)}
              placeholder={t('auditLogs.filter.targetTypePlaceholder', 'e.g. role')}
            />
          </div>
        </TechnicalDetails>
      </div>

      {body()}
    </div>
  );
}
