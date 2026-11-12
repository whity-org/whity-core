'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Avatar } from '@whity/ui/avatar';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { useDateDisplay } from '@whity/features/datetime';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { ErrorState } from '@whity/ui/empty-state';
import { IconPlus, IconShare, IconShieldLock } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';
import { CreateDelegationModal } from './create-modal';
import { roleLabel } from '@whity/features/roles';
import { permissionInWords } from './permission-words';
import type { Delegation, UserOption } from './types';
import { AwayCard, type AwayPeriod, type AwayPerson } from './away-card';
import { fetchAllPagesTyped } from '@/lib/api/fetch-all-pages';
import { useAuth } from '@/lib/auth-context';

/**
 * Row view-model for the delegations table. Display strings are precomputed so
 * the generic DataTable (which renders raw cell values) shows readable labels
 * for the polymorphic grantee, the OU scope and the live/revoked status.
 */
interface DelegationRow {
  id: number;
  permission: string;
  /** Who gave it, by name, or a plain word when there is none to show. */
  grantor: string;
  /** The grantee's name, or a plain word when there is none to show. */
  grantee: string;
  granteeIsRole: boolean;
  scope: string;
  grantedAt: string | null;
  until: string;
  status: string;
  /** A permission delegation, or an "I'm away" period (OR.1). */
  source: Delegation | null;
  away?: AwayPeriod;
}

export default function DelegationsPage() {
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [delegations, setDelegations] = useState<Delegation[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);

  const { apiClient } = useAuth();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  // OR.1: "I'm away" periods sit in the same list, in names.
  const [awayPeriods, setAwayPeriods] = useState<AwayPeriod[]>([]);
  const [people, setPeople] = useState<AwayPerson[]>([]);
  // Ended delegations stay listed under All, for the record.
  const [showAll, setShowAll] = useState(false);
  const dates = useDateDisplay();

  // Sort/filter/pagination all still run CLIENT-side over a single fetch —
  // fetching the backend's own page-size ceiling (100) rather than its default
  // fixes the previous silent page-1-only truncation for the common case, and
  // tenants with >100 delegations remain capped.
  //
  // #1102: the backend grew that search/sort support. `GET /api/v1/delegations`
  // accepts `sort`/`dir` (permission, granteeType, granteeId, scope, status,
  // grantedAt) and `q` (permission) alongside the existing filters, and reports
  // a search-filtered total, so this screen can move to server-side sort and
  // search whenever the table is wired for it.
  const fetchDelegations = useCallback(async () => {
    try {
      setIsLoading(true);
      const { data, response } = await api.GET('/api/v1/delegations', {
        // The generated OpenAPI schema for this endpoint doesn't document
        // per_page (a spec gap — see the migration note above), but the
        // controller runs through the same PaginationParams helper as the
        // other list endpoints and honors it identically.
        params: { query: { per_page: 100, ...(showAll ? { includeRevoked: true } : {}) } as never },
      });

      if (response.status === 403) {
        // The acting user lacks delegation:manage — show an access-denied state
        // rather than an error toast loop.
        setIsForbidden(true);
        setDelegations([]);
        return;
      }
      setIsForbidden(false);

      if (data === undefined) {
        throw new Error(t('delegations.error.load', 'Failed to fetch delegations'));
      }

      setDelegations(data.data);
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('delegations.error.load', 'Failed to fetch delegations');
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  }, [addToast, t, showAll]);

  const fetchAway = useCallback(async () => {
    const response = await apiClient('/api/v1/away-periods');
    const body = (await response.json().catch(() => null)) as { data?: AwayPeriod[] } | null;
    setAwayPeriods(response.ok ? (body?.data ?? []) : []);
  }, [apiClient]);

  useEffect(() => {
    void (async () => {
      await fetchDelegations();
      await fetchAway();
    })();
  }, [fetchDelegations, fetchAway]);

  useEffect(() => {
    void (async () => {
      const result = await fetchAllPagesTyped<UserOption>((query) => api.GET('/api/v1/users', { params: { query } }));
      setPeople(result.items.filter((u) => typeof u.name === 'string' && u.name !== '').map((u) => ({ id: u.id, name: u.name })));
    })();
  }, []);

  const rows: DelegationRow[] = useMemo(
    () =>
      delegations.map((d): DelegationRow => ({
        id: d.id,
        permission: d.permission,
        grantor: d.grantorName ?? t('delegations.grantee.user', 'A person'),
        grantee:
          d.granteeName ??
          (d.granteeType === 'role'
            ? t('delegations.grantee.role', 'A role')
            : t('delegations.grantee.user', 'A person')),
        granteeIsRole: d.granteeType === 'role',
        scope:
          d.ouId !== null
            ? (d.ouName ?? t('delegations.scope.ou', 'One unit'))
            : t('delegations.scope.tenantWide', 'Whole workspace'),
        grantedAt: d.grantedAt,
        until:
          d.expiresAt !== null && d.expiresAt !== undefined
            ? (dates.date(d.expiresAt) ?? '')
            : t('delegations.until.none', 'No end date'),
        status:
          d.status === 'revoked'
            ? t('delegations.status.revoked', 'Revoked')
            : d.status === 'expired'
              ? t('delegations.status.ended', 'Ended')
              : t('delegations.status.active', 'Active'),
        source: d,
      })).concat(
        awayPeriods
          .filter((a) => showAll || a.status !== 'ended')
          .map((a): DelegationRow => ({
            id: -a.id,
            permission: a.covers
              .map((cover) =>
                cover === 'flows'
                  ? t('away.cover.flows', 'Approvals in flows')
                  : cover === 'proposals'
                    ? t('away.cover.proposals', "Your agents' proposals")
                    : t('away.cover.documents', 'Documents to sign'),
              )
              .join(', '),
            grantor: a.profile.name ?? t('delegations.grantee.user', 'A person'),
            grantee: a.delegate.name,
            granteeIsRole: false,
            scope: t('away.row.scope', 'While away'),
            grantedAt: a.createdAt,
            until: `${dates.date(a.startsOn) ?? a.startsOn} – ${dates.date(a.backOn) ?? a.backOn}`,
            status:
              a.status === 'starting'
                ? t('away.status.starting', 'Starting')
                : a.status === 'in_force'
                  ? t('away.status.inForce', 'In force')
                  : t('delegations.status.ended', 'Ended'),
            source: null,
            away: a,
          })),
      ),
    [delegations, awayPeriods, showAll, t, dates]
  );

  const columns: DataTableColumn<DelegationRow>[] = [
    {
      accessorKey: 'grantor',
      header: t('delegations.table.grantor', 'From'),
      enableSorting: true,
      cell: (row) => (
        <div className="flex items-center gap-3">
          <Avatar name={row.grantor} size={28} />
          <span className="font-medium text-foreground">{row.grantor}</span>
        </div>
      ),
    },
    {
      accessorKey: 'grantee',
      header: t('delegations.table.grantee', 'To'),
      enableSorting: true,
      cell: (row) => (
        <div className="flex items-center gap-3">
          {row.granteeIsRole ? (
            <Badge variant="outline">{row.granteeIsRole ? roleLabel(row.grantee) : row.grantee}</Badge>
          ) : (
            <>
              <Avatar name={row.grantee} size={28} />
              <span className="font-medium text-foreground">{row.grantee}</span>
            </>
          )}
        </div>
      ),
    },
    {
      accessorKey: 'permission',
      header: t('delegations.table.permission', 'What'),
      enableSorting: true,
      cell: (row) => (
        row.away ? (
          <p className="text-foreground">{row.permission}</p>
        ) : (
          <div>
            <p className="text-foreground">{permissionInWords(row.permission)}</p>
            <TechnicalDetails>
              <p className="font-mono text-xs text-muted-foreground">{row.permission}</p>
            </TechnicalDetails>
          </div>
        )
      ),
    },
    { accessorKey: 'scope', header: t('delegations.table.scope', 'Where'), enableSorting: true },
    { accessorKey: 'until', header: t('delegations.table.until', 'Until'), enableSorting: true },
    ...dates.dateColumns<DelegationRow>([
      {
        id: 'grantedAt',
        header: t('delegations.table.grantedAt', 'Since'),
        value: (row) => row.grantedAt,
        enableSorting: true,
      },
    ]),
    {
      accessorKey: 'status',
      header: t('delegations.table.status', 'Status'),
      enableSorting: true,
      cell: (row) => (
        <Badge
          variant={
            row.away
              ? row.away.status === 'in_force'
                ? 'success'
                : 'outline'
              : row.source?.status === 'active'
                ? 'success'
                : 'secondary'
          }
        >
          {row.status}
        </Badge>
      ),
    },
  ];

  const handleUndo = async (delegation: Delegation) => {
    const { error, response } = await api.POST('/api/v1/delegations/{id}/restore', {
      params: { path: { id: delegation.id } },
    });
    if (error !== undefined || !response.ok) {
      addToast(
        response.status === 409
          ? t('delegations.undo.tooLate', 'This can no longer be undone. To give the access back, delegate it again.')
          : t('delegations.undo.error', 'The revoke could not be undone.'),
        'error'
      );
      return;
    }
    addToast(t('delegations.undo.success', 'Delegation put back.'), 'success');
    void fetchDelegations();
  };

  // Revoking can be undone for a while, so it happens at once and the toast offers
  // Undo (tier 1).
  const handleRevoke = async (delegation: Delegation) => {
    const { error, response } = await api.DELETE('/api/v1/delegations/{id}', {
      params: { path: { id: delegation.id } },
    });
    if (error !== undefined || !response.ok) {
      addToast(error?.error ?? t('delegations.revoke.error', 'Failed to revoke delegation'), 'error');
      return;
    }
    addToast(t('delegations.revoked', 'Delegation revoked. They lost this access straight away.'), 'success', 10000, {
      label: t('delegations.undo', 'Undo'),
      onClick: () => void handleUndo(delegation),
    });
    void fetchDelegations();
  };

  const rowActions = (row: DelegationRow) => {
    // Ended delegations stay listed, for the record, with nothing left to do.
    const source = row.source;
    if (source === null || source.status !== 'active') return null;
    return (
      <Button variant="outline" size="sm" onClick={() => void handleRevoke(source)}>
        {t('delegations.action.revoke', 'Revoke')}
      </Button>
    );
  };

  const accessDenied = isForbidden ? (
    <ErrorState
      icon={<IconShieldLock />}
      title={t('delegations.forbidden.title', 'Access denied')}
      description={t(
        'delegations.forbidden.description',
        'You need the delegation:manage permission to manage delegations.'
      )}
    />
  ) : undefined;

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('delegations.title', 'Delegations')}
        description={t('delegations.description.names', 'Who decides for whom, for how long.')}
        action={
          isForbidden ? undefined : (
            <Button variant="outline" onClick={() => setIsCreateOpen(true)} className="gap-2">
              <IconPlus size={18} />
              {t('delegations.header.new', 'New delegation')}
            </Button>
          )
        }
      />

      <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,520px)_minmax(0,1fr)]">
      <AwayCard people={people} onChanged={() => void fetchAway()} />
      <div className="min-w-0 space-y-4">
      {!isForbidden && (
        <ToggleGroup
          required
          label={t('delegations.filter.label', 'Show')}
          value={showAll ? 'all' : 'active'}
          options={[
            { value: 'active', label: t('delegations.filter.active', 'Active') },
            { value: 'all', label: t('delegations.filter.all', 'All') },
          ]}
          onChange={(value) => setShowAll(value === 'all')}
        />
      )}

      <DataTable
        columns={columns}
        data={rows}
        getRowId={(row) => String(row.id)}
        rowActions={rowActions}
        isLoading={isLoading}
        overrideContent={accessDenied}
        enableGlobalFilter
        globalFilterPlaceholder={t('delegations.searchPlaceholder', 'Search delegations…')}
        pagination={{ pageSize: 10 }}
        emptyState={{
          icon: <IconShare size={32} className="text-muted-foreground" />,
          title: t('delegations.empty.title', 'No delegations yet'),
          description: t(
            'delegations.empty.description',
            'Delegate a subset of your permissions to a role or a user.'
          ),
          action: (
            <Button
              onClick={() => setIsCreateOpen(true)}
              variant="outline"
              className="gap-2"
            >
              <IconPlus size={18} />
              {t('delegations.empty.action', 'Create the first delegation')}
            </Button>
          ),
        }}
      />
      </div>
      </div>

      <CreateDelegationModal
        // Remount on each open so the form resets to its defaults without a
        // synchronous setState in an effect (disallowed by this React version).
        key={isCreateOpen ? 'create-open' : 'create-closed'}
        isOpen={isCreateOpen}
        onOpenChange={setIsCreateOpen}
        onSuccess={() => {
          setIsCreateOpen(false);
          void fetchDelegations();
        }}
      />
    </div>
  );
}
