/**
 * Editor milestone M7, saved blocks in the editor (EditorCustomBlocks;
 * E.21, E.22): a synced block is one savedBlock drawn from the library, with
 * its label and Detach; the / menu's Saved blocks group inserts a synced one
 * as a reference and a copy as ordinary blocks; Save as block saves the
 * blocks and, when synced, puts the reference in their place.
 */
import * as React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { Editor } from '@tiptap/core';

import { applyItem, filterCatalogue, savedBlockItems } from '@whity/ui/editor/catalogue';
import type { Block, SavedBlock, WhityDocument } from '@whity/ui/editor/contract';
import { fromEditorContent, normalizeBlocks, toEditorContent } from '@whity/ui/editor/convert';
import { DocEditor } from '@whity/ui/editor/doc-editor';
import { EDITOR_LABELS } from '@whity/ui/editor/labels';
import { SAMPLE_DOCUMENT } from '@whity/ui/editor/sample';

const t = (text: string) => ({ type: 'text' as const, text });
const AMRO = { kind: 'person' as const, id: '12', name: 'Amro Saleh' };

const BANK: SavedBlock = {
  id: '5',
  name: 'Bank details',
  mode: 'synced',
  sharing: 'workspace',
  owner: AMRO,
  blocks: [
    { id: 's1', type: 'heading', level: 2, content: [t('Bank details')] },
    { id: 's2', type: 'paragraph', content: [t('Example Bakery, IBAN JO00 0000')] },
  ],
  perDocumentKeys: [],
  version: 3,
  usedIn: 12,
};
const TERMS: SavedBlock = { ...BANK, id: '6', name: 'Payment terms', mode: 'copy', blocks: [{ id: 'c1', type: 'paragraph', content: [t('Pay within 30 days.')] }], usedIn: 0 };

const REF: Block = { id: 'r1', type: 'savedBlock', savedBlockId: '5', version: 3, overrides: {} };

function doc(blocks: Block[]): WhityDocument {
  return { ...SAMPLE_DOCUMENT, blocks };
}

function editorOf(container: HTMLElement): Editor {
  const dom = container.querySelector('.ProseMirror') as HTMLElement & { editor?: Editor };
  if (!dom?.editor) throw new Error('no editor');
  return dom.editor;
}

beforeAll(() => {
  global.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
  // Focusing the editor scrolls the caret into view; jsdom has no layout.
  const rects = () => Object.assign([], { item: () => null }) as unknown as DOMRectList;
  const box = () => ({ x: 0, y: 0, top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0, toJSON: () => ({}) }) as DOMRect;
  Element.prototype.getClientRects ??= rects;
  Range.prototype.getClientRects ??= rects;
  Range.prototype.getBoundingClientRect ??= box;
  document.elementFromPoint ??= () => null;
  // jsdom has no PointerEvent; the gutter reads clientY from one.
  (window as unknown as { PointerEvent?: typeof MouseEvent }).PointerEvent ??= class extends MouseEvent {} as typeof MouseEvent;
});

it('round-trips a savedBlock through the editor unchanged', () => {
  const blocks: Block[] = [REF, { id: 'p1', type: 'paragraph', content: [t('After')] }];
  expect(fromEditorContent(toEditorContent(blocks))).toEqual(normalizeBlocks(blocks));
});

it('offers saved blocks in the / menu: synced as a reference, a copy as its blocks with no ids', () => {
  const items = savedBlockItems([BANK, TERMS]);
  expect(items.map((i) => [i.group, i.title, EDITOR_LABELS[i.hint]])).toEqual([
    ['groupSaved', 'Bank details', EDITOR_LABELS.savedBlockSyncedHint],
    ['groupSaved', 'Payment terms', EDITOR_LABELS.savedBlockCopyHint],
  ]);
  expect(items[0].node).toEqual({ type: 'savedBlock', attrs: { savedBlockId: '5', version: 3, overrides: {} } });
  expect(items[0].nodes).toBeUndefined();
  expect(items[1].nodes?.map((n) => n.attrs?.id)).toEqual([null]);

  // Found by their own name, after Whity's blocks.
  const found = filterCatalogue('bank', EDITOR_LABELS, EDITOR_LABELS, items);
  expect(found.map((i) => i.id)).toEqual(['saved:5']);
  expect(filterCatalogue('', EDITOR_LABELS, EDITOR_LABELS, items).slice(-2).map((i) => i.id)).toEqual(['saved:5', 'saved:6']);
});

it('draws a synced block from the library, with its label, and Detach turns it into ordinary blocks', async () => {
  const onChange = jest.fn();
  const { container } = render(<DocEditor document={doc([REF])} onChange={onChange} canEdit services={{ savedBlocks: [BANK] }} />);
  await act(async () => {});

  const group = screen.getByRole('group', { name: 'Saved block: Bank details' });
  expect(group).toHaveTextContent('Example Bakery, IBAN JO00 0000');
  expect(group).toHaveTextContent('Synced · used in 12 documents');

  fireEvent.click(screen.getByRole('button', { name: EDITOR_LABELS.savedBlockDetach }));
  await act(async () => {});
  const saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
  expect(saved.blocks.map((b) => b.type)).toEqual(['heading', 'paragraph']);
  expect(saved.blocks[1]).toMatchObject({ type: 'paragraph', content: [t('Example Bakery, IBAN JO00 0000')] });
  // Fresh ids, not the library's.
  expect(saved.blocks.map((b) => b.id)).not.toContain('s1');
  expect(container.querySelector('[data-type="savedBlock"]')).toBeNull();
});

it('says so when the saved block was deleted or isn\'t shared, and offers no Detach', async () => {
  render(<DocEditor document={doc([REF])} onChange={jest.fn()} canEdit services={{ savedBlocks: [] }} />);
  await act(async () => {});
  expect(screen.getByText(EDITOR_LABELS.savedBlockMissing)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: EDITOR_LABELS.savedBlockDetach })).toBeNull();
});

it('reads a synced block without Detach when the document can only be read', async () => {
  render(<DocEditor document={doc([REF])} onChange={jest.fn()} canEdit={false} mode="viewing" services={{ savedBlocks: [BANK] }} />);
  await act(async () => {});
  expect(screen.getByText('Example Bakery, IBAN JO00 0000')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: EDITOR_LABELS.savedBlockDetach })).toBeNull();
});

it('inserts a copy as ordinary blocks and a synced block as a reference', async () => {
  const onChange = jest.fn();
  const { container } = render(<DocEditor document={doc([{ id: 'p1', type: 'paragraph', content: [] }])} onChange={onChange} canEdit services={{ savedBlocks: [BANK, TERMS] }} />);
  await act(async () => {});
  const editor = editorOf(container);
  const [synced, copy] = savedBlockItems([BANK, TERMS]);

  act(() => {
    editor.commands.setTextSelection(1);
    applyItem(editor, copy);
  });
  let saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
  expect(saved.blocks.map((b) => b.type)).toEqual(['paragraph', 'paragraph']);
  expect(saved.blocks[0]).toMatchObject({ content: [t('Pay within 30 days.')] });
  expect(saved.blocks[0].id).not.toBe('c1');

  act(() => {
    editor.commands.setTextSelection(editor.state.doc.content.size - 1);
    applyItem(editor, synced);
  });
  saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
  expect(saved.blocks.filter((b) => b.type === 'savedBlock')).toEqual([expect.objectContaining({ savedBlockId: '5', version: 3, overrides: {} })]);
});

it('saves the selected blocks as a synced block and puts the reference in their place', async () => {
  const onChange = jest.fn();
  const saveBlock = jest.fn().mockResolvedValue({ ...BANK, id: '9', version: 1 });
  const blocks: Block[] = [
    { id: 'h1', type: 'heading', level: 2, content: [t('Bank details')] },
    { id: 'p1', type: 'paragraph', content: [t('Example Bakery, IBAN JO00 0000')] },
    { id: 'p2', type: 'paragraph', content: [t('Thank you.')] },
  ];
  const { container } = render(<DocEditor document={doc(blocks)} onChange={onChange} canEdit services={{ savedBlocks: [], saveBlock }} />);
  await act(async () => {});
  const editor = editorOf(container);

  // Select across the first two blocks, then open the first block's menu.
  act(() => {
    editor.commands.setTextSelection({ from: 2, to: editor.state.doc.child(0).nodeSize + 3 });
    editor.commands.focus();
  });
  // Hovering the first block shows its gutter (jsdom has no layout: every block is at 0).
  fireEvent.pointerMove(container.querySelector('.ProseMirror')!, { clientY: 0 });
  const handle = await screen.findByRole('button', { name: EDITOR_LABELS.dragHandle });
  fireEvent.keyDown(handle, { key: 'Enter' });
  fireEvent.click(await screen.findByRole('menuitem', { name: new RegExp(EDITOR_LABELS.saveAsBlock) }));

  expect(await screen.findByText('Saves the 2 selected blocks as one block you can insert from the / menu.')).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(EDITOR_LABELS.saveBlockName), { target: { value: 'Bank details' } });
  fireEvent.click(screen.getByRole('button', { name: EDITOR_LABELS.saveBlockSave }));

  await waitFor(() => expect(saveBlock).toHaveBeenCalled());
  expect(saveBlock).toHaveBeenCalledWith({
    name: 'Bank details',
    mode: 'synced',
    sharing: 'me',
    blocks: blocks.slice(0, 2),
    perDocumentKeys: [],
  });
  await waitFor(() => {
    const saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
    expect(saved.blocks.map((b) => b.type)).toEqual(['savedBlock', 'paragraph']);
  });
  const saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
  expect(saved.blocks[0]).toMatchObject({ type: 'savedBlock', savedBlockId: '9', version: 1 });
  expect(saved.blocks[1]).toMatchObject({ id: 'p2' });
});
