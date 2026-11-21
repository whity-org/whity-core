'use client';

/**
 * /admin/document-stamps (#1729): Settings › Company stamps.
 *
 * A company stamp is the workspace's: a name, the legal name it stands for,
 * the image of the stamp, and the people (or roles) who hold it. On a route, a
 * `stamp` step can only be answered by one of its holders applying it, with
 * the same consent and second check as signing. The page calls that what it
 * is: an authorised person applying the image of the stamp, not a certified
 * electronic seal.
 *
 * Anyone who may read documents sees the list; adding, editing, choosing
 * holders, archiving and restoring need `documents:stamps`. Archiving is undone
 * by restoring, so it asks nothing first (section 21: confirm by how undoable).
 */

import { useCallback, useState } from 'react';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { api } from '@/lib/api/client';
import { fetchAllPagesTyped } from '@/lib/api/fetch-all-pages';
import { DOCUMENTS_STAMPS } from '@/lib/capabilities';
import { throwUnlessOk } from '@/lib/forbidden';
import { useToast } from '@/lib/toast-context';
import type { AudiencePersonOption } from '@whity/ui/audience-people-picker';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { PageHeader } from '@whity/ui/page-header';
import { EmptyFirstTime, ReadOnlyNotice } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { holdersChanged, refusalOf, type CompanyStamp, type StampDraft, type StampField } from './model';
import { StampEditor, type Catalogue } from './stamp-editor';

type Editing = { stamp: CompanyStamp | null } | null;

export default function DocumentStampsPage() {
  const t = useTranslation('documents');
  const { addToast } = useToast();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const canWrite = hasPermission(DOCUMENTS_STAMPS);

  const [showArchived, setShowArchived] = useState(false);
  const loadFailed = t('stamps.loadFailed', 'The company stamps could not be loaded.');
  const { data, error, forbidden, refetch } = useFetch<CompanyStamp[]>(async () => {
    const { data: body, response } = await api.GET('/api/v1/document-stamps', {
      params: { query: showArchived ? { archived: true } : {} },
    } as never);
    throwUnlessOk(response, loadFailed);
    return ((body as unknown as { data?: CompanyStamp[] })?.data ?? []) as CompanyStamp[];
  }, [showArchived]);

  const [editing, setEditing] = useState<Editing>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<{ message: string; field: StampField | null } | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  // The pickers' catalogues, read only once someone opens the editor.
  const wantCatalogues = canWrite && editing !== null;
  const { data: people } = useFetch<Catalogue<AudiencePersonOption> | null>(async () => {
    if (!wantCatalogues) return null;
    const { response: probe } = await api.GET('/api/v1/users', { params: { query: { page: 1 } } });
    if (!probe.ok) {
      return {
        items: [],
        incomplete: null,
        unavailable: t(
          'stamps.people.forbidden',
          'You cannot list people here, so you cannot name one. Choose a role instead, or ask someone who manages users.'
        ),
      };
    }
    const all = await fetchAllPagesTyped<{ id: number; name: string; email?: string | null }>(
      (query) => api.GET('/api/v1/users', { params: { query } }) as never
    );
    return {
      items: all.items.map((p) => ({
        id: p.id,
        name: typeof p.name === 'string' && p.name !== '' ? p.name : (p.email ?? String(p.id)),
        secondary: p.email ?? null,
      })),
      unavailable: null,
      incomplete: all.complete
        ? null
        : t('stamps.people.partial', 'Only some people could be loaded, so this list may be incomplete.'),
    };
  }, [wantCatalogues]);
  const { data: roles } = useFetch<Catalogue<{ id: number; name: string }> | null>(async () => {
    if (!wantCatalogues) return null;
    const { response: probe } = await api.GET('/api/v1/roles', { params: { query: { page: 1 } } });
    if (!probe.ok) {
      return {
        items: [],
        incomplete: null,
        unavailable: t('stamps.roles.forbidden', 'You cannot list roles here, so you cannot choose one.'),
      };
    }
    const all = await fetchAllPagesTyped<{ id: number; name: string }>(
      (query) => api.GET('/api/v1/roles', { params: { query } }) as never
    );
    return {
      items: all.items.map((r) => ({ id: r.id, name: r.name })),
      unavailable: null,
      incomplete: all.complete
        ? null
        : t('stamps.roles.partial', 'Only some roles could be loaded, so this list may be incomplete.'),
    };
  }, [wantCatalogues]);

  const open = (stamp: CompanyStamp | null) => {
    setProblem(null);
    setEditing({ stamp });
  };

  const save = useCallback(
    async (draft: StampDraft) => {
      if (editing === null) return;
      const failed = t('stamps.saveFailed', 'The stamp could not be saved. Try again.');
      setSaving(true);
      setProblem(null);
      try {
        const existing = editing.stamp;
        if (existing === null) {
          const { error: err, response } = await api.POST('/api/v1/document-stamps', {
            body: {
              name: draft.name,
              legal_name: draft.legal_name,
              image: draft.image,
              holders: { people: draft.people, roles: draft.roles },
            } as never,
          });
          if (!response.ok) {
            setProblem(refusalOf(err, failed));
            return;
          }
          addToast(t('stamps.added', 'Stamp added'), 'success');
        } else {
          const changes: Record<string, string> = {};
          if (draft.name !== existing.name) changes.name = draft.name;
          if (draft.legal_name !== existing.legal_name) changes.legal_name = draft.legal_name;
          if (draft.image !== existing.image) changes.image = draft.image;
          if (Object.keys(changes).length > 0) {
            const { error: err, response } = await api.PATCH('/api/v1/document-stamps/{id}', {
              params: { path: { id: existing.id } },
              body: changes as never,
            });
            if (!response.ok) {
              setProblem(refusalOf(err, failed));
              return;
            }
          }
          if (holdersChanged(existing, draft)) {
            const { error: err, response } = await api.PUT('/api/v1/document-stamps/{id}/holders', {
              params: { path: { id: existing.id } },
              body: { people: draft.people, roles: draft.roles } as never,
            });
            if (!response.ok) {
              setProblem(refusalOf(err, failed));
              return;
            }
          }
          addToast(t('stamps.saved', 'Stamp saved'), 'success');
        }
        setEditing(null);
        refetch();
      } catch {
        setProblem({ message: failed, field: null });
      } finally {
        setSaving(false);
      }
    },
    [editing, addToast, refetch, t]
  );

  const setArchived = async (stamp: CompanyStamp, archive: boolean) => {
    setBusyId(stamp.id);
    try {
      const { error: err, response } = archive
        ? await api.POST('/api/v1/document-stamps/{id}/archive', { params: { path: { id: stamp.id } } })
        : await api.POST('/api/v1/document-stamps/{id}/restore', { params: { path: { id: stamp.id } } });
      if (!response.ok) {
        addToast(
          refusalOf(
            err,
            archive
              ? t('stamps.archiveFailed', 'The stamp could not be archived. Try again.')
              : t('stamps.restoreFailed', 'The stamp could not be restored. Try again.')
          ).message,
          'error'
        );
        return;
      }
      addToast(
        archive
          ? t('stamps.archived', 'Stamp archived. Steps that ask for it cannot be answered until you restore it.')
          : t('stamps.restored', 'Stamp restored'),
        'success'
      );
      refetch();
    } catch {
      addToast(t('stamps.saveFailed', 'The stamp could not be saved. Try again.'), 'error');
    } finally {
      setBusyId(null);
    }
  };

  if (!capabilitiesLoading && !hasPermission('documents:read')) {
    return <NoAccess area={t('stamps.area', 'company stamps')} permission="documents:read" />;
  }

  const holderText = (stamp: CompanyStamp): string => {
    const names = [
      ...stamp.holders.people.map((p) => p.name),
      ...stamp.holders.roles.map((r) => t('stamps.list.role', '{name} (role)', { name: r.name })),
    ];
    return names.length === 0
      ? t('stamps.list.noHolders', 'Nobody holds it yet')
      : t('stamps.list.heldBy', 'Held by {names}', { names: names.join(', ') });
  };

  const stamps = data ?? [];

  return (
    <div className="pb-16" data-testid="document-stamps">
      <PageHeader
        title={t('stamps.title', 'Company stamps')}
        description={t(
          'stamps.description',
          'The workspace’s stamps and who holds each one. On a document route, only a holder can apply a stamp: its image goes on the document with an audit trail. This is not a certified electronic seal.'
        )}
        action={
          canWrite ? (
            <Button onClick={() => open(null)} data-testid="stamps-add">
              {t('stamps.add', 'Add a stamp')}
            </Button>
          ) : undefined
        }
        className="mb-4 border-b-0 pb-0"
      />

      {!capabilitiesLoading && !canWrite && (
        <ReadOnlyNotice
          className="mb-4"
          title={t('stamps.readOnly.title', 'You can see the stamps but not change them')}
          description={t('stamps.readOnly.body', 'Someone who manages company stamps can add them and choose who holds each.')}
        />
      )}

      <label className="mb-4 flex items-center gap-2 text-sm text-foreground">
        <Checkbox checked={showArchived} onCheckedChange={(v) => setShowArchived(v === true)} />
        {t('stamps.showArchived', 'Show archived stamps')}
      </label>

      {error ? (
        <LoadProblem forbidden={forbidden} message={error} onRetry={refetch} area={t('stamps.area', 'company stamps')} />
      ) : data === null ? (
        <div role="status" aria-busy="true" aria-label={t('stamps.loading', 'Loading the company stamps')} className="grid gap-3">
          <Skeleton className="h-24 w-full rounded-card" />
          <Skeleton className="h-24 w-full rounded-card" />
        </div>
      ) : stamps.length === 0 ? (
        <EmptyFirstTime
          icon="approval"
          title={t('stamps.none.title', 'No company stamps yet')}
          description={
            canWrite
              ? t('stamps.none.body', 'Add the company’s stamp, then choose who may apply it on document routes.')
              : t('stamps.none.readOnly', 'Someone who manages company stamps can add one.')
          }
        />
      ) : (
        <ul className="grid gap-3" data-testid="stamps-list">
          {stamps.map((stamp) => (
            <li
              key={stamp.id}
              className="flex flex-wrap items-center gap-4 rounded-card border border-border bg-card p-4"
              data-testid="stamp-row"
            >
              {/* A data URL the server checked (PNG or JPEG); next/image adds nothing here. */}
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={stamp.image}
                alt={t('stamping.imageAlt', 'The image of the stamp {name}', { name: stamp.name })}
                className="size-20 shrink-0 object-contain"
              />
              <div className="min-w-0 flex-1 space-y-1">
                <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-foreground">
                  {stamp.name}
                  {stamp.archived_at !== null && <Badge variant="secondary">{t('stamps.list.archived', 'Archived')}</Badge>}
                </p>
                <p className="text-sm text-muted-foreground">
                  {t('stamping.legalName', 'Stands for {legalName}', { legalName: stamp.legal_name })}
                </p>
                <p className="text-sm text-muted-foreground">{holderText(stamp)}</p>
              </div>
              {canWrite && (
                <div className="flex flex-wrap gap-2">
                  {stamp.archived_at === null && (
                    <Button variant="outline" onClick={() => open(stamp)} disabled={busyId === stamp.id}>
                      {t('stamps.edit', 'Edit')}
                    </Button>
                  )}
                  <Button
                    variant="outline"
                    onClick={() => void setArchived(stamp, stamp.archived_at === null)}
                    loading={busyId === stamp.id}
                  >
                    {stamp.archived_at === null ? t('stamps.archive', 'Archive') : t('stamps.restore', 'Restore')}
                  </Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      <StampEditor
        open={editing !== null}
        stamp={editing?.stamp ?? null}
        people={people}
        roles={roles}
        saving={saving}
        problem={problem}
        onOpenChange={(next) => {
          if (!next && !saving) setEditing(null);
        }}
        onSave={(draft) => void save(draft)}
      />
    </div>
  );
}
