import type { Block, PerDocumentPart, SavedBlock } from '@whity/ui/editor/contract';
import type { DocumentEditorAdapter } from '@whity/features/document-editor';
import { api } from '@/lib/api/client';
import type { components } from '@whity/api-client';

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
    perDocumentParts: b.perDocumentParts as unknown as PerDocumentPart[],
    version: b.version,
    usedIn: b.usedIn,
  };
}

/* ── The library page (E.22): list, change, delete, where it's used ───────── */

export type LibraryBlock = WireSavedBlock;
export type SavedBlockUse = components['schemas']['SavedBlockUsage'];

export async function listLibrary(): Promise<LibraryBlock[]> {
  const { data, response } = await api.GET('/api/v1/saved-blocks');
  if (data === undefined) throw new Error(`Failed to load the saved blocks (${response.status})`);
  return data.data;
}

export async function getLibraryBlock(id: number): Promise<LibraryBlock> {
  const { data, response } = await api.GET('/api/v1/saved-blocks/{id}', { params: { path: { id } } });
  if (data === undefined || data.data === null) throw new Error(`Failed to load the saved block (${response.status})`);
  return data.data;
}

/** What a change can be refused for: someone else saved first, or the block was deleted. */
export type LibraryChangeResult = { ok: true; block: LibraryBlock } | { ok: false; reason: 'changed' | 'deleted' | 'failed' };

export async function updateLibraryBlock(
  id: number,
  patch: { name?: string; sharing?: 'me' | 'workspace'; blocks?: Block[]; perDocumentParts?: PerDocumentPart[]; version?: number }
): Promise<LibraryChangeResult> {
  const { data, response, error } = await api.PATCH('/api/v1/saved-blocks/{id}', {
    params: { path: { id } },
    body: {
      ...(patch.name !== undefined ? { name: patch.name } : {}),
      ...(patch.sharing !== undefined ? { sharing: patch.sharing } : {}),
      ...(patch.blocks !== undefined ? { blocks: patch.blocks as unknown as WireSavedBlock['blocks'] } : {}),
      ...(patch.perDocumentParts !== undefined ? { per_document_parts: patch.perDocumentParts as unknown as WireSavedBlock['perDocumentParts'] } : {}),
      ...(patch.version !== undefined ? { version: patch.version } : {}),
    },
  });
  if (data !== undefined && data.data !== null) return { ok: true, block: data.data };
  if (response.status === 409) {
    const code = (error as { details?: { code?: string } } | undefined)?.details?.code;
    return { ok: false, reason: code === 'archived' ? 'deleted' : 'changed' };
  }
  return { ok: false, reason: 'failed' };
}

/** Deleting: a synced block still in use answers with how many documents use it, unless forced. */
export async function deleteLibraryBlock(id: number, force = false): Promise<{ ok: true } | { ok: false; usedIn: number } | { ok: false; usedIn: null }> {
  const { data, response, error } = await api.DELETE('/api/v1/saved-blocks/{id}', {
    params: { path: { id }, query: force ? { force: '1' } : {} },
  });
  if (data !== undefined) return { ok: true };
  if (response.status === 409) {
    const usedIn = (error as { details?: { used_in?: number } } | undefined)?.details?.used_in;
    return { ok: false, usedIn: typeof usedIn === 'number' ? usedIn : 0 };
  }
  return { ok: false, usedIn: null };
}

/** The documents using a block that the viewer can see, and how many they can't. */
export async function libraryBlockUsage(id: number): Promise<{ documents: SavedBlockUse[]; hidden: number }> {
  const { data, response } = await api.GET('/api/v1/saved-blocks/{id}/usage', { params: { path: { id } } });
  if (data === undefined) throw new Error(`Failed to load where the block is used (${response.status})`);
  return { documents: data.data, hidden: data.meta.hidden };
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
        per_document_parts: block.perDocumentParts as unknown as WireSavedBlock['perDocumentParts'],
      },
    });
    if (data === undefined || data.data === null) throw new Error(`Failed to save the block (${response.status})`);
    return toSavedBlock(data.data);
  },
};
