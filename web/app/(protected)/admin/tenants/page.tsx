'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TENANTS_WRITE, TENANTS_DELETE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@whity/ui/button';
import { SearchField } from '@whity/ui/search-field';
import { AreaError, EmptyFiltered, EmptyFirstTime } from '@whity/ui/page-states';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { CreateTenantModal } from './create-modal';
import { DeleteTenantModal } from './delete-modal';

/**
 * The tenant row shape.
 *
 * Re-exported from the record screen, which derives it from the OpenAPI schema
 * (WC-168), rather than hand-mirrored here: this page used to declare `slug` and
 * `createdAt` as non-nullable while the published contract says both may be
 * null, and the delete dialog read the first of those straight into a sentence.
 */
import type { Tenant } from './record-screen';

export type { Tenant };

export default function TenantsPage() {
  const { apiClient } = useAuth();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const canCreate = hasPermission(TENANTS_WRITE);
  const canEdit = hasPermission(TENANTS_WRITE);
  const canDelete = hasPermission(TENANTS_DELETE);

  const router = useRouter();

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [selectedTenant, setSelectedTenant] = useState<Tenant | null>(null);

  // Search runs on the server (`q` matches name and slug, and the total it
  // reports is the filtered one), so the hundredth workspace is reachable by
  // typing its name. Sorting and paging of what comes back stay in the table.
  const [query, setQuery] = useState('');
  const [searched, setSearched] = useState('');
  useEffect(() => {
    const timer = setTimeout(() => setSearched(query.trim()), 300);
    return () => clearTimeout(timer);
  }, [query]);

  const { data, loading: isLoading, error, refetch: fetchTenants } = useFetch(async () => {
    const params = new URLSearchParams({ per_page: '100' });
    if (searched !== '') params.set('q', searched);
    const response = await apiClient(`/api/v1/tenants?${params.toString()}`);
    if (!response.ok) {
      throw new Error(t('tenants.error.load', 'Failed to fetch tenants'));
    }
    const data = await response.json();
    return (data.data ?? []) as Tenant[];
  }, [apiClient, searched]);

  const tenants = data ?? [];

  /**
   * #882: open the workspace's RECORD PAGE.
   *
   * This is the whole of what "Edit" means on this screen now — there is no
   * edit dialog behind a flag to fall back to. #884 asked for a decision per
   * screen, and for tenants the decision is that the page SUPERSEDES the modal:
   * the dialog edited two fields and could show nothing else, while the record
   * page carries the workspace's plan and entitlement overrides beside them and
   * states the cross-tenant write rule instead of letting Save 403.
   */
  const openRecord = useCallback(
    (tenant: Tenant) => {
      router.push(`/admin/tenants/${tenant.id}`);
    },
    [router]
  );

  const handleDeleteClick = (tenant: Tenant) => {
    setSelectedTenant(tenant);
    setIsDeleteModalOpen(true);
  };

  const columns: DataTableColumn<Tenant>[] = [
    {
      accessorKey: 'name',
      header: t('tenants.table.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
      // #882: the row's own name opens the record — the affordance a list gets
      // once its records have addresses. Same treatment the users and roles
      // lists gained. Offered to every reader, not only writers: the record page
      // is READABLE without tenants:write, and it is the only place a
      // workspace's plan and entitlements are shown at all.
      cell: (tenant) => (
        <button
          type="button"
          onClick={() => openRecord(tenant)}
          className="text-start font-medium text-primary underline-offset-4 hover:underline"
        >
          {tenant.name}
        </button>
      ),
    },
    {
      accessorKey: 'slug',
      header: t('tenants.table.slug', 'Slug'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    { accessorKey: 'userCount', header: t('tenants.table.userCount', 'Users'), enableSorting: true },
    // #1068, and the same two changes the users list needed: gated on the
    // tenant preference, and formatted at all rather than rendered as the raw
    // wire string an `accessorKey` with no `cell` produces.
    ...dates.dateColumns<Tenant>([
      {
        id: 'createdAt',
        header: t('tenants.table.createdAt', 'Created'),
        value: (tenant) => tenant.createdAt,
        enableSorting: true,
      },
    ]),
  ];

  // Always rendered now: "open this workspace" is a READ, available to anyone
  // who can reach this page, and it is the only route to the plan and
  // entitlement panels the record page carries. The write controls inside it
  // stay gated on tenants:write separately — same reasoning as the users list's
  // always-present row menu (#797 §2).
  const rowActions = (tenant: Tenant) => {
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={t('tenants.rowActions.label', 'Row actions')}
          >
            <IconMenu2 size={16} />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onClick={() => openRecord(tenant)}>
            {canEdit
              ? t('tenants.actions.edit', 'Edit')
              : t('tenants.actions.view', 'Open workspace')}
          </DropdownMenuItem>
          {canDelete && (
            <DropdownMenuItem
              onClick={() => handleDeleteClick(tenant)}
              className="text-destructive focus:text-destructive"
            >
              {t('tenants.actions.delete', 'Delete')}
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('tenants.title', 'Tenants')}
        description={t('tenants.description', 'The workspaces on this installation.')}
        action={
          canCreate ? (
            <Button
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2"
            >
              <IconPlus size={18} />
              {t('tenants.createButton', 'Create tenant')}
            </Button>
          ) : undefined
        }
      />

      <SearchField
        value={query}
        onValueChange={setQuery}
        label={t('tenants.searchLabel', 'Search tenants')}
        clearLabel={t('tenants.searchClear', 'Clear search')}
        placeholder={t('tenants.searchPlaceholder', 'Search tenants…')}
        className="max-w-sm"
      />

      {error ? (
        <AreaError
          message={t('tenants.error.load', 'Failed to fetch tenants')}
          retryLabel={t('tenants.error.retry', 'Try again')}
          onRetry={fetchTenants}
        />
      ) : !isLoading && tenants.length === 0 ? (
        searched !== '' ? (
          <EmptyFiltered
            title={t('tenants.empty.filtered', 'No workspaces match “{query}”', { query: searched })}
            clearLabel={t('tenants.empty.clear', 'Clear search')}
            onClear={() => setQuery('')}
          />
        ) : (
          <EmptyFirstTime
            title={t('tenants.empty.title', 'No workspaces yet')}
            description={t('tenants.empty.description', 'Create the first workspace to get started.')}
            action={
              canCreate ? (
                <Button onClick={() => setIsCreateModalOpen(true)}>{t('tenants.createButton', 'Create tenant')}</Button>
              ) : undefined
            }
          />
        )
      ) : (
        <DataTable
          columns={columns}
          data={tenants}
          getRowId={(tenant) => String(tenant.id)}
          rowActions={rowActions}
          isLoading={isLoading}
          onRowClick={openRecord}
          pagination={{ pageSize: 10 }}
        />
      )}

      <CreateTenantModal
        isOpen={isCreateModalOpen}
        onOpenChange={setIsCreateModalOpen}
        onSuccess={() => {
          setIsCreateModalOpen(false);
          fetchTenants();
        }}
      />

      {/* Delete stays a dialog on the LIST. A confirmation is not a record
          surface — it has no fields, nothing to link to and nothing to come back
          to afterwards, because the record it names is about to stop existing. */}
      {selectedTenant && (
        <DeleteTenantModal
          isOpen={isDeleteModalOpen}
          onOpenChange={setIsDeleteModalOpen}
          tenant={selectedTenant}
          onSuccess={() => {
            setIsDeleteModalOpen(false);
            setSelectedTenant(null);
            fetchTenants();
          }}
        />
      )}
    </div>
  );
}
