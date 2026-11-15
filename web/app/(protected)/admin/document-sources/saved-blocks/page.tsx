'use client';

import { useCallback, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@whity/ui/dropdown-menu';
import { Icon, type IconName } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { AreaError } from '@whity/ui/page-states';
import { SearchField } from '@whity/ui/search-field';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import type { Block, PerDocumentPart } from '@whity/ui/editor/contract';
import { AdminHeader } from '@/components/admin/admin-header';
import { FilterMenu } from '@/components/admin/filter-menu';
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
import { SavedBlockPreview } from './saved-block-preview';

type Kind = 'synced' | 'copy';
type Sharing = 'me' | 'workspace';

/**
 * Saved blocks (editor M7; E.21–E.24, E.38; card TplSavedBlocks): this
 * person's own blocks and the workspace library, filtered by kind and by who
 * can use them. Each row opens a side panel with the block's text, its
 * per-document parts marked (E.23a) and where it's used; the row menu renames
 * it, changes who can use it, edits the original or deletes it. People manage
 * their own blocks; the workspace library is managed with documents:publish
 * until documents:library:manage exists. Blocks are made from a document
 * (Save as block in the block menu).
 */
export default function SavedBlocksPage() {
  const t = useTranslation('documents');
  const dates = useDateDisplay();
  const { user } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const viewerId = typeof user?.id === 'number' ? user.id : null;
  const canManageLibrary = hasPermission(DOCUMENTS_PUBLISH);
  const canEdit = hasPermission(DOCUMENTS_WRITE);
  const [kind, setKind] = useState<Kind[]>([]);
  const [sharing, setSharing] = useState<Sharing[]>([]);
  const [query, setQuery] = useState('');
  const [openId, setOpenId] = useState<number | null>(null);
  const [renaming, setRenaming] = useState<LibraryBlock | null>(null);
  const [deleting, setDeleting] = useState<LibraryBlock | null>(null);

  const load = useCallback(() => listLibrary(), []);
  const { data, loading, error, refetch } = useFetch(load);

  const mine = useCallback((b: LibraryBlock) => b.owner?.kind === 'person' && Number(b.owner.id) === viewerId, [viewerId]);
  const canManage = useCallback((b: LibraryBlock) => mine(b) || canManageLibrary, [mine, canManageLibrary]);
  const rows = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase();
    return (data ?? []).filter(
      (b) =>
        (kind.length === 0 || kind.includes(b.mode)) &&
        (sharing.length === 0 || sharing.includes(b.sharing)) &&
        (needle === '' || `${b.name} ${b.description ?? ''}`.toLocaleLowerCase().includes(needle))
    );
  }, [data, kind, sharing, query]);
  const open = openId !== null ? (data ?? []).find((b) => b.id === openId) ?? null : null;

  const kindBadge = useCallback(
    (b: LibraryBlock) => (
      <Badge icon={b.mode === 'synced' ? 'sync' : 'copy'} className="bg-muted text-muted-foreground">
        {b.mode === 'synced' ? t('savedBlocks.synced', 'Synced') : t('savedBlocks.copy', 'Copy')}
      </Badge>
    ),
    [t]
  );

  const changeSharing = async (b: LibraryBlock, next: Sharing) => {
    const result = await updateLibraryBlock(b.id, { sharing: next });
    if (result.ok) {
      addToast(t('savedBlocks.saved', 'Saved'), 'success');
      void refetch();
      return;
    }
    addToast(
      result.reason === 'changed'
        ? t('savedBlocks.changedElsewhere', 'Someone changed this block meanwhile. Close it and open it again.')
        : t('savedBlocks.saveFailed', 'That didn’t save. Try again.'),
      'error'
    );
  };

  const columns = useMemo<DataTableColumn<LibraryBlock>[]>(
    () => [
      {
        accessorKey: 'name',
        header: t('savedBlocks.column.block', 'Block'),
        enableSorting: true,
        cell: (b) => (
          <span className="grid min-w-0">
            <span className="truncate text-foreground" dir="auto">
              {b.name}
            </span>
            {b.description && (
              <span className="truncate text-sm text-muted-foreground" dir="auto">
                {b.description}
              </span>
            )}
          </span>
        ),
      },
      { id: 'kind', header: t('savedBlocks.column.kind', 'Kind'), cell: kindBadge },
      {
        id: 'sharing',
        header: t('savedBlocks.column.sharing', 'Who can use it'),
        cell: (b) => (b.sharing === 'workspace' ? t('savedBlocks.everyone', 'Everyone in the workspace') : t('savedBlocks.onlyMe', 'Only me')),
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
        { id: 'updatedAt', header: t('savedBlocks.column.changed', 'Changed'), value: (b) => b.updatedAt, withTime: false, enableSorting: true, className: 'text-muted-foreground' },
      ]),
    ],
    [dates, kindBadge, t]
  );

  return (
    <div className="grid gap-6">
      <AdminHeader
        breadcrumb={
          <nav aria-label={t('savedBlocks.breadcrumb', 'Breadcrumb')} className="flex flex-wrap items-center gap-1">
            <Link href="/admin/document-library" className="hover:underline">
              {t('savedBlocks.crumb.documents', 'Documents')}
            </Link>
            <Icon name="chevron-right" size={12} className="rtl:-scale-x-100" aria-hidden />
            <span aria-current="page" className="text-foreground">
              {t('savedBlocks.title', 'Saved blocks')}
            </span>
          </nav>
        }
        title={t('savedBlocks.title', 'Saved blocks')}
        description={t(
          'savedBlocks.description',
          'Blocks saved from documents to insert again from the / menu. Editing a synced block updates every document that uses it; published templates pick up the change when they are published again.'
        )}
      />

      <div className="flex flex-wrap items-center gap-2">
        <FilterMenu
          testId="saved-blocks-filter-kind"
          label={t('savedBlocks.column.kind', 'Kind')}
          options={[
            { value: 'synced', label: t('savedBlocks.synced', 'Synced') },
            { value: 'copy', label: t('savedBlocks.copy', 'Copy') },
          ]}
          selected={kind}
          onChange={(next) => setKind(next as Kind[])}
        />
        <FilterMenu
          testId="saved-blocks-filter-sharing"
          label={t('savedBlocks.column.sharing', 'Who can use it')}
          options={[
            { value: 'workspace', label: t('savedBlocks.everyone', 'Everyone in the workspace') },
            { value: 'me', label: t('savedBlocks.onlyMe', 'Only me') },
          ]}
          selected={sharing}
          onChange={(next) => setSharing(next as Sharing[])}
        />
        <SearchField
          className="ms-auto w-full max-w-60"
          value={query}
          onValueChange={setQuery}
          label={t('savedBlocks.search', 'Search saved blocks')}
          placeholder={t('savedBlocks.search', 'Search saved blocks')}
          clearLabel={t('savedBlocks.searchClear', 'Clear search')}
        />
      </div>

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
          rowActions={(b) => (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" aria-label={t('savedBlocks.more', 'More for {name}', { name: b.name })}>
                  <Icon name="more" size={16} />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => setOpenId(b.id)}>{t('savedBlocks.whereUsed', 'Where it’s used')}</DropdownMenuItem>
                {canManage(b) && <DropdownMenuItem onClick={() => setRenaming(b)}>{t('savedBlocks.rename', 'Rename')}</DropdownMenuItem>}
                {canManage(b) && canManageLibrary && (
                  <DropdownMenuItem onClick={() => void changeSharing(b, b.sharing === 'workspace' ? 'me' : 'workspace')}>
                    {b.sharing === 'workspace'
                      ? t('savedBlocks.makeOnlyMe', 'Make it only mine')
                      : t('savedBlocks.makeEveryone', 'Let everyone in the workspace use it')}
                  </DropdownMenuItem>
                )}
                {canManage(b) && canEdit && (
                  <DropdownMenuItem asChild>
                    <Link href={`/admin/document-sources/saved-blocks/${b.id}`}>{t('savedBlocks.editOriginal', 'Edit original')}</Link>
                  </DropdownMenuItem>
                )}
                {canManage(b) && (
                  <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem className="text-destructive" onClick={() => setDeleting(b)}>
                      {t('savedBlocks.delete', 'Delete')}
                    </DropdownMenuItem>
                  </>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
          emptyState={{
            title: t('savedBlocks.emptyTitle', 'No saved blocks yet'),
            description: t('savedBlocks.empty', 'In a document, open a block’s menu and choose Save as block. It then appears here and in the / menu.'),
          }}
        />
      )}

      {open && (
        <SavedBlockDrawer
          block={open}
          canManage={canManage(open)}
          canEdit={canEdit}
          onRename={() => setRenaming(open)}
          onClose={() => setOpenId(null)}
        />
      )}
      {renaming && <RenameDialog block={renaming} onClose={() => setRenaming(null)} onChanged={() => void refetch()} />}
      {deleting && (
        <DeleteBlock
          block={deleting}
          canForceDelete={canManageLibrary}
          onClose={() => setDeleting(null)}
          onDeleted={() => {
            if (openId === deleting.id) setOpenId(null);
            void refetch();
          }}
        />
      )}
    </div>
  );
}

const USE_ICON: Record<string, IconName> = { template: 'template', wiki: 'page' };

function SavedBlockDrawer({
  block,
  canManage,
  canEdit,
  onRename,
  onClose,
}: {
  block: LibraryBlock;
  canManage: boolean;
  canEdit: boolean;
  onRename: () => void;
  onClose: () => void;
}) {
  const t = useTranslation('documents');
  const arabic = useFormattingLocale() === 'ar';
  const usage = useFetch(() => libraryBlockUsage(block.id), [block.id]);
  const kindWords: Record<string, string> = {
    template: t('savedBlocks.use.template', 'Template'),
    letter: t('savedBlocks.use.letter', 'Letter'),
    report: t('savedBlocks.use.report', 'Report'),
    note: t('savedBlocks.use.note', 'Note'),
    wiki: t('savedBlocks.use.wiki', 'Wiki page'),
    label: t('savedBlocks.use.label', 'Label'),
  };

  return (
    <Sheet open onOpenChange={(next) => !next && onClose()}>
      <SheetContent closeLabel={t('savedBlocks.close', 'Close')}>
        <SheetHeader>
          <p className="font-mono text-xs text-muted-foreground">
            {block.mode === 'synced' ? t('savedBlocks.syncedBlock', 'Synced block') : t('savedBlocks.copyBlock', 'Copy block')}
          </p>
          <SheetTitle dir="auto">{block.name}</SheetTitle>
          <SheetDescription>
            {block.mode === 'synced'
              ? block.usedIn === 1
                ? t('savedBlocks.usedInSentenceOne', 'Used in 1 document')
                : t('savedBlocks.usedInSentence', 'Used in {n} documents', { n: block.usedIn })
              : t('savedBlocks.copyHint', 'Copy: each insert is a copy people change freely.')}
          </SheetDescription>
        </SheetHeader>

        <div className="space-y-5">
          <SavedBlockPreview
            blocks={block.blocks as unknown as Block[]}
            parts={block.perDocumentParts as unknown as PerDocumentPart[]}
            arabic={arabic}
            partLabel={(label, required) =>
              required
                ? t('savedBlocks.partRequired', 'Filled in each document, required: {label}', { label })
                : t('savedBlocks.part', 'Filled in each document: {label}', { label })
            }
          />

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
                <ul className="divide-y divide-border">
                  {usage.data?.documents.map((d) => (
                    <li key={d.id} className="flex min-h-12 items-center gap-2 py-2">
                      <Icon name={USE_ICON[d.kind] ?? 'document'} size={16} className="shrink-0 text-muted-foreground" aria-hidden />
                      <Link href={`/admin/document-sources/${d.id}`} className="min-w-0 flex-1 truncate text-sm hover:underline" dir="auto">
                        {d.title || t('blockEditor.untitled', 'Untitled')}
                      </Link>
                      <Badge variant="outline">{kindWords[d.kind] ?? d.kind}</Badge>
                      {d.kind === 'template' && d.version < block.version && (
                        <span className="text-sm text-muted-foreground">{t('savedBlocks.nextPublish', 'Changes at next publish')}</span>
                      )}
                    </li>
                  ))}
                  {(usage.data?.hidden ?? 0) > 0 && (
                    <li className="flex min-h-12 items-center py-2 text-sm text-muted-foreground">
                      {t('savedBlocks.hidden', 'And {n} you can’t see', { n: usage.data?.hidden ?? 0 })}
                    </li>
                  )}
                </ul>
              )}
            </div>
          )}

          {canManage && (
            <div className="flex flex-wrap gap-2">
              {canEdit && (
                <Button icon="edit" asChild>
                  <Link href={`/admin/document-sources/saved-blocks/${block.id}`}>{t('savedBlocks.editOriginal', 'Edit original')}</Link>
                </Button>
              )}
              <Button variant="outline" onClick={onRename}>
                {t('savedBlocks.rename', 'Rename')}
              </Button>
            </div>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function RenameDialog({ block, onClose, onChanged }: { block: LibraryBlock; onClose: () => void; onChanged: () => void }) {
  const t = useTranslation('documents');
  const { addToast } = useToast();
  const [name, setName] = useState(block.name);
  const [saving, setSaving] = useState(false);
  const valid = name.trim() !== '' && name.trim() !== block.name;

  const save = async () => {
    setSaving(true);
    const result = await updateLibraryBlock(block.id, { name: name.trim() });
    setSaving(false);
    if (result.ok) {
      addToast(t('savedBlocks.saved', 'Saved'), 'success');
      onChanged();
      onClose();
      return;
    }
    addToast(
      result.reason === 'changed'
        ? t('savedBlocks.changedElsewhere', 'Someone changed this block meanwhile. Close it and open it again.')
        : t('savedBlocks.saveFailed', 'That didn’t save. Try again.'),
      'error'
    );
  };

  return (
    <Dialog open onOpenChange={(next) => !next && onClose()}>
      <DialogContent closeLabel={t('savedBlocks.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{t('savedBlocks.renameTitle', 'Rename saved block')}</DialogTitle>
        </DialogHeader>
        <form
          className="grid gap-4"
          onSubmit={(event) => {
            event.preventDefault();
            if (valid) void save();
          }}
        >
          <div className="space-y-1.5">
            <label htmlFor="saved-block-name" className="text-sm font-medium">
              {t('savedBlocks.name', 'Name')}
            </label>
            <Input id="saved-block-name" value={name} maxLength={120} onChange={(e) => setName(e.target.value)} />
          </div>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={onClose}>
              {t('savedBlocks.cancel', 'Cancel')}
            </Button>
            <Button type="submit" disabled={!valid || saving} loading={saving}>
              {t('savedBlocks.rename', 'Rename')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function DeleteBlock({
  block,
  canForceDelete,
  onClose,
  onDeleted,
}: {
  block: LibraryBlock;
  canForceDelete: boolean;
  onClose: () => void;
  onDeleted: () => void;
}) {
  const t = useTranslation('documents');
  const { addToast } = useToast();
  // A synced block still in use: its own dialog, after the first one closes.
  const [inUse, setInUse] = useState<number | null>(null);
  // Set while the first dialog hands over to the in-use one, so its closing doesn't end both.
  const escalating = useRef(false);

  const remove = async (force: boolean) => {
    const result = await deleteLibraryBlock(block.id, force);
    if (result.ok) {
      addToast(t('savedBlocks.deleted', 'Saved block deleted'), 'success');
      onClose();
      onDeleted();
      return;
    }
    if (result.usedIn !== null && !force) {
      escalating.current = true;
      setInUse(result.usedIn);
      return;
    }
    onClose();
    addToast(t('savedBlocks.deleteFailed', 'The block wasn’t deleted. Try again.'), 'error');
  };

  return inUse === null ? (
    <ConfirmAction
      open
      onOpenChange={(next) => {
        if (!next && !escalating.current) onClose();
      }}
      title={t('savedBlocks.deleteTitle', 'Delete {name}?', { name: block.name })}
      description={t('savedBlocks.deleteDescription', 'It leaves the / menu and this library. Documents and published templates that use it keep what they show.')}
      confirmLabel={t('savedBlocks.delete', 'Delete')}
      cancelLabel={t('savedBlocks.cancel', 'Cancel')}
      onConfirm={() => remove(false)}
    />
  ) : (
    <ConfirmAction
      open
      onOpenChange={(next) => !next && onClose()}
      title={t('savedBlocks.inUseTitle', '{name} is used in {n} documents', { name: block.name, n: inUse })}
      description={
        canForceDelete
          ? t('savedBlocks.inUseForce', 'Those documents will say the block is no longer available, and templates using it can’t be published until it’s removed or detached. This is hard to undo.')
          : t('savedBlocks.inUseNoForce', 'Only someone who can publish documents can delete a synced block that documents still use.')
      }
      confirmLabel={canForceDelete ? t('savedBlocks.deleteAnyway', 'Delete anyway') : t('savedBlocks.ok', 'OK')}
      cancelLabel={t('savedBlocks.cancel', 'Cancel')}
      onConfirm={() => (canForceDelete ? remove(true) : onClose())}
      typeToConfirm={canForceDelete ? { value: block.name, label: t('savedBlocks.typeName', 'Type {name} to confirm', { name: block.name }) } : undefined}
    />
  );
}
