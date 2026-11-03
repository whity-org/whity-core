'use client';

/**
 * Route templates — `/admin/document-route-templates` (#1027).
 *
 * The list, and the door to the node-based editor. Bespoke rather than the
 * schema-driven CrudScreen for the reason every core admin page states: that
 * screen renders from the DYNAMIC `/api/openapi.json`, which describes only
 * plugin routes, so it cannot derive fields for a core resource.
 *
 * NO PEOPLE COUNT ON A ROW, DELIBERATELY
 * --------------------------------------
 * A row shows how many STAGES a design has and never how many people it reaches.
 * The second would mean resolving every rule of every step on every render — a
 * page of forty templates commissioning hundreds of fan-out queries to decorate
 * a screen nobody asked a membership question on — and the number would be stale
 * by the time it was drawn. That question is answered per node, inside the
 * editor, where somebody has asked it.
 */

import { useCallback, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { ROUTE_TEMPLATES_WRITE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { IconMenu2, IconPlus } from '@tabler/icons-react';
import { ConfirmAction } from '@whity/ui/confirm-action';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { routeSentence } from '@/lib/documents/route-sentence';
import { useTranslation } from '@whity/features/i18n';
import type { RouteTemplateGraphWire, RouteTemplateSummary } from './types';

export default function RouteTemplatesPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const router = useRouter();
  const t = useTranslation('admin');

  const canWrite = hasPermission(ROUTE_TEMPLATES_WRITE);

  const { data, loading, error, refetch } = useFetch(async () => {
    const response = await apiClient('/api/v1/document-route-templates');
    if (!response.ok) {
      throw new Error(t('routeTemplates.error.load', 'Failed to load route templates'));
    }
    const body = await response.json();
    return (body.data ?? []) as RouteTemplateSummary[];
  }, [apiClient]);

  const [creating, setCreating] = useState(false);
  const [name, setName] = useState('');
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState<RouteTemplateSummary | null>(null);

  // Each route is described in a sentence, which needs its stages: read them per
  // route (the list carries only a count). Role and group names are looked up
  // once; a viewer who cannot read them sees the stage's own label instead.
  const sentences = useFetch(async () => {
    const rows = data ?? [];
    const [rolesRes, groupsRes] = await Promise.all([
      apiClient('/api/v1/roles?per_page=100').catch(() => null),
      apiClient('/api/v1/user-groups?per_page=100').catch(() => null),
    ]);
    const nameMap = async (res: Response | null) => {
      const map = new Map<number, string>();
      if (res?.ok) {
        const body = (await res.json().catch(() => null)) as { data?: { id: number; name: string }[] } | null;
        for (const item of body?.data ?? []) map.set(item.id, item.name);
      }
      return map;
    };
    const roles = await nameMap(rolesRes);
    const groups = await nameMap(groupsRes);
    const out: Record<number, string> = {};
    await Promise.all(
      rows.map(async (row) => {
        const res = await apiClient(`/api/v1/document-route-templates/${row.id}`).catch(() => null);
        if (!res?.ok) return;
        const body = (await res.json().catch(() => null)) as { data?: RouteTemplateGraphWire } | null;
        if (body?.data === undefined) return;
        out[row.id] = routeSentence(
          body.data.steps.map((st) => ({
            position: st.position,
            ruleKind: st.rule_kind,
            ruleConfig: st.rule_config,
            label: st.label,
          })),
          (st) =>
            st.ruleKind === 'group'
              ? (groups.get(st.ruleConfig.group_id as number) ?? null)
              : (roles.get(st.ruleConfig.role_id as number) ?? null),
          {
            unnamed: t('routeTemplates.sentence.unnamed', 'A stage'),
            empty: t('routeTemplates.sentence.empty', 'No stages drawn yet'),
            join: (before, next) => t('routeTemplates.sentence.then', '{before}, then {next}', { before, next }),
          }
        );
      })
    );
    return out;
  }, [apiClient, data]);

  const duplicate = useCallback(
    async (row: RouteTemplateSummary) => {
      const failed = t('routeTemplates.error.duplicate', 'The route could not be duplicated');
      const source = await apiClient(`/api/v1/document-route-templates/${row.id}`);
      if (!source.ok) return addToast(failed, 'error');
      const graph = ((await source.json()) as { data: RouteTemplateGraphWire }).data;
      const made = await apiClient('/api/v1/document-route-templates', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: t('routeTemplates.duplicate.name', '{name} (copy)', { name: row.name }),
          description: row.description,
        }),
      });
      if (!made.ok) return addToast(failed, 'error');
      const id = ((await made.json()) as { data: { id: number } }).data.id;
      const put = await apiClient(`/api/v1/document-route-templates/${id}/graph`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ steps: graph.steps, edges: graph.edges }),
      });
      if (!put.ok) return addToast(failed, 'error');
      router.push(`/admin/document-route-templates/${id}`);
    },
    [addToast, apiClient, router, t]
  );

  const remove = useCallback(async () => {
    if (deleting === null) return;
    const response = await apiClient(`/api/v1/document-route-templates/${deleting.id}`, { method: 'DELETE' });
    if (!response.ok) {
      addToast(t('routeTemplates.error.delete', 'The route could not be deleted'), 'error');
      return;
    }
    setDeleting(null);
    refetch();
  }, [addToast, apiClient, deleting, refetch, t]);

  const create = useCallback(async () => {
    setSaving(true);
    try {
      const response = await apiClient('/api/v1/document-route-templates', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
      });

      if (!response.ok) {
        // The server's message names the conflict or the field, so it is shown
        // verbatim rather than replaced with a generic failure.
        const body = await response.json().catch(() => null);
        const message =
          typeof body?.error === 'string'
            ? body.error
            : t('routeTemplates.error.create', 'Failed to create the route template');
        addToast(message, 'error');
        return;
      }

      const body = await response.json();
      setCreating(false);
      setName('');
      // Straight into the editor: a template is created EMPTY and the only
      // useful next act is to draw it.
      router.push(`/admin/document-route-templates/${body.data.id}`);
    } finally {
      setSaving(false);
    }
  }, [addToast, apiClient, name, router, t]);

  const columns: DataTableColumn<RouteTemplateSummary>[] = [
    {
      accessorKey: 'name',
      header: t('routeTemplates.column.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
      cell: (row) => (
        <button
          type="button"
          onClick={() => router.push(`/admin/document-route-templates/${row.id}`)}
          className="text-start font-medium text-foreground hover:underline"
          dir="auto"
        >
          {row.name}
        </button>
      ),
    },
    {
      id: 'sentence',
      header: t('routeTemplates.column.route', 'The route'),
      cell: (row) => (
        <span className="text-muted-foreground" dir="auto">
          {sentences.data?.[row.id] ?? row.description ?? '—'}
        </span>
      ),
    },
    {
      accessorKey: 'step_count',
      header: t('routeTemplates.column.stages', 'Stages'),
      enableSorting: true,
      cell: (row) => row.step_count,
    },
    {
      accessorKey: 'usage_count',
      header: t('routeTemplates.column.used', 'Used'),
      enableSorting: true,
      cell: (row) =>
        row.usage_count === 1
          ? t('routeTemplates.used.once', 'Once')
          : t('routeTemplates.used.times', '{count} times', { count: row.usage_count }),
    },
  ];

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('routeTemplates.title', 'Routes')}
        description={t(
          'routeTemplates.description',
          'The routes a document can travel. A stage names a role, a group or a unit rather than a list of people, so a route reaches whoever holds the role when it is used.'
        )}
        action={
          canWrite ? (
            <Button onClick={() => setCreating(true)}>
              <IconPlus className="me-1 size-4" />
              {t('routeTemplates.action.new', 'New route')}
            </Button>
          ) : undefined
        }
      />

      {error !== null ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : (
        <DataTable
          columns={columns}
          data={data ?? []}
          getRowId={(row) => String(row.id)}
          isLoading={loading}
          onRowClick={(row) => router.push(`/admin/document-route-templates/${row.id}`)}
          rowActions={(row) => (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  aria-label={t('routeTemplates.rowActions.label', 'Actions for {name}', { name: row.name })}
                >
                  <IconMenu2 size={16} />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-auto min-w-44">
                <DropdownMenuItem onClick={() => router.push(`/admin/document-route-templates/${row.id}`)}>
                  {t('routeTemplates.rowActions.open', 'Open')}
                </DropdownMenuItem>
                <DropdownMenuItem disabled={!canWrite} onClick={() => void duplicate(row)}>
                  {t('routeTemplates.rowActions.duplicate', 'Duplicate')}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem disabled={!canWrite} variant="destructive" onClick={() => setDeleting(row)}>
                  {t('routeTemplates.rowActions.delete', 'Delete…')}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          )}
          emptyState={{
            title: t('routeTemplates.empty.title', 'No routes yet'),
            description: t('routeTemplates.empty.description', 'Make one so documents go to the right people.'),
          }}
          enableGlobalFilter
          globalFilterPlaceholder={t('routeTemplates.searchPlaceholder', 'Search route templates…')}
          pagination={{ pageSize: 10 }}
        />
      )}

      <Dialog open={creating} onOpenChange={setCreating}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('routeTemplates.create.title', 'New route')}</DialogTitle>
            <DialogDescription>
              {t(
                'routeTemplates.create.description',
                'Give the flow a name. You will draw its stages next.'
              )}
            </DialogDescription>
          </DialogHeader>
          <Input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t('routeTemplates.create.placeholder', 'Expense approval')}
            maxLength={160}
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setCreating(false)}>
              {t('routeTemplates.action.cancel', 'Cancel')}
            </Button>
            <Button onClick={create} disabled={saving || name.trim() === ''}>
              {t('routeTemplates.action.create', 'Create')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmAction
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={t('routeTemplates.delete.title', 'Delete the route “{name}”?', { name: deleting?.name ?? '' })}
        description={t(
          'routeTemplates.delete.description',
          'Documents already on this route finish it. New documents can no longer be sent on it.'
        )}
        confirmLabel={t('routeTemplates.delete.confirm', 'Delete route')}
        cancelLabel={t('routeTemplates.action.cancel', 'Cancel')}
        variant="destructive"
        onConfirm={remove}
      />

      {/* Refetching is what the list does after a delete elsewhere returns here;
          the button exists so a stale tab has a way back without a reload. */}
      <button type="button" onClick={refetch} className="sr-only">
        {t('routeTemplates.action.refresh', 'Refresh')}
      </button>
    </div>
  );
}
