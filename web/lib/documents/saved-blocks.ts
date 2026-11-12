import type { Block, SavedBlock } from '@whity/ui/editor/contract';
import type { DocumentEditorAdapter } from '@whity/features/document-editor';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';

/**
 * Saved blocks in the editor (editor M7; E.21-E.24): the web half of
 * `/api/v1/saved-blocks`. The list is this person's own blocks and the
 * workspace library's; deleted ones aren't in it.
 */

type WireSavedBlock = components['schemas']['SavedBlock'];

export function toSavedBlock(b: WireSavedBlock): SavedBlock {
  return {
    id: String(b.id),
    name: b.name,
    ...(b.description ? { description: b.description } : {}),
    mode: b.mode,
    sharing: b.sharing,
    // A removed person's block stays usable; it has no owner to name.
    owner: b.owner ? { kind: 'person', id: String(b.owner.id ?? ''), name: b.owner.name } : { kind: 'system', name: 'Whity' },
    blocks: b.blocks as unknown as Block[],
    perDocumentKeys: b.perDocumentKeys,
    version: b.version,
    usedIn: b.usedIn,
  };
}

export const savedBlocksAdapter: Pick<DocumentEditorAdapter, 'savedBlocks' | 'saveBlock'> = {
  async savedBlocks(): Promise<SavedBlock[]> {
    const { data, response } = await api.GET('/api/v1/saved-blocks');
    if (data === undefined) throw new Error(`Failed to load the saved blocks (${response.status})`);
    return data.data.map(toSavedBlock);
  },

  async saveBlock(block): Promise<SavedBlock> {
    const { data, response } = await api.POST('/api/v1/saved-blocks', {
      body: {
        name: block.name,
        mode: block.mode,
        sharing: block.sharing,
        blocks: block.blocks as unknown as WireSavedBlock['blocks'],
        per_document_keys: block.perDocumentKeys,
      },
    });
    if (data === undefined || data.data === null) throw new Error(`Failed to save the block (${response.status})`);
    return toSavedBlock(data.data);
  },
};
