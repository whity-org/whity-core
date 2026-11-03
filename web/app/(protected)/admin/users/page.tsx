'use client';

import { useCallback, useState } from 'react';
import { useRouter } from 'next/navigation';
import { apiClient } from '@/lib/api-client';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { USERS_WRITE, USERS_DELETE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import {
  DataTable,
  dataTableQueryString,
  useDataTableQuery,
  type DataTableColumn,
} from '@/components/ui/data-table';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { Avatar } from '@whity/ui/avatar';
import { roleLabel } from '@whity/features/roles';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@whity/ui/tabs';
import { AreaError } from '@whity/ui/page-states';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { CreateUserModal } from './create-modal';
import { DeleteUserModal } from './delete-modal';
import { InvitationsPanel } from './invitations-panel';
import { MembershipsModal } from './memberships-modal';

/**
 * The user row shape, derived from the OpenAPI schema (WC-168) so it tracks
 * the published `GET /api/users` contract instead of hand-mirroring it.
 */
export type User = components['schemas']['User'];

/** `GET /api/v1/users` — the envelope every core list endpoint answers with. */
interface UsersResponse {
  data: User[];
  pagination?: { total: number; totalPages: number };
}

export default function UsersPage() {
  const { addToast } = useToast();
  const router = useRouter();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const canCreate = hasPermission(USERS_WRITE);
  const canEdit = hasPermission(USERS_WRITE);
  const canDelete = hasPermission(USERS_DELETE);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [isMembershipsModalOpen, setIsMembershipsModalOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);
  const [tab, setTab] = useState('people');
  const [isInviteOpen, setIsInviteOpen] = useState(false);

  /**
   * Page, sort and search all belong to the SERVER now (#1102).
   *
   * Until this, the screen fetched `per_page=100` and sorted, filtered and
   * paginated that slice in the browser — so on a tenant with 150 people, 50 of
   * them were simply not on this screen and nothing said so. The table could
   * not have told anybody either: everything it knew was the hundred rows it
   * had been handed.
   *
   * `sortKeys` maps THIS SCREEN's column ids onto the keys
   * `UsersApiHandler::listSpec()` declares — `name`, `email`, `role`, `status`,
   * `created`, plus `tenant` for the system tenant. A column whose id already
   * is the key needs no entry. Only those columns are marked `enableSorting`
   * below: an unknown key is not an error server-side, it silently falls back
   * to the endpoint's default order, so a sortable-looking header for a column
   * the endpoint does not know would click, animate and reorder nothing.
   */
  const query = useDataTableQuery({
    sortKeys: { accountStatus: 'status', createdAt: 'created' },
  });
  const queryString = dataTableQueryString(query.request);

  // `queryString` is the whole dependency, which is also what makes `fetchUsers`
  // safe to call after a create/edit/delete: `refetch` re-runs THIS closure, so
  // the refetch carries the page, sort and term the operator was looking at
  // rather than dropping them back to an unfiltered page 1.
  const { data, loading, error, refetch: fetchUsers } = useFetch(async () => {
    const response = await apiClient(`/api/v1/users?${queryString}`);
    if (!response.ok) {
      throw new Error(t('users.error.load', 'Failed to fetch users'));
    }
    const body: UsersResponse = await response.json();
    const rows = body.data ?? [];
    return {
      rows,
      // An endpoint that answered without an envelope would otherwise report
      // zero rows across one page while rendering rows — read what is there,
      // fall back to what the page itself proves.
      total: body.pagination?.total ?? rows.length,
      totalPages: body.pagination?.totalPages ?? 1,
    };
  }, [queryString]);

  const users = data?.rows ?? [];

  /**
   * The skeleton is for the FIRST load only.
   *
   * DataTable's loading branch replaces the whole table — search box included —
   * so showing it on every request would unmount the search input mid-word:
   * each debounced keystroke would blow away the draft and the caret. `data`
   * stays null until the first response lands and is non-null forever after,
   * which is exactly "have we ever had something to show". Later requests leave
   * the previous page on screen until the new one arrives.
   */
  const isLoading = loading && data === null;

  /** #882: open the user's RECORD PAGE. */
  const openRecord = useCallback(
    (user: User) => {
      router.push(`/admin/users/${user.id}`);
    },
    [router]
  );

  const handleDeleteClick = (user: User) => {
    setSelectedUser(user);
    setIsDeleteModalOpen(true);
  };

  const handleMembershipsClick = (user: User) => {
    setSelectedUser(user);
    setIsMembershipsModalOpen(true);
  };

  // Deactivate/reactivate (WC-user-status) is gated on the SAME users:write
  // capability as Edit. Deactivating is undoable, so it happens at once and the
  // toast offers Undo (tier 1); the Undo is the opposite change.
  const handleToggleAccountStatus = async (user: User, offerUndo = true) => {
    const nextStatus = user.accountStatus === 'inactive' ? 'active' : 'inactive';
    try {
      const { error, response } = await api.PATCH('/api/v1/users/{id}', {
        params: { path: { id: user.id } },
        body: { accountStatus: nextStatus },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(
          error?.error ?? t('users.status.error', 'Failed to update user status')
        );
      }

      addToast(
        nextStatus === 'inactive'
          ? t('users.status.deactivated', '{name} was deactivated', { name: user.name })
          : t('users.status.reactivated', '{name} was reactivated', { name: user.name }),
        'success',
        10000,
        offerUndo
          ? {
              label: t('users.status.undo', 'Undo'),
              onClick: () => void handleToggleAccountStatus({ ...user, accountStatus: nextStatus }, false),
            }
          : undefined
      );
      fetchUsers();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('users.status.error', 'Failed to update user status');
      addToast(message, 'error');
    }
  };

  // The per-column filter boxes are gone from `name` and `email`, and their
  // absence is the same fix as the rest of this change. A column filter is
  // applied by the table to the rows it holds, which is now ONE page: typing
  // "sara" into it would hide four of the twenty-five on screen and leave the
  // Sara on page 3 exactly where she was. The search box above the table is the
  // replacement, and it asks the server — which matches on email and role name
  // (`searchable` in the same ListSpec).
  const columns: DataTableColumn<User>[] = [
    {
      // Sorted by the email on the server, which is what a person is listed by.
      id: 'email',
      accessorKey: 'name',
      header: t('users.table.person', 'Person'),
      enableSorting: true,
      // A person is a name with an avatar and their email, never an id.
      cell: (user) => (
        <div className="flex items-center gap-3">
          <Avatar name={user.name || user.email} size={32} />
          <div className="min-w-0">
            <p className="truncate font-medium text-foreground">{user.name || user.email}</p>
            <p className="truncate text-sm text-muted-foreground">{user.email}</p>
          </div>
        </div>
      ),
    },
    {
      accessorKey: 'role',
      header: t('users.table.role', 'Role'),
      enableSorting: true,
      cell: (user) => roleLabel(user.role),
    },
    {
      accessorKey: 'accountStatus',
      header: t('users.table.status', 'Status'),
      enableSorting: true,
      cell: (user) => (
        <Badge variant={user.accountStatus === 'inactive' ? 'secondary' : 'success'}>
          {user.accountStatus === 'inactive'
            ? t('users.status.inactive', 'Deactivated')
            : t('users.status.active', 'Active')}
        </Badge>
      ),
    },
    // Gated on the workspace's date preference (#1068).
    ...dates.dateColumns<User>([
      {
        id: 'createdAt',
        header: t('users.table.createdAt', 'Added'),
        value: (user) => user.createdAt,
        enableSorting: true,
      },
    ]),
  ];

  // The dropdown is always rendered: "which tenants is this person in?" is a
  // READ, available to anyone who can reach this page, and it is the only place
  // that question is answered at all (#797 §2). The grant/revoke controls inside
  // the modal are gated on users:write separately.
  const rowActions = (user: User) => {
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={t('users.rowActions.label', 'Row actions')}
          >
            <IconMenu2 />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onClick={() => openRecord(user)}>
            {t('users.rowActions.open', 'Open')}
          </DropdownMenuItem>
          <DropdownMenuItem onClick={() => handleMembershipsClick(user)}>
            {t('users.rowActions.memberships', 'Workspaces and roles')}
          </DropdownMenuItem>
          {canEdit && (
            <DropdownMenuItem onClick={() => handleToggleAccountStatus(user)}>
              {user.accountStatus === 'inactive'
                ? t('users.rowActions.reactivate', 'Reactivate')
                : t('users.rowActions.deactivate', 'Deactivate')}
            </DropdownMenuItem>
          )}
          {canDelete && (
            <DropdownMenuItem
              variant="destructive"
              onClick={() => handleDeleteClick(user)}
            >
              {t('users.rowActions.delete', 'Delete')}
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  const firstLoadFailed = error !== null && data === null;

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('users.title', 'Users')}
        description={t('users.description', 'The people in your workspace, and what each of them may do.')}
        action={
          canCreate ? (
            <div className="flex items-center gap-2">
              <Button
                onClick={() => {
                  setTab('invitations');
                  setIsInviteOpen(true);
                }}
                className="gap-2"
              >
                <IconPlus />
                {t('users.invitePeople', 'Invite people')}
              </Button>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="outline"
                    size="icon"
                    aria-label={t('users.moreActions', 'More actions')}
                  >
                    <IconMenu2 />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onClick={() => setIsCreateModalOpen(true)}>
                    {t('users.addWithoutInviting', 'Add without inviting')}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          ) : undefined
        }
      />

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList variant="line">
          <TabsTrigger value="people">{t('users.tab.people', 'People')}</TabsTrigger>
          <TabsTrigger value="invitations">{t('users.tab.invitations', 'Invitations')}</TabsTrigger>
        </TabsList>

        <TabsContent value="people" className="mt-6">
          {firstLoadFailed ? (
            <AreaError
              message={error}
              retryLabel={t('users.tryAgain', 'Try again')}
              onRetry={() => fetchUsers()}
            />
          ) : (
            <DataTable
              ariaLabel={t('users.table.label', 'Users')}
              columns={columns}
              data={users}
              getRowId={(user) => String(user.id)}
              onRowClick={openRecord}
              rowActions={rowActions}
              isLoading={isLoading}
              emptyState={
                query.search.value !== ''
                  ? {
                      title: t('users.empty.filtered', 'No one matches “{term}”', {
                        term: query.search.value,
                      }),
                    }
                  : {
                      title: t('users.empty.title', 'No one here yet'),
                      description: t(
                        'users.empty.description',
                        'Invite people by email, and they appear here once they join.'
                      ),
                    }
              }
              globalFilterPlaceholder={t('users.searchPlaceholder', 'Search by email or role')}
              sorting={query.sorting}
              search={query.search}
              pagination={query.pagination({
                total: data?.total ?? 0,
                totalPages: data?.totalPages ?? 1,
              })}
            />
          )}
        </TabsContent>

        <TabsContent value="invitations" className="mt-6">
          <InvitationsPanel inviteOpen={isInviteOpen} onInviteOpenChange={setIsInviteOpen} />
        </TabsContent>
      </Tabs>

      <CreateUserModal
        isOpen={isCreateModalOpen}
        onOpenChange={setIsCreateModalOpen}
        onSuccess={() => {
          setIsCreateModalOpen(false);
          fetchUsers();
        }}
      />

      {selectedUser && (
        <>
          <DeleteUserModal
            isOpen={isDeleteModalOpen}
            onOpenChange={setIsDeleteModalOpen}
            user={selectedUser}
            onSuccess={() => {
              setIsDeleteModalOpen(false);
              setSelectedUser(null);
              fetchUsers();
            }}
          />

          {/*
            Closing re-fetches the list: a granted role can change the PRIMARY
            row the list shows, and a stale table would contradict the modal
            the operator just used.
          */}
          <MembershipsModal
            isOpen={isMembershipsModalOpen}
            onOpenChange={(open) => {
              setIsMembershipsModalOpen(open);
              if (!open) {
                fetchUsers();
              }
            }}
            user={selectedUser}
            canManage={canEdit}
          />
        </>
      )}
    </div>
  );
}
