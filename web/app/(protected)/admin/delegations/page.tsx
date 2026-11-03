'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { fetchAllPagesTyped } from '@/lib/api/fetch-all-pages';
import { Avatar } from '@whity/ui/avatar';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { useDateDisplay } from '@whity/features/datetime';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import { ErrorState } from '@whity/ui/empty-state';
import { IconPlus, IconShare, IconShieldLock } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';
import { CreateDelegationModal } from './create-modal';
import { permissionInWords } from './permission-words';
import { RevokeDelegationModal } from './revoke-modal';
import type { Delegation } from './types';

/**
 * Row view-model for the delegations table. Display strings are precomputed so
 * the generic DataTable (which renders raw cell values) shows readable labels
 * for the polymorphic grantee, the OU scope and the live/revoked status.
 */
interface DelegationRow {
  id: number;
  permission: string;
  /** The grantee's name, or a plain word when the viewer cannot read it. */
  grantee: string;
  granteeIsRole: boolean;
  scope: string;
  grantedAt: string | null;
  status: string;
  source: Delegation;
}

export default function DelegationsPage() {
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [delegations, setDelegations] = useState<Delegation[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isForbidden, setIsForbidden] = useState(false);

  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isRevokeOpen, setIsRevokeOpen] = useState(false);
  const [selected, setSelected] = useState<Delegation | null>(null);
  const dates = useDateDisplay();

  // Names for who and where, never ids. Each list is read once and dropped when
  // it could not be read in full, so a row falls back to a plain word rather
  // than to a number.
  const [roleNames, setRoleNames] = useState<Map<number, string>>(new Map());
  const [userNames, setUserNames] = useState<Map<number, string>>(new Map());
  const [ouNames, setOuNames] = useState<Map<number, string>>(new Map());
  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const [roles, users, ous] = await Promise.all([
        fetchAllPagesTyped<{ id: number; name: string }>((query) =>
          api.GET('/api/v1/roles', { params: { query } })
        ),
        fetchAllPagesTyped<{ id: number; name: string; email: string }>((query) =>
          api.GET('/api/v1/users', { params: { query } })
        ),
        fetchAllPagesTyped<{ id: number; name: string }>((query) =>
          api.GET('/api/v1/ous', { params: { query } })
        ),
      ]);
      if (cancelled) return;
      if (roles.complete) setRoleNames(new Map(roles.items.map((r) => [r.id, r.name])));
      if (users.complete) setUserNames(new Map(users.items.map((u) => [u.id, u.name || u.email])));
      if (ous.complete) setOuNames(new Map(ous.items.map((o) => [o.id, o.name])));
    })();
    return () => {
      cancelled = true;
    };
  }, []);

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
        params: { query: { per_page: 100 } as never },
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
  }, [addToast, t]);

  useEffect(() => {
    void (async () => {
      await fetchDelegations();
    })();
  }, [fetchDelegations]);

  const rows: DelegationRow[] = useMemo(
    () =>
      delegations.map((d) => ({
        id: d.id,
        permission: d.permission,
        grantee:
          d.granteeType === 'role'
            ? (roleNames.get(d.granteeId) ?? t('delegations.grantee.role', 'A role'))
            : (userNames.get(d.granteeId) ?? t('delegations.grantee.user', 'A person')),
        granteeIsRole: d.granteeType === 'role',
        scope:
          d.ouId !== null
            ? (ouNames.get(d.ouId) ?? t('delegations.scope.ou', 'One unit'))
            : t('delegations.scope.tenantWide', 'Whole workspace'),
        grantedAt: d.grantedAt,
        status:
          d.revokedAt !== null
            ? t('delegations.status.revoked', 'Revoked')
            : t('delegations.status.active', 'Active'),
        source: d,
      })),
    [delegations, t, roleNames, userNames, ouNames]
  );

  const columns: DataTableColumn<DelegationRow>[] = [
    {
      accessorKey: 'grantee',
      header: t('delegations.table.grantee', 'To'),
      enableSorting: true,
      cell: (row) => (
        <div className="flex items-center gap-3">
          {row.granteeIsRole ? (
            <Badge variant="outline">{row.grantee}</Badge>
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
        <div>
          <p className="text-foreground">{permissionInWords(row.permission)}</p>
          <TechnicalDetails>
            <p className="font-mono text-xs text-muted-foreground">{row.permission}</p>
          </TechnicalDetails>
        </div>
      ),
    },
    { accessorKey: 'scope', header: t('delegations.table.scope', 'Where'), enableSorting: true },
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
        <Badge variant={row.source.revokedAt !== null ? 'secondary' : 'success'}>{row.status}</Badge>
      ),
    },
  ];

  const handleRevokeClick = (delegation: Delegation) => {
    setSelected(delegation);
    setIsRevokeOpen(true);
  };

  const rowActions = (row: DelegationRow) => {
    // Ended delegations stay listed, for the record, with nothing left to do.
    if (row.source.revokedAt !== null) return null;
    return (
      <Button variant="outline" size="sm" onClick={() => handleRevokeClick(row.source)}>
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
        description={
          isForbidden
            ? t(
                'delegations.description.short',
                'Delegate a subset of your permissions to roles or users.'
              )
            : t(
                'delegations.description',
                'Delegate a subset of your permissions to roles or users, scoped to a tenant or an organizational unit.'
              )
        }
        action={
          isForbidden ? undefined : (
            <Button onClick={() => setIsCreateOpen(true)} className="gap-2">
              <IconPlus size={18} />
              {t('delegations.header.create', 'Delegate')}
            </Button>
          )
        }
      />

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

      {selected && (
        <RevokeDelegationModal
          isOpen={isRevokeOpen}
          onOpenChange={setIsRevokeOpen}
          delegation={selected}
          granteeName={rows.find((row) => row.id === selected.id)?.grantee ?? ''}
          scopeName={rows.find((row) => row.id === selected.id)?.scope ?? ''}
          onSuccess={() => {
            setIsRevokeOpen(false);
            setSelected(null);
            void fetchDelegations();
          }}
        />
      )}
    </div>
  );
}
