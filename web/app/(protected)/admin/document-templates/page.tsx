'use client';

import { useCallback, useMemo, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { IconDots, IconShieldLock } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { ErrorState } from '@whity/ui/empty-state';
import { ToggleGroup } from '@whity/ui/toggle-group';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { DOCUMENTS_ISSUE, DOCUMENTS_PUBLISH, DOCUMENTS_WRITE } from '@/lib/capabilities';
import { issueHref } from '@/lib/documents/issue';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { createDocumentSource } from '@/lib/documents/sources';
import { NumberingDialog } from './numbering-dialog';
import { PublicNameDialog } from './public-name-dialog';
import { ScopeDialog } from './scope-dialog';
import { DeleteDialog } from './delete-dialog';
import { CopyDialog } from './copy-dialog';
import { describeAudience } from './audience';
import { NewTemplateDialog } from './new-template-dialog';
import { ForbiddenError, editorHref, listTemplates } from './api';
import { useLocalizedUnits } from '@/hooks/useLocalizedUnits';
import type { OuOption, PermissionOption, PermissionSource, TemplateRow } from './types';

/**
 * Document templates (A31) — the editor templates and label sheets documents
 * are issued from, and who can see each one.
 *
 * Since editor M4c every template is an editor document (`document_sources`,
 * kinds `template` and `label`): the old canvas designer's templates were
 * converted, and are marked "Converted" here so their authors can find and
 * check them. Templates are written and renamed in the editor (a title is draft
 * content, E.9); this page lists them, opens them, copies them and governs who
 * can see them.
 *
 * WHO CAN SEE IT is the column worth reading. A dean's secretary and a
 * department head's secretary hold the same permissions and still reach
 * different templates, because of where each template is filed. That fact lives
 * in three columns (`scope`, `owner_ou_id`, `required_permission`), so the
 * visibility badge carries the plain-language sentence from ./audience.tsx as
 * its tooltip, and the scope dialog shows the same sentence live.
 *
 * Saved blocks were part of the old designer and come back in M7.
 */
type ShowFilter = 'all' | 'published' | 'drafts';

export default function DocumentTemplatesPage() {
  const { apiClient, user } = useAuth();
  const { addToast } = useToast();
  const router = useRouter();
  const { hasPermission, permissions } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  const canWrite = hasPermission(DOCUMENTS_WRITE);
  const canPublish = hasPermission(DOCUMENTS_PUBLISH);
  const canIssue = hasPermission(DOCUMENTS_ISSUE);
  const viewerProfileId = user?.id ?? null;

  // ── data ────────────────────────────────────────────────────────────────────

  const templates = useFetch<TemplateRow[]>(
    () => listTemplates(apiClient, t('documentTemplates.error.templates', 'Failed to load templates')),
    [apiClient]
  );

  /**
   * Record type names ("Invoice" rather than `invoice`). Best effort: when the
   * catalogue cannot be read the key is shown as it is.
   */
  const recordTypes = useFetch<Record<string, string>>(async () => {
    const response = await apiClient('/api/v1/document-embeds');
    if (!response.ok) return {};
    const body = (await response.json().catch(() => null)) as {
      data?: { record_types?: { key: string; label: string }[] };
    } | null;
    return Object.fromEntries((body?.data?.record_types ?? []).map((r) => [r.key, r.label]));
  }, [apiClient]);

  /**
   * Unit names, for the audience sentence and the placement picker. Listing
   * units needs `ous:read`, which this page's own gate does not imply, so a 403
   * here is an ordinary state: units show by id and placement is locked.
   */
  const ous = useFetch<OuOption[]>(async () => {
    const result = await fetchAllPages<OuOption>(apiClient, '/api/v1/ous');
    if (!result.complete) {
      throw new Error(t('documentTemplates.error.ous', 'Failed to load the full unit list'));
    }
    return result.items;
  }, [apiClient]);
  const units = useLocalizedUnits(ous.data);

  /**
   * Options for the permission tag: the catalogue when the caller may read it
   * (admin role), otherwise the caller's own permissions. Either way the value
   * written is the slug, never an id.
   */
  const permissionOptions = useFetch<{ source: PermissionSource; names: string[] }>(async () => {
    // A page holds at most 100 and the catalogue is longer, so read every page.
    const catalogue: PermissionOption[] = [];
    for (let page = 1; page <= 20; page += 1) {
      const response = await apiClient(`/api/v1/permissions?per_page=100&page=${page}`);
      if (!response.ok) break;
      const body = (await response.json()) as {
        data?: PermissionOption[];
        pagination?: { totalPages?: number };
      };
      const rows = body.data ?? [];
      catalogue.push(...rows);
      const totalPages = body.pagination?.totalPages;
      if (rows.length === 0 || typeof totalPages !== 'number' || page >= totalPages) break;
    }
    const names = catalogue.map((p) => p.name).filter((n): n is string => typeof n === 'string' && n !== '');
    if (names.length > 0) return { source: 'catalogue' as const, names: names.sort() };
    return { source: 'own' as const, names: [...permissions].sort() };
  }, [apiClient, permissions]);

  // ── dialog state ────────────────────────────────────────────────────────────

  const [copying, setCopying] = useState<TemplateRow | null>(null);
  const [scoping, setScoping] = useState<TemplateRow | null>(null);
  const [numbering, setNumbering] = useState<TemplateRow | null>(null);
  const [naming, setNaming] = useState<TemplateRow | null>(null);
  const [deleting, setDeleting] = useState<TemplateRow | null>(null);
  const [creating, setCreating] = useState(false);
  const [starting, setStarting] = useState(false);
  const [show, setShow] = useState<ShowFilter>('all');

  const nameOf = useCallback(
    (row: TemplateRow) => (row.title.trim() === '' ? t('documentTemplates.untitled', 'Untitled') : row.title),
    [t]
  );

  const ouName = useCallback(
    (ouId: number | null): string | null => {
      if (ouId === null) return null;
      return units.find((o) => o.id === ouId)?.name ?? null;
    },
    [units]
  );

  const createTemplate = async () => {
    setCreating(true);
    try {
      const row = await createDocumentSource('template', '');
      router.push(editorHref(row.id));
    } catch {
      addToast(t('documentTemplates.create.failed', 'The template wasn’t created. Try again.'), 'error');
      setCreating(false);
    }
  };

  // ── table ───────────────────────────────────────────────────────────────────

  /**
   * Whity's starters are offered under New template; listed here too, every
   * workspace's own templates sat under 24 drafts it never touched (12 in each
   * language). A starter joins the list once somebody publishes or edits it.
   */
  const listed = useMemo(() => {
    const rows = (templates.data ?? []).filter(
      (row) => row.starter_key === null || row.published_version !== null || row.draft_version > 1
    );
    if (show === 'published') return rows.filter((row) => row.published_version !== null);
    if (show === 'drafts') return rows.filter((row) => row.published_version === null);
    return rows;
  }, [templates.data, show]);
  const startersAvailable = (templates.data ?? []).some((row) => row.starter_key !== null);

  const recordTypeNames = recordTypes.data;

  const columns = useMemo<DataTableColumn<TemplateRow>[]>(
    () => [
      {
        accessorKey: 'title',
        header: t('documentTemplates.table.title', 'Template'),
        enableSorting: true,
        cell: (row) => (
          <span className="flex flex-wrap items-center gap-2">
            <Link
              href={editorHref(row.id)}
              dir="auto"
              className="font-medium text-foreground underline-offset-4 hover:underline"
            >
              {nameOf(row)}
            </Link>
            {row.starter_key !== null && (
              <Badge variant="outline">{t('documentTemplates.badge.starter', 'Whity starter')}</Badge>
            )}
            {row.legacy_template_id !== null && (
              <Badge variant="outline">{t('documentTemplates.badge.converted', 'Converted')}</Badge>
            )}
            <span className="basis-full text-sm text-muted-foreground">
              {row.kind === 'label'
                ? t('documentTemplates.kind.label', 'Label sheet')
                : t('documentTemplates.kind.template', 'Template')}
              {row.record_type !== null && ` · ${recordTypeNames?.[row.record_type] ?? row.record_type}`}
            </span>
          </span>
        ),
      },
      {
        id: 'status',
        header: t('documentTemplates.table.status', 'Status'),
        cell: (row) =>
          row.published_version === null ? (
            <Badge variant="secondary">{t('documentTemplates.status.draft', 'Draft')}</Badge>
          ) : (
            <Badge variant="success">
              {t('documentTemplates.status.published', 'Published, version {version}', { version: row.published_version })}
            </Badge>
          ),
      },
      {
        id: 'visibility',
        header: t('documentTemplates.table.visibility', 'Who can see it'),
        cell: (row) => (
          <span className="text-sm text-muted-foreground">
            {describeAudience(row, { ouName: ouName(row.owner_ou_id), viewerProfileId }, t)}
          </span>
        ),
      },
      // The "Updated" column goes when this tenant hides dates (#1068).
      ...dates.dateColumns<TemplateRow>([
        {
          id: 'updated_at',
          header: t('documentTemplates.table.updated', 'Changed'),
          value: (row) => row.updated_at,
          enableSorting: true,
          className: 'text-muted-foreground',
        },
      ]),
    ],
    [t, dates, nameOf, ouName, viewerProfileId, recordTypeNames]
  );

  const rowActions = (row: TemplateRow) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={t('documentTemplates.rowActions.label', 'Actions for {name}', { name: nameOf(row) })}
        >
          <IconDots size={16} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-auto min-w-52">
        <DropdownMenuItem onClick={() => router.push(editorHref(row.id))}>
          {t('documentTemplates.rowActions.open', 'Open')}
        </DropdownMenuItem>
        {/* Issuing opens the editor on the published version (M4c); a label
            sheet prints labels instead, so it has no document to issue. */}
        {row.kind === 'template' && row.published_version !== null && canIssue && (
          <DropdownMenuItem onClick={() => router.push(issueHref(row.id))}>
            {t('documentTemplates.rowActions.issue', 'Issue a document')}
          </DropdownMenuItem>
        )}
        {/* Copying needs write, not publish: the copy lands personal, the one
            scope everybody with write already has. */}
        <DropdownMenuItem onClick={() => setCopying(row)} disabled={!canWrite}>
          {t('documentTemplates.rowActions.copy', 'Make a copy…')}
        </DropdownMenuItem>
        {/* Every field in the scope dialog is a publish action server-side,
            so the entry is gated rather than opening a dialog whose save 403s. */}
        <DropdownMenuItem onClick={() => setScoping(row)} disabled={!canPublish}>
          {t('documentTemplates.rowActions.audience', 'Who can see it…')}
        </DropdownMenuItem>
        {row.kind === 'template' && (
          <DropdownMenuItem onClick={() => setNumbering(row)} disabled={!canPublish}>
            {t('documentTemplates.rowActions.numbering', 'Numbering…')}
          </DropdownMenuItem>
        )}
        {row.kind === 'template' && (
          <DropdownMenuItem onClick={() => setNaming(row)} disabled={!canPublish}>
            {t('documentTemplates.rowActions.publicName', 'Name on the public check…')}
          </DropdownMenuItem>
        )}
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={() => setDeleting(row)} disabled={!canWrite} variant="destructive">
          {t('documentTemplates.rowActions.delete', 'Delete…')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  // ── render ──────────────────────────────────────────────────────────────────

  const forbidden = templates.error === new ForbiddenError().message;

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('documentTemplates.title', 'Templates')}
        description={t(
          'documentTemplates.subtitle',
          'The templates and label sheets documents are issued from, and who can see each one. A document is issued from the published version.'
        )}
        action={
          canWrite ? (
            <Button icon="plus" loading={creating} disabled={creating} onClick={() => setStarting(true)}>
              {t('documentTemplates.new', 'New template')}
            </Button>
          ) : undefined
        }
      />

      {ous.error !== null && (
        <p className="text-sm text-muted-foreground">
          {t(
            'documentTemplates.ous.unavailable',
            'Unit names could not be loaded, so units are shown by id and placements cannot be changed. Listing units needs the ous:read permission.'
          )}
        </p>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <ToggleGroup
          label={t('documentTemplates.filter.label', 'Show')}
          value={show}
          required
          onChange={(value) => typeof value === 'string' && setShow(value as ShowFilter)}
          options={[
            { value: 'all', label: t('documentTemplates.filter.all', 'All') },
            { value: 'published', label: t('documentTemplates.filter.published', 'Published') },
            { value: 'drafts', label: t('documentTemplates.filter.drafts', 'Drafts') },
          ]}
        />
        {canWrite && startersAvailable && (
          <p className="text-sm text-muted-foreground">
            {t(
              'documentTemplates.startersHint',
              'Whity’s starters are under New template, and join this list once you publish or change one.'
            )}
          </p>
        )}
      </div>

      <DataTable
        columns={columns}
        data={listed}
        getRowId={(row) => String(row.id)}
        rowActions={rowActions}
        onRowClick={(row) => router.push(editorHref(row.id))}
        isLoading={templates.loading}
        overrideContent={
          forbidden ? (
            <ErrorState
              icon={<IconShieldLock />}
              title={t('documentTemplates.forbidden.title', 'Access denied')}
              description={t(
                'documentTemplates.forbidden.templates',
                'You need the documents:read permission to see document templates.'
              )}
            />
          ) : undefined
        }
        ariaLabel={t('documentTemplates.title', 'Templates')}
        enableGlobalFilter
        globalFilterPlaceholder={t('documentTemplates.searchTemplates', 'Search templates…')}
        emptyState={
          show !== 'all'
            ? {
                title: t('documentTemplates.empty.filtered.title', 'No templates match'),
                description: t('documentTemplates.empty.filtered.description', 'Show all templates to see the rest.'),
                action: (
                  <Button variant="outline" onClick={() => setShow('all')}>
                    {t('documentTemplates.empty.filtered.clear', 'Show all')}
                  </Button>
                ),
              }
            : startersAvailable && canWrite
              ? {
                  moment: 'empty-documents',
                  once: 'document-templates',
                  title: t('documentTemplates.empty.starters.title', 'No templates of your own yet'),
                  description: t(
                    'documentTemplates.empty.starters.description',
                    'Start from one of Whity’s starters, such as an invoice or a receipt, and make it yours.'
                  ),
                  action: (
                    <Button variant="outline" icon="plus" onClick={() => setStarting(true)}>
                      {t('documentTemplates.new', 'New template')}
                    </Button>
                  ),
                }
              : {
                  title: t('documentTemplates.empty.templates.title', 'No templates you can see'),
                  description: t(
                    'documentTemplates.empty.templates.description',
                    'Templates are filtered by where you stand in the organisation and what you hold, so an empty list can also mean there are templates filed elsewhere.'
                  ),
                }
        }
        errorState={
          templates.error !== null && !forbidden
            ? { title: t('documentTemplates.error.templates', 'Failed to load templates'), description: templates.error }
            : undefined
        }
        pagination={{ pageSize: 25 }}
      />

      {starting && (
        <NewTemplateDialog
          templates={templates.data ?? []}
          nameOf={nameOf}
          onClose={() => setStarting(false)}
          onBlank={() => {
            setStarting(false);
            void createTemplate();
          }}
          onFrom={(row) => {
            setStarting(false);
            setCopying(row);
          }}
        />
      )}

      {copying !== null && (
        <CopyDialog
          row={copying}
          name={nameOf(copying)}
          apiClient={apiClient}
          onClose={() => setCopying(null)}
          onCopied={(id) => {
            setCopying(null);
            router.push(editorHref(id));
          }}
        />
      )}

      {scoping !== null && (
        <ScopeDialog
          row={scoping}
          name={nameOf(scoping)}
          ous={units}
          ousUnavailable={ous.error !== null}
          permissionNames={permissionOptions.data?.names ?? []}
          permissionSource={permissionOptions.data?.source ?? 'own'}
          viewerProfileId={viewerProfileId}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setScoping(null)}
          onSaved={() => {
            setScoping(null);
            templates.refetch();
          }}
        />
      )}

      {numbering !== null && (
        <NumberingDialog
          row={numbering}
          name={nameOf(numbering)}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setNumbering(null)}
          onSaved={() => {
            setNumbering(null);
            templates.refetch();
          }}
        />
      )}

      {naming !== null && (
        <PublicNameDialog
          row={naming}
          name={nameOf(naming)}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setNaming(null)}
          onSaved={() => {
            setNaming(null);
            templates.refetch();
          }}
        />
      )}

      {deleting !== null && (
        <DeleteDialog
          row={deleting}
          name={nameOf(deleting)}
          ouName={ouName(deleting.owner_ou_id)}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setDeleting(null)}
          onDeleted={() => {
            setDeleting(null);
            templates.refetch();
          }}
        />
      )}
    </div>
  );
}
