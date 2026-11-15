'use client';

import { use, useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslation } from '@whity/features/i18n';
import { useEditorLabels } from '@whity/features/document-editor';
import { Button } from '@whity/ui/button';
import type { Block, WhityDocument } from '@whity/ui/editor/contract';
import { DocEditor } from '@whity/ui/editor/doc-editor';
import { assignPartKeys, perDocumentParts } from '@whity/ui/editor/saved-parts';
import type { PerDocumentPart } from '@whity/ui/editor/contract';
import { AreaError } from '@whity/ui/page-states';
import { useCapabilities } from '@/hooks/useCapabilities';
import { DOCUMENTS_WRITE } from '@/lib/capabilities';
import { useToast } from '@/lib/toast-context';
import { getLibraryBlock, updateLibraryBlock, type LibraryBlock } from '@/lib/documents/saved-blocks';

/**
 * Edit original (EditorCustomBlocks, E.21, E.38): a saved block's blocks in
 * the editor. Saving updates every draft, note and wiki page that uses a
 * synced block at once, and published templates when they are published
 * again, so it is an explicit Save changes rather than autosave. Someone
 * saving in between is caught by the version, and nothing is overwritten.
 */
export default function EditSavedBlockPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const blockId = Number(id);
  const router = useRouter();
  const t = useTranslation('documents');
  const labels = useEditorLabels();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const [block, setBlock] = useState<LibraryBlock | null>(null);
  const [failed, setFailed] = useState(false);
  const [doc, setDoc] = useState<WhityDocument | null>(null);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [loadCount, setLoadCount] = useState(0);

  const open = useCallback((b: LibraryBlock) => {
    setBlock(b);
    setDirty(false);
    setDoc({
      id: `saved-block-${b.id}`,
      kind: 'note',
      title: b.name,
      lang: 'en',
      defaultView: 'continuous',
      blocks: b.blocks as unknown as Block[],
      comments: [],
      suggestions: [],
      status: 'draft',
      updatedAt: b.updatedAt,
      updatedBy: b.owner ? { kind: 'person', id: String(b.owner.id ?? ''), name: b.owner.name } : { kind: 'system', name: 'Whity' },
    });
    setLoadCount((n) => n + 1);
  }, []);

  useEffect(() => {
    let live = true;
    getLibraryBlock(blockId).then(
      (b) => live && open(b),
      () => live && setFailed(true)
    );
    return () => {
      live = false;
    };
  }, [blockId, open]);

  // Leaving with unsaved changes asks first (section 21).
  useEffect(() => {
    if (!dirty) return;
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  const back = () => {
    if (dirty && !window.confirm(t('savedBlocks.leaveUnsaved', 'Leave without saving your changes?'))) return;
    router.push('/admin/document-sources/saved-blocks');
  };

  const save = async () => {
    if (!block || !doc) return;
    setSaving(true);
    // E.23a: the parts each document fills, by their stable keys, keeping their labels.
    const blocks = assignPartKeys(doc.blocks);
    const parts = perDocumentParts(blocks, block.perDocumentParts as unknown as PerDocumentPart[]);
    const result = await updateLibraryBlock(block.id, { blocks, perDocumentParts: parts, version: block.version });
    setSaving(false);
    if (result.ok) {
      setBlock(result.block);
      setDirty(false);
      addToast(
        block.mode === 'synced'
          ? t('savedBlocks.savedSynced', 'Saved. Documents using it show the change now; published templates when they are published again.')
          : t('savedBlocks.saved', 'Saved'),
        'success'
      );
      return;
    }
    addToast(
      result.reason === 'changed'
        ? t('savedBlocks.changedElsewhere', 'Someone changed this block meanwhile. Close it and open it again.')
        : result.reason === 'deleted'
          ? t('savedBlocks.deletedMeanwhile', 'This block was deleted meanwhile.')
          : t('savedBlocks.saveFailed', 'That didn’t save. Try again.'),
      'error'
    );
  };

  if (failed) {
    return (
      <div className="grid h-full place-items-center p-6">
        <AreaError message={t('savedBlocks.openFailed', 'This saved block didn’t open')} retryLabel={t('savedBlocks.backToLibrary', 'Back to saved blocks')} onRetry={back} />
      </div>
    );
  }

  return (
    <div className="flex h-full flex-col">
      <div className="min-h-0 flex-1">
        {doc && (
          <DocEditor
            key={loadCount}
            document={doc}
            onChange={(next) => {
              setDoc({ ...next, title: doc.title });
              setDirty(true);
            }}
            canEdit={hasPermission(DOCUMENTS_WRITE)}
            back={{ label: t('savedBlocks.backToLibrary', 'Back to saved blocks'), onClick: back }}
            path={t('savedBlocks.editingOriginal', 'Saved blocks · Original')}
            labels={labels}
            perDocumentParts={block?.mode === 'synced'}
          />
        )}
      </div>
      {dirty && (
        <div role="region" aria-label={t('savedBlocks.saveBar', 'Unsaved changes')} className="flex items-center gap-3 border-t border-border bg-card px-4 py-3">
          <p className="min-w-0 flex-1 text-sm text-muted-foreground">
            {block?.mode === 'synced' && (block.usedIn ?? 0) > 0
              ? t('savedBlocks.saveBarSynced', 'Saving updates the {n} documents that use it.', { n: block.usedIn })
              : t('savedBlocks.saveBarPlain', 'You changed the original.')}
          </p>
          <Button variant="outline" onClick={() => block && open(block)} disabled={saving}>
            {t('savedBlocks.discard', 'Discard')}
          </Button>
          <Button onClick={() => void save()} loading={saving}>
            {t('savedBlocks.saveChanges', 'Save changes')}
          </Button>
        </div>
      )}
    </div>
  );
}
