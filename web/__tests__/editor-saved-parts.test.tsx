/**
 * Editor milestone M7, per-document parts (E.23): in a synced block's
 * original, runs of text or fields marked per document are parts keyed
 * `<block>#<n>`; a document fills each with its own text, which Detach keeps.
 */
import * as React from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';

import type { Block, SavedBlock, WhityDocument } from '@whity/ui/editor/contract';
import { DocEditor } from '@whity/ui/editor/doc-editor';
import { EDITOR_LABELS } from '@whity/ui/editor/labels';
import { SAMPLE_DOCUMENT } from '@whity/ui/editor/sample';
import { applyOverrides, perDocumentKeys, segments } from '@whity/ui/editor/saved-parts';

const t = (text: string) => ({ type: 'text' as const, text });
const pd = (text: string) => ({ type: 'text' as const, text, marks: [{ type: 'perDocument' as const }] });
const signer = { type: 'field' as const, path: 'signer.name', label: 'Signer', perDocument: true };

const SIGNATURE: Block[] = [
  { id: 'a', type: 'paragraph', content: [t('Signed by '), pd('Amro Saleh'), t(' on '), pd('12 November')] },
  { id: 't', type: 'toggle', open: true, summary: [t('Witness')], children: [{ id: 'b', type: 'paragraph', content: [signer, pd(', witness')] }] },
];
const SIGN: SavedBlock = {
  id: '7',
  name: 'Signature line',
  mode: 'synced',
  sharing: 'workspace',
  owner: { kind: 'person', id: '12', name: 'Amro Saleh' },
  blocks: SIGNATURE,
  perDocumentKeys: ['a#0', 'a#1', 'b#0'],
  version: 1,
  usedIn: 2,
};

beforeAll(() => {
  global.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
  const rects = () => Object.assign([], { item: () => null }) as unknown as DOMRectList;
  Element.prototype.getClientRects ??= rects;
  Range.prototype.getClientRects ??= rects;
  Range.prototype.getBoundingClientRect ??= () => ({ x: 0, y: 0, top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0, toJSON: () => ({}) }) as DOMRect;
});

it('splits a line into parts keyed by block and position; consecutive marked runs are one part', () => {
  const segs = segments('a', SIGNATURE[0].type === 'paragraph' ? SIGNATURE[0].content : []);
  expect(segs.map((s) => s.part)).toEqual([null, 'a#0', null, 'a#1']);
  // A field set per document and marked text next to it are one part.
  expect(segments('b', [signer, pd(', witness'), t('.')]).map((s) => [s.part, s.runs.length])).toEqual([['b#0', 2], [null, 1]]);
  expect(perDocumentKeys(SIGNATURE)).toEqual(['a#0', 'a#1', 'b#0']);
});

it('fills parts with the document\'s own text and drops the mark; unfilled parts keep the original', () => {
  const out = applyOverrides(SIGNATURE, { 'a#0': [t('Awss Haddad')] });
  expect(out[0]).toEqual({ id: 'a', type: 'paragraph', content: [t('Signed by '), t('Awss Haddad'), t(' on '), t('12 November')] });
  const toggle = out[1] as Extract<Block, { type: 'toggle' }>;
  expect(toggle.children[0]).toEqual({ id: 'b', type: 'paragraph', content: [{ type: 'field', path: 'signer.name', label: 'Signer' }, t(', witness')] });
});

it('in a document, a part is a field this document fills, and Detach keeps what it filled', async () => {
  const onChange = jest.fn();
  const doc: WhityDocument = { ...SAMPLE_DOCUMENT, blocks: [{ id: 'r1', type: 'savedBlock', savedBlockId: '7', version: 1, overrides: {} }] };
  render(<DocEditor document={doc} onChange={onChange} canEdit services={{ savedBlocks: [SIGN] }} />);
  await act(async () => {});

  const name = screen.getByRole('textbox', { name: 'Filled per document: Amro Saleh' });
  expect(name).toHaveAttribute('placeholder', 'Amro Saleh');
  fireEvent.change(name, { target: { value: 'Awss Haddad' } });
  fireEvent.blur(name);
  await act(async () => {});
  let saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
  expect(saved.blocks[0]).toMatchObject({ type: 'savedBlock', overrides: { 'a#0': [t('Awss Haddad')] } });

  // Emptied, the part goes back to the original's text.
  fireEvent.change(screen.getByRole('textbox', { name: 'Filled per document: 12 November' }), { target: { value: '13 November' } });
  fireEvent.blur(screen.getByRole('textbox', { name: 'Filled per document: 12 November' }));
  await act(async () => {});

  fireEvent.click(screen.getByRole('button', { name: EDITOR_LABELS.savedBlockDetach }));
  await act(async () => {});
  saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
  expect(saved.blocks[0]).toMatchObject({ type: 'paragraph', content: [t('Signed by Awss Haddad on 13 November')] });
});

it('reads a part as text when the document can only be read', async () => {
  const doc: WhityDocument = { ...SAMPLE_DOCUMENT, blocks: [{ id: 'r1', type: 'savedBlock', savedBlockId: '7', version: 1, overrides: { 'a#0': [t('Awss Haddad')] } }] };
  render(<DocEditor document={doc} onChange={jest.fn()} canEdit={false} mode="viewing" services={{ savedBlocks: [SIGN] }} />);
  await act(async () => {});
  expect(screen.queryByRole('textbox', { name: /Filled per document/ })).toBeNull();
  expect(screen.getByText('Awss Haddad')).toBeInTheDocument();
  expect(screen.getByText('12 November')).toBeInTheDocument();
});
