'use client';

import Link from 'next/link';
import { useMemo, useState } from 'react';
import { Button } from '@whity/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { Icon } from '@whity/ui/icon';
import { AccessDeniedPanel, AreaError, EmptyFirstTime, LoadingRows } from '@whity/ui/page-states';
import { SearchField } from '@whity/ui/search-field';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useAuth } from '@/lib/auth-context';
import { isReadPermission } from '@/components/agents/workspace-agents';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { MCP_TOKENS_MANAGE } from '@/lib/capabilities';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { ConnectWizard } from './connect-wizard';
import { RevokeAiPrincipalModal } from './revoke-modal';
import type { AiPrincipal, AiPrincipalListResponse } from './types';

/** Scope entries that don't narrow what an app reaches: capabilities and the agent's grant. */
const NOT_NARROWING = ['tools:call', 'resources:read', 'prompts:read', 'grant:'];

/**
 * Connected AI apps (A20, TplConnectedApps; was AI principals): outside apps
 * signed in to Whity, each for one person. The connect wizard issues the
 * credential (WHIT-697); disconnecting asks first (tier 2). Kind and the user
 * number are technical details (A.3). "May do" puts the app's scope in words;
 * the app's own page (What it may do) holds the grant.
 */
export default function AiPrincipalsPage() {
  const { apiClient, user } = useAuth();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const canManage = hasPermission(MCP_TOKENS_MANAGE);

  const [isWizardOpen, setIsWizardOpen] = useState(false);
  const [renewing, setRenewing] = useState<AiPrincipal | null>(null);
  const [revoking, setRevoking] = useState<AiPrincipal | null>(null);
  const [query, setQuery] = useState('');

  // Sort and search run on the client over one fetch of the server's largest
  // page; `GET /api/v1/admin/mcp/tokens` also accepts `sort`, `dir` and `q`
  // (#1102) when this table is wired for server-side paging.
  const { data, loading, error, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/admin/mcp/tokens?per_page=100');
    if (response.status === 403) throw new Error('forbidden');
    if (!response.ok) throw new Error(t('aiPrincipals.error.load', 'The connected apps could not be loaded.'));
    return ((await response.json()) as AiPrincipalListResponse).data ?? [];
  }, [apiClient]);

  // Names, never ids: the people the apps are for. Left empty when the viewer
  // can't read users, and the column then says so rather than showing a number.
  const people = useFetch<Record<number, string>>(async () => {
    const result = await fetchAllPages<{ id: number; name?: string; email?: string }>(apiClient, '/api/v1/users');
    const names: Record<number, string> = {};
    for (const person of result.items) names[person.id] = person.name || person.email || '';
    return names;
  }, [apiClient]);

  const principals = useMemo(() => data ?? [], [data]);
  const shown = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return needle === '' ? principals : principals.filter((principal) => principal.name.toLowerCase().includes(needle));
  }, [principals, query]);

  const whoFor = (principal: AiPrincipal): string => {
    if (user !== null && principal.userId === user.id) return t('aiPrincipals.for.you', 'You');
    return people.data?.[principal.userId] || t('aiPrincipals.for.other', 'Another person');
  };

  /**
   * The wizard's three choices, read back from what it issued: everything the
   * person can do, reads only, or a narrower list. An app's writes ask first
   * whichever it is, so this says what it reaches, not what it runs alone.
   */
  const mayDo = (scope: string[]): string => {
    const narrowing = scope.filter((entry) => !NOT_NARROWING.some((prefix) => entry === prefix || entry.startsWith(prefix)));
    if (narrowing.length === 0) return t('aiPrincipals.mayDo.everything', 'Everything this person can do');
    return narrowing.every(isReadPermission) ? t('aiPrincipals.mayDo.readOnly', 'Only looks things up') : t('aiPrincipals.mayDo.some', 'Some areas only');
  };

  const columns: DataTableColumn<AiPrincipal>[] = [
    {
      accessorKey: 'name',
      header: t('aiPrincipals.table.app', 'App'),
      enableSorting: true,
      cell: (row: AiPrincipal) => (
        <span className="flex items-center gap-3">
          <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-control bg-primary-tint text-sm font-semibold text-primary-tint-foreground">
            {row.name.charAt(0).toUpperCase()}
          </span>
          <span className="min-w-0">
            {row.principalKind === 'ai-agent' ? (
              <Link href={`/admin/ai-principals/${encodeURIComponent(row.jti)}`} className="font-medium text-primary hover:underline">
                {row.name}
              </Link>
            ) : (
              <span className="font-medium">{row.name}</span>
            )}
            <TechnicalDetails>
              <span dir="ltr" className="block font-mono text-xs text-muted-foreground">
                {row.principalKind} · {row.jti}
              </span>
            </TechnicalDetails>
          </span>
        </span>
      ),
    },
    {
      id: 'for',
      header: t('aiPrincipals.table.for', 'For'),
      cell: (row: AiPrincipal) => (
        <span>
          {whoFor(row)}
          <TechnicalDetails>
            <span dir="ltr" className="block font-mono text-xs text-muted-foreground">
              {t('aiPrincipals.table.userId', 'User ID')} {row.userId}
            </span>
          </TechnicalDetails>
        </span>
      ),
    },
    {
      id: 'mayDo',
      header: t('aiPrincipals.table.mayDo', 'May do'),
      cell: (row: AiPrincipal) => <span className="text-sm">{mayDo(row.scope)}</span>,
    },
    // #1068: dates go together when this tenant hides them.
    ...dates.dateColumns<AiPrincipal>([
      {
        id: 'lastUsedAt',
        header: t('aiPrincipals.table.lastUsed', 'Last used'),
        value: (row) => row.lastUsedAt,
        withTime: true,
        enableSorting: true,
      },
      {
        id: 'expiresAt',
        header: t('aiPrincipals.table.expires', 'Expires'),
        value: (row) => row.expiresAt,
        withTime: false,
        enableSorting: true,
      },
    ]),
  ];

  const rowActions = (principal: AiPrincipal) => {
    if (!canManage) return null;
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" aria-label={t('aiPrincipals.row.menu', 'More actions')}>
            <Icon name="more" size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {/* Renewing issues a new token as the caller, so it is offered only
              on the caller's own connections: renewing a colleague's would
              silently move their app onto the admin's account. */}
          {user !== null && principal.userId === user.id && (
            <DropdownMenuItem onClick={() => setRenewing(principal)}>{t('aiPrincipals.action.renew', 'Renew')}</DropdownMenuItem>
          )}
          <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => setRevoking(principal)}>
            {t('aiPrincipals.action.disconnect', 'Disconnect')}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  const connect = (
    <Button onClick={() => setIsWizardOpen(true)}>
      <Icon name="link" size={16} />
      {t('aiPrincipals.header.connect', 'Connect an AI app')}
    </Button>
  );

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('aiPrincipals.title', 'Connected AI apps')}
        description={t('aiPrincipals.description', 'Outside AI apps signed in to Whity, each for one person. An app can never do more than that person can.')}
        action={canManage ? connect : undefined}
      />

      {error === 'forbidden' ? (
        <AccessDeniedPanel
          title={t('aiPrincipals.denied.title', 'You can’t see connected apps')}
          description={t('aiPrincipals.denied.description', 'Only people who manage AI connections for this workspace can. Ask them for access.')}
        />
      ) : error !== null ? (
        <AreaError message={error} retryLabel={t('agents.retry', 'Try again')} onRetry={refetch} />
      ) : loading ? (
        <LoadingRows
          headers={[t('aiPrincipals.table.app', 'App'), t('aiPrincipals.table.for', 'For'), t('aiPrincipals.table.lastUsed', 'Last used')]}
          label={t('agents.loading', 'Loading…')}
        />
      ) : principals.length === 0 ? (
        <EmptyFirstTime
          icon="link"
          title={t('aiPrincipals.empty.title', 'No AI apps connected')}
          description={t('aiPrincipals.empty.description', 'Connect Claude, ChatGPT, Cursor or another app so it can work with your data, asking you first for changes.')}
        />
      ) : (
        <>
          <SearchField
            className="w-full max-w-sm"
            value={query}
            onValueChange={setQuery}
            label={t('aiPrincipals.searchPlaceholder', 'Search connected apps')}
            placeholder={t('aiPrincipals.searchPlaceholder', 'Search connected apps')}
            clearLabel={t('agents.search.clear', 'Clear search')}
          />
          <DataTable
            columns={columns}
            data={shown}
            getRowId={(principal) => String(principal.id)}
            rowActions={canManage ? rowActions : undefined}
            pagination={{ pageSize: 10 }}
            emptyState={{
              title: t('aiPrincipals.filtered.title', 'No apps match'),
              description: t('aiPrincipals.filtered.description', 'Try another name.'),
            }}
          />
        </>
      )}

      <ConnectWizard
        isOpen={isWizardOpen || renewing !== null}
        onOpenChange={(open) => {
          setIsWizardOpen(open);
          if (!open) setRenewing(null);
        }}
        onConnected={refetch}
        renew={renewing === null ? null : { jti: renewing.jti, name: renewing.name, scope: renewing.scope }}
      />

      {revoking !== null && (
        <RevokeAiPrincipalModal
          isOpen
          onOpenChange={(open) => !open && setRevoking(null)}
          principal={revoking}
          onSuccess={() => {
            setRevoking(null);
            refetch();
          }}
        />
      )}
    </div>
  );
}
