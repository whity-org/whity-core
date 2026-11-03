'use client';

/**
 * Settings: Tags (docs/design/components/TplSettingsTags). Tags and tag groups
 * on one page: each group with its tags in a line. A row opens a drawer with the
 * group's English and Arabic names and its tags (add, rename, remove). The
 * technical key appears only with Show technical details.
 *
 * Left out, because no API returns it: where each tag is used ("Used on 214
 * customers") and so how many records lose a tag when it is removed.
 */

import { useCallback, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TAGS_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import { BilingualInput, type BilingualValue } from '@whity/ui/bilingual-input';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { AreaError, EmptyFirstTime } from '@whity/ui/page-states';
import { Icon } from '@whity/ui/icon';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@whity/ui/sheet';
import { useTranslation } from '@whity/features/i18n';

interface Tag {
  id: number;
  group_id: number;
  name: string;
}

interface TagGroup {
  id: number;
  key: string;
  display_name: BilingualValue;
}

const KEY_PATTERN = /^[A-Za-z0-9_.:-]{1,64}$/;

type ApiClient = ReturnType<typeof useAuth>['apiClient'];
type AddToast = ReturnType<typeof useToast>['addToast'];

const groupLabel = (g: TagGroup): string => g.display_name?.en || g.display_name?.ar || g.key;

export default function TagsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const canManage = hasPermission(TAGS_MANAGE);
  const searchParams = useSearchParams();

  const { data, loading, error, refetch } = useFetch(async () => {
    const [tagsRes, groupsRes] = await Promise.all([
      apiClient('/api/v1/tags'),
      apiClient('/api/v1/tag-groups'),
    ]);
    if (!tagsRes.ok || !groupsRes.ok) {
      throw new Error(t('tags.error.load', 'Failed to fetch tags'));
    }
    return {
      tags: ((await tagsRes.json()).data ?? []) as Tag[],
      groups: ((await groupsRes.json()).data ?? []) as TagGroup[],
    };
  }, [apiClient]);

  const [creating, setCreating] = useState(false);
  // `/admin/tag-groups/<id>` redirects here with ?group=<id>, so an old link
  // lands on the same group's drawer.
  const [openId, setOpenId] = useState<number | null>(() => {
    const requested = Number(searchParams.get('group'));
    return Number.isInteger(requested) && requested > 0 ? requested : null;
  });
  const [deleting, setDeleting] = useState<TagGroup | null>(null);

  const groups = data?.groups ?? [];
  const tags = data?.tags ?? [];
  const rows = groups.map((group) => ({
    ...group,
    label: groupLabel(group),
    tagLine: tags
      .filter((tag) => tag.group_id === group.id)
      .map((tag) => tag.name)
      .join(', '),
  }));
  type Row = (typeof rows)[number];

  const columns: DataTableColumn<Row>[] = [
    {
      accessorKey: 'label',
      header: t('tags.table.group', 'Group'),
      enableSorting: true,
      cell: (row) => <span className="font-medium">{row.label}</span>,
    },
    {
      accessorKey: 'tagLine',
      header: t('tags.table.tags', 'Tags'),
      cell: (row) =>
        row.tagLine !== '' ? (
          row.tagLine
        ) : (
          <span className="text-muted-foreground">{t('tags.table.noTags', 'No tags yet')}</span>
        ),
    },
  ];

  const open = groups.find((group) => group.id === openId) ?? null;

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('tags.title', 'Tags')}
        description={t(
          'tags.description',
          'Labels people put on customers, documents and products. Each tag belongs to a group.'
        )}
        action={
          canManage ? (
            <Button onClick={() => setCreating(true)} className="gap-2">
              <Icon name="plus" size={16} />
              {t('tags.createGroup', 'New tag group')}
            </Button>
          ) : undefined
        }
      />

      {error !== null ? (
        <AreaError
          message={t('tags.loadError', 'Failed to load tags.')}
          retryLabel={t('tags.retry', 'Try again')}
          onRetry={refetch}
        />
      ) : !loading && groups.length === 0 ? (
        <EmptyFirstTime
          title={t('tags.empty.title', 'No tag groups yet')}
          description={t('tags.empty.description', 'Create a group, then add the tags people can use.')}
          action={
            canManage ? (
              <Button onClick={() => setCreating(true)}>{t('tags.createGroup', 'New tag group')}</Button>
            ) : undefined
          }
        />
      ) : (
        <DataTable
          columns={columns}
          data={rows}
          getRowId={(row) => String(row.id)}
          isLoading={loading}
          onRowClick={(row) => setOpenId(row.id)}
          pagination={{ pageSize: 10 }}
        />
      )}

      {creating && (
        <CreateGroupDialog
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false);
            refetch();
          }}
        />
      )}

      {open !== null && (
        <GroupDrawer
          key={open.id}
          group={open}
          tags={tags.filter((tag) => tag.group_id === open.id)}
          canManage={canManage}
          apiClient={apiClient}
          addToast={addToast}
          onClose={() => setOpenId(null)}
          onChanged={refetch}
          onDelete={() => setDeleting(open)}
        />
      )}

      {deleting !== null && (
        <ConfirmAction
          open
          onOpenChange={(next) => !next && setDeleting(null)}
          title={t('tags.deleteGroup.title', 'Delete {name}?', { name: groupLabel(deleting) })}
          description={t(
            'tags.deleteGroup.description',
            'The group and all of its tags are deleted, and those tags are removed from everything they are attached to. This cannot be undone.'
          )}
          confirmLabel={t('tags.deleteGroup.confirm', 'Delete group')}
          cancelLabel={t('tags.cancel', 'Cancel')}
          typeToConfirm={{
            value: groupLabel(deleting),
            label: t('tags.deleteGroup.type', 'Type {name} to confirm', { name: groupLabel(deleting) }),
          }}
          onConfirm={async () => {
            const response = await apiClient(`/api/v1/tag-groups/${deleting.id}`, { method: 'DELETE' });
            if (!response.ok) {
              addToast(t('tags.deleteGroup.error', 'Failed to delete tag group'), 'error');
              return;
            }
            addToast(t('tags.deleteGroup.success', 'Tag group deleted'), 'success');
            setOpenId(null);
            setDeleting(null);
            refetch();
          }}
        />
      )}
    </div>
  );
}

function GroupDrawer({
  group,
  tags,
  canManage,
  apiClient,
  addToast,
  onClose,
  onChanged,
  onDelete,
}: {
  group: TagGroup;
  tags: Tag[];
  canManage: boolean;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onChanged: () => void;
  onDelete: () => void;
}) {
  const t = useTranslation('admin');
  const [name, setName] = useState<BilingualValue>(group.display_name ?? {});
  const [key, setKey] = useState(group.key);
  const [keyError, setKeyError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [newTag, setNewTag] = useState('');
  const [renaming, setRenaming] = useState<{ id: number; name: string } | null>(null);
  const [removing, setRemoving] = useState<Tag | null>(null);

  const changed =
    key !== group.key || (name.en ?? '') !== (group.display_name?.en ?? '') || (name.ar ?? '') !== (group.display_name?.ar ?? '');

  const save = async () => {
    const trimmed = key.trim();
    if (!KEY_PATTERN.test(trimmed)) {
      setKeyError(t('tags.key.invalid', 'A key is up to 64 characters of letters, digits, and _ . : -'));
      return;
    }
    setKeyError(null);
    setSaving(true);
    try {
      const response = await apiClient(`/api/v1/tag-groups/${group.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: trimmed, display_name: { ar: name.ar ?? '', en: name.en ?? '' } }),
      });
      if (response.status === 409) {
        setKeyError(t('tags.key.conflict', 'Another group already uses this key.'));
        return;
      }
      if (!response.ok) throw new Error('save');
      addToast(t('tags.group.saved', 'Tag group updated'), 'success');
      onChanged();
    } catch {
      addToast(t('tags.group.saveError', 'Failed to save this tag group'), 'error');
    } finally {
      setSaving(false);
    }
  };

  const tagCall = useCallback(
    async (url: string, init: RequestInit, done: string, failed: string) => {
      const response = await apiClient(url, init);
      if (response.status === 409) {
        addToast(t('tags.tag.conflict', 'A tag with this name already exists in this group.'), 'error');
        return false;
      }
      if (!response.ok) {
        addToast(failed, 'error');
        return false;
      }
      addToast(done, 'success');
      onChanged();
      return true;
    },
    [apiClient, addToast, onChanged, t]
  );

  const addTag = async () => {
    const trimmed = newTag.trim();
    if (trimmed === '' || trimmed.length > 128) return;
    const ok = await tagCall(
      '/api/v1/tags',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ group_id: group.id, name: trimmed }),
      },
      t('tags.tag.created', 'Tag added'),
      t('tags.tag.createError', 'Failed to add the tag')
    );
    if (ok) setNewTag('');
  };

  const renameTag = async () => {
    if (renaming === null) return;
    const trimmed = renaming.name.trim();
    if (trimmed === '' || trimmed.length > 128) return;
    const ok = await tagCall(
      `/api/v1/tags/${renaming.id}`,
      {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: trimmed }),
      },
      t('tags.tag.renamed', 'Tag renamed'),
      t('tags.tag.renameError', 'Failed to rename the tag')
    );
    if (ok) setRenaming(null);
  };

  return (
    <Sheet open onOpenChange={(next) => !next && onClose()}>
      <SheetContent closeLabel={t('tags.close', 'Close')}>
        <SheetHeader>
          <SheetTitle>{groupLabel(group)}</SheetTitle>
          <SheetDescription>
            {t('tags.drawer.description', 'The names people see, and the tags in this group.')}
          </SheetDescription>
        </SheetHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <label htmlFor="tag-group-display-name" className="text-sm font-medium">
              {t('tags.group.name', 'Name')}
            </label>
            <BilingualInput
              id="tag-group-display-name"
              value={name}
              onChange={setName}
              disabled={!canManage}
            />
          </div>

          <TechnicalDetails>
            <div className="space-y-1.5">
              <label htmlFor="tag-group-key" className="text-sm font-medium">
                {t('tags.group.key', 'Key')}
              </label>
              <Input
                id="tag-group-key"
                value={key}
                dir="ltr"
                disabled={!canManage}
                onChange={(e) => setKey(e.target.value)}
                autoComplete="off"
              />
              {keyError !== null && <p className="text-sm text-destructive">{keyError}</p>}
            </div>
          </TechnicalDetails>

          <div className="space-y-2">
            <p className="text-sm font-medium">{t('tags.drawer.tags', 'Tags')}</p>
            {tags.length === 0 && (
              <p className="text-sm text-muted-foreground">{t('tags.drawer.noTags', 'This group holds no tags yet.')}</p>
            )}
            <ul className="divide-y divide-border rounded-card border border-border">
              {tags.map((tag) => (
                <li key={tag.id} className="flex items-center gap-2 p-2">
                  {renaming?.id === tag.id ? (
                    <>
                      <Input
                        aria-label={t('tags.tag.nameLabel', 'Tag name')}
                        value={renaming.name}
                        onChange={(e) => setRenaming({ id: tag.id, name: e.target.value })}
                        onKeyDown={(e) => e.key === 'Enter' && void renameTag()}
                        autoFocus
                      />
                      <Button size="sm" onClick={() => void renameTag()}>
                        {t('tags.tag.save', 'Save')}
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => setRenaming(null)}>
                        {t('tags.cancel', 'Cancel')}
                      </Button>
                    </>
                  ) : (
                    <>
                      <span className="min-w-0 flex-1 truncate text-sm">{tag.name}</span>
                      {canManage && (
                        <>
                          <Button size="sm" variant="outline" onClick={() => setRenaming({ id: tag.id, name: tag.name })}>
                            {t('tags.tag.rename', 'Rename')}
                          </Button>
                          <Button size="sm" variant="outline" onClick={() => setRemoving(tag)}>
                            {t('tags.tag.remove', 'Remove')}
                          </Button>
                        </>
                      )}
                    </>
                  )}
                </li>
              ))}
            </ul>

            {canManage && (
              <div className="flex gap-2">
                <Input
                  aria-label={t('tags.tag.newLabel', 'New tag')}
                  value={newTag}
                  placeholder={t('tags.tag.newPlaceholder', 'New tag')}
                  onChange={(e) => setNewTag(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && void addTag()}
                />
                <Button variant="outline" onClick={() => void addTag()} disabled={newTag.trim() === ''}>
                  {t('tags.tag.add', 'Add tag')}
                </Button>
              </div>
            )}
          </div>
        </div>

        {canManage && (
          <SheetFooter>
            <Button variant="outline" className="text-destructive" onClick={onDelete}>
              {t('tags.deleteGroup.button', 'Delete group')}
            </Button>
            <Button onClick={() => void save()} disabled={!changed || saving}>
              {saving ? t('tags.saving', 'Saving…') : t('tags.save', 'Save changes')}
            </Button>
          </SheetFooter>
        )}

        {removing !== null && (
          <ConfirmAction
            open
            onOpenChange={(next) => !next && setRemoving(null)}
            title={t('tags.tag.removeTitle', 'Remove {name}?', { name: removing.name })}
            description={t(
              'tags.tag.removeDescription',
              'The tag is removed from everything it is attached to. This is hard to undo.'
            )}
            confirmLabel={t('tags.tag.removeConfirm', 'Remove tag')}
            cancelLabel={t('tags.cancel', 'Cancel')}
            onConfirm={async () => {
              await tagCall(
                `/api/v1/tags/${removing.id}`,
                { method: 'DELETE' },
                t('tags.tag.removed', 'Tag removed'),
                t('tags.tag.removeError', 'Failed to remove the tag')
              );
              setRemoving(null);
            }}
          />
        )}
      </SheetContent>
    </Sheet>
  );
}

function CreateGroupDialog({
  apiClient,
  addToast,
  onClose,
  onSaved,
}: {
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const [key, setKey] = useState('');
  const [displayName, setDisplayName] = useState<BilingualValue>({});
  const [keyError, setKeyError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    // People name a group; the key is made from the English name when it is
    // not typed (technical details show the field for those who want to set it).
    const typed = key.trim() !== '' ? key.trim() : (displayName.en ?? '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (!KEY_PATTERN.test(typed)) {
      setKeyError(t('tags.key.needName', 'Give the group an English name, or a key of letters, digits, and _ . : -'));
      return;
    }
    setKeyError(null);
    setSubmitting(true);
    try {
      const response = await apiClient('/api/v1/tag-groups', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: typed, display_name: { ar: displayName.ar ?? '', en: displayName.en ?? '' } }),
      });
      if (response.status === 409) {
        setKeyError(t('tags.key.conflict', 'Another group already uses this key.'));
        return;
      }
      if (!response.ok) throw new Error('save');
      addToast(t('tags.group.created', 'Tag group created'), 'success');
      onSaved();
    } catch {
      addToast(t('tags.group.saveError', 'Failed to save this tag group'), 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(next) => !next && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('tags.createGroup', 'New tag group')}</DialogTitle>
          <DialogDescription>
            {t('tags.create.description', 'Name the group in English and Arabic. You add its tags next.')}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-1.5">
            <label htmlFor="tag-group-new-name" className="text-sm font-medium">
              {t('tags.group.name', 'Name')}
            </label>
            <BilingualInput id="tag-group-new-name" value={displayName} onChange={setDisplayName} />
          </div>
          <TechnicalDetails>
            <div className="space-y-1.5">
              <label htmlFor="tag-group-new-key" className="text-sm font-medium">
                {t('tags.group.key', 'Key')}
              </label>
              <Input id="tag-group-new-key" value={key} dir="ltr" onChange={(e) => setKey(e.target.value)} autoComplete="off" />
            </div>
          </TechnicalDetails>
          {keyError !== null && <p className="text-sm text-destructive">{keyError}</p>}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('tags.cancel', 'Cancel')}
          </Button>
          <Button type="button" onClick={() => void submit()} disabled={submitting}>
            {submitting ? t('tags.saving', 'Saving…') : t('tags.create', 'Create group')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
