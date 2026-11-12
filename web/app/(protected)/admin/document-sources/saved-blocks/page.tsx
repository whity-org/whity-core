'use client';

import { useCallback, useMemo, useState } from 'react';
import Link from 'next/link';
import { IconCopy, IconRefresh } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { Input } from '@whity/ui/input';
import { AreaError } from '@whity/ui/page-states';
import { RadioGroup, RadioGroupItem } from '@whity/ui/radio-group';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { DOCUMENTS_PUBLISH, DOCUMENTS_WRITE } from '@/lib/capabilities';
import { useToast } from '@/lib/toast-context';
import {
  deleteLibraryBlock,
  libraryBlockUsage,
  listLibrary,
  updateLibraryBlock,
  type LibraryBlock,
} from '@/lib/documents/saved-blocks';

type Filter = 'all' | 'mine' | 'workspace';

/**
 * Saved blocks (editor M7; EditorCustomBlocks, E.21–E.22): this person's own
 * blocks and the workspace library. Each row opens a drawer to rename it,
 * change who can use it, see where a synced block is used, edit the original
 * or delete it. People manage their own blocks; the workspace library is
 * managed with documents:publish until documents:library:manage exists.
 * Blocks are made from a document (Save as block in the block menu).
 */
export default function SavedBlocksPage() {
  const t = useTranslation('documents');
  const dates = useDateDisplay();
  const { user } = useAuth();
  const { hasPermission } = useCapabilities();
  const viewerId = typeof user?.id === 'number' ? user.id : null;
  const canManageLibrary = hasPermission(DOCUMENTS_PUBLISH);
  const [filter, setFilter] = useState<Filter>('all');
  const [openId, setOpenId] = useState<number | null>(null);

  const load = useCallback(() => listLibrary(), []);
  const { data, loading, error, refetch } = useFetch(load);

  const mine = useCallback((b: LibraryBlock) => b.owner?.kind === 'person' && Number(b.owner.id) === viewerId, [viewerId]);
  const rows = useMemo(
    () => (data ?? []).filter((b) => (filter === 'mine' ? mine(b) : filter === 'workspace' ? b.sharing === 'workspace' : true)),
    [data, filter, mine]
  );
  const open = openId !== null ? (data ?? []).find((b) => b.id === openId) ?? null : null;

  const owner = useCallback(
    (b: LibraryBlock) => (mine(b) ? t('savedBlocks.you', 'You') : b.owner?.name || t('savedBlocks.former', 'A former member')),
    [mine, t]
  );

  const columns = useMemo<DataTableColumn<LibraryBlock>[]>(
    () => [
      {
        accessorKey: 'name',
        header: t('savedBlocks.column.name', 'Saved block'),
        enableSorting: true,
        cell: (b) => (
          <span className="flex min-w-0 items-center gap-3">
            {b.mode === 'synced' ? (
              <IconRefresh className="size-5 shrink-0 text-muted-foreground" aria-hidden />
            ) : (
              <IconCopy className="size-5 shrink-0 text-muted-foreground" aria-hidden />
            )}
            <span className="truncate font-medium text-foreground" dir="auto">
              {b.name}
            </span>
            <Badge variant="secondary">{b.mode === 'synced' ? t('savedBlocks.synced', 'Synced') : t('savedBlocks.copy', 'Copy')}</Badge>
          </span>
        ),
      },
      {
        id: 'sharing',
        header: t('savedBlocks.column.sharing', 'Who can use it'),
        cell: (b) => (b.sharing === 'workspace' ? t('savedBlocks.everyone', 'Everyone in the workspace') : t('savedBlocks.onlyOwner', 'Only the person who saved it')),
      },
      {
        id: 'owner',
        header: t('savedBlocks.column.owner', 'Saved by'),
        cell: (b) => (
          <span className="flex items-center gap-2">
            <Avatar name={owner(b)} size={24} />
            {owner(b)}
          </span>
        ),
      },
      {
        id: 'usedIn',
        header: t('savedBlocks.column.usedIn', 'Used in'),
        cell: (b) =>
          b.mode !== 'synced'
            ? '—'
            : b.usedIn === 1
              ? t('savedBlocks.usedInOne', '1 document')
              : t('savedBlocks.usedIn', '{n} documents', { n: b.usedIn }),
      },
      ...dates.dateColumns<LibraryBlock>([
        { id: 'updatedAt', header: t('savedBlocks.column.changed', 'Changed'), value: (b) => b.updatedAt, enableSorting: true, className: 'text-muted-foreground' },
      ]),
    ],
    [dates, owner, t]
  );

  return (
    <div className="grid gap-6">
      <AdminHeader
        title={t('savedBlocks.title', 'Saved blocks')}
        description={t(
          'savedBlocks.description',
          'Blocks saved from documents to insert again from the / menu. Editing a synced block updates every document that uses it; published templates pick up the change when they are published again.'
        )}
      />

      <Tabs value={filter} onValueChange={(value) => setFilter(value as Filter)}>
        <TabsList aria-label={t('savedBlocks.filter', 'Which saved blocks to show')}>
          <TabsTrigger value="all">{t('savedBlocks.filterAll', 'All')}</TabsTrigger>
          <TabsTrigger value="mine">{t('savedBlocks.filterMine', 'Mine')}</TabsTrigger>
          <TabsTrigger value="workspace">{t('savedBlocks.filterWorkspace', 'Workspace library')}</TabsTrigger>
        </TabsList>
      </Tabs>

      {error ? (
        <AreaError message={t('savedBlocks.loadFailed', 'The saved blocks didn’t load')} retryLabel={t('savedBlocks.retry', 'Try again')} onRetry={() => void refetch()} />
      ) : (
        <DataTable
          columns={columns}
          data={rows}
          getRowId={(b) => String(b.id)}
          isLoading={loading}
          onRowClick={(b) => setOpenId(b.id)}
          ariaLabel={t('savedBlocks.title', 'Saved blocks')}
          pagination={{ pageSize: 25 }}
          emptyState={{
            title: t('savedBlocks.emptyTitle', 'No saved blocks yet'),
            description: t('savedBlocks.empty', 'In a document, open a block’s menu and choose Save as block. It then appears here and in the / menu.'),
          }}
        />
      )}

      {open && (
        <SavedBlockDrawer
          block={open}
          canManage={mine(open) || canManageLibrary}
          canShare={canManageLibrary}
          canForceDelete={canManageLibrary}
          canEdit={hasPermission(DOCUMENTS_WRITE)}
          onClose={() => setOpenId(null)}
          onChanged={() => void refetch()}
        />
      )}
    </div>
  );
}

function SavedBlockDrawer({
  block,
  canManage,
  canShare,
  canForceDelete,
  canEdit,
  onClose,
  onChanged,
}: {
  block: LibraryBlock;
  canManage: boolean;
  canShare: boolean;
  canForceDelete: boolean;
  canEdit: boolean;
  onClose: () => void;
  onChanged: () => void;
}) {
  const t = useTranslation('documents');
  const { addToast } = useToast();
  const [name, setName] = useState(block.name);
  const [sharing, setSharing] = useState(block.sharing);
  const [saving, setSaving] = useState(false);
  const [asking, setAsking] = useState(false);
  // A synced block still in use: its own dialog, after the first one closes.
  const [inUse, setInUse] = useState<number | null>(null);
  const usage = useFetch(() => libraryBlockUsage(block.id), [block.id]);
  const changed = name.trim() !== block.name || sharing !== block.sharing;

  const save = async () => {
    setSaving(true);
    const result = await updateLibraryBlock(block.id, { name: name.trim(), sharing });
    setSaving(false);
    if (result.ok) {
      addToast(t('savedBlocks.saved', 'Saved'), 'success');
      onChanged();
      return;
    }
    addToast(
      result.reason === 'changed'
        ? t('savedBlocks.changedElsewhere', 'Someone changed this block meanwhile. Close it and open it again.')
        : t('savedBlocks.saveFailed', 'That didn’t save. Try again.'),
      'error'
    );
  };

  const remove = async (force: boolean) => {
    const result = await deleteLibraryBlock(block.id, force);
    if (result.ok) {
      addToast(t('savedBlocks.deleted', 'Saved block deleted'), 'success');
      setInUse(null);
      onClose();
      onChanged();
      return;
    }
    if (result.usedIn !== null && !force) {
      setInUse(result.usedIn);
      return;
    }
    setInUse(null);
    addToast(t('savedBlocks.deleteFailed', 'The block wasn’t deleted. Try again.'), 'error');
  };

  return (
    <Sheet open onOpenChange={(next) => !next && onClose()}>
      <SheetContent closeLabel={t('savedBlocks.close', 'Close')}>
        <SheetHeader>
          <SheetTitle dir="auto">{block.name}</SheetTitle>
          <SheetDescription>
            {block.mode === 'synced'
              ? t('savedBlocks.syncedHint', 'Synced: editing the original updates every document that uses it.')
              : t('savedBlocks.copyHint', 'Copy: each insert is a copy people change freely.')}
          </SheetDescription>
        </SheetHeader>

        <div className="space-y-5">
          <div className="space-y-1.5">
            <label htmlFor="saved-block-name" className="text-sm font-medium">
              {t('savedBlocks.name', 'Name')}
            </label>
            <Input id="saved-block-name" value={name} maxLength={120} disabled={!canManage} onChange={(e) => setName(e.target.value)} />
          </div>

          {canShare && canManage && (
            <fieldset className="space-y-2">
              <legend className="mb-1.5 text-sm font-medium">{t('savedBlocks.column.sharing', 'Who can use it')}</legend>
              <RadioGroup value={sharing} onValueChange={(v) => setSharing(v as typeof sharing)} className="grid gap-2">
                <label className="flex items-center gap-2.5 text-sm">
                  <RadioGroupItem value="me" />
                  {t('savedBlocks.onlyOwner', 'Only the person who saved it')}
                </label>
                <label className="flex items-center gap-2.5 text-sm">
                  <RadioGroupItem value="workspace" />
                  {t('savedBlocks.everyone', 'Everyone in the workspace')}
                </label>
              </RadioGroup>
            </fieldset>
          )}

          {block.mode === 'synced' && (
            <div className="space-y-2">
              <p className="text-sm font-medium">{t('savedBlocks.whereUsed', 'Where it’s used')}</p>
              {usage.loading ? (
                <div className="h-10 animate-pulse rounded-control bg-muted" aria-busy="true" />
              ) : usage.error ? (
                <AreaError message={t('savedBlocks.usageFailed', 'Where it’s used didn’t load')} retryLabel={t('savedBlocks.retry', 'Try again')} onRetry={() => void usage.refetch()} />
              ) : (usage.data?.documents.length ?? 0) === 0 && (usage.data?.hidden ?? 0) === 0 ? (
                <p className="text-sm text-muted-foreground">{t('savedBlocks.unused', 'No document uses it yet.')}</p>
              ) : (
                <ul className="divide-y divide-border rounded-card border border-border">
                  {usage.data?.documents.map((d) => (
                    <li key={d.id} className="flex min-h-12 items-center gap-2 px-3">
                      <Link href={`/admin/document-sources/${d.id}`} className="min-w-0 flex-1 truncate text-sm text-primary underline-offset-2 hover:underline" dir="auto">
                        {d.title || t('blockEditor.untitled', 'Untitled')}
                      </Link>
                    </li>
                  ))}
                  {(usage.data?.hidden ?? 0) > 0 && (
                    <li className="flex min-h-12 items-center px-3 text-sm text-muted-foreground">
                      {t('savedBlocks.hidden', 'And {n} you can’t see', { n: usage.data?.hidden ?? 0 })}
                    </li>
                  )}
                </ul>
              )}
            </div>
          )}

          {canManage && canEdit && (
            <Button variant="outline" icon="edit" asChild>
              <Link href={`/admin/document-sources/saved-blocks/${block.id}`}>{t('savedBlocks.editOriginal', 'Edit original')}</Link>
            </Button>
          )}
        </div>

        {canManage && (
          <SheetFooter>
            <Button variant="outline" className="text-destructive" onClick={() => setAsking(true)}>
              {t('savedBlocks.delete', 'Delete')}
            </Button>
            <Button onClick={() => void save()} disabled={!changed || name.trim() === '' || saving} loading={saving}>
              {t('savedBlocks.saveChanges', 'Save changes')}
            </Button>
          </SheetFooter>
        )}

        {asking && (
          <ConfirmAction
            open
            onOpenChange={(next) => !next && setAsking(false)}
            title={t('savedBlocks.deleteTitle', 'Delete {name}?', { name: block.name })}
            description={t('savedBlocks.deleteDescription', 'It leaves the / menu and this library. Documents and published templates that use it keep what they show.')}
            confirmLabel={t('savedBlocks.delete', 'Delete')}
            cancelLabel={t('savedBlocks.cancel', 'Cancel')}
            onConfirm={() => remove(false)}
          />
        )}
        {inUse !== null && (
          <ConfirmAction
            open
            onOpenChange={(next) => !next && setInUse(null)}
            title={t('savedBlocks.inUseTitle', '{name} is used in {n} documents', { name: block.name, n: inUse })}
            description={
              canForceDelete
                ? t('savedBlocks.inUseForce', 'Those documents will say the block is no longer available, and templates using it can’t be published until it’s removed or detached. This is hard to undo.')
                : t('savedBlocks.inUseNoForce', 'Only someone who can publish documents can delete a synced block that documents still use.')
            }
            confirmLabel={canForceDelete ? t('savedBlocks.deleteAnyway', 'Delete anyway') : t('savedBlocks.ok', 'OK')}
            cancelLabel={t('savedBlocks.cancel', 'Cancel')}
            onConfirm={() => (canForceDelete ? remove(true) : setInUse(null))}
            typeToConfirm={canForceDelete ? { value: block.name, label: t('savedBlocks.typeName', 'Type {name} to confirm', { name: block.name }) } : undefined}
          />
        )}
      </SheetContent>
    </Sheet>
  );
}
