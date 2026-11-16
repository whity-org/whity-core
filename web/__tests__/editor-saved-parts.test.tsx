/**
 * Editor M7, per-document parts (E.23, E.23a): in a synced block's original,
 * text or fields marked per document are parts with stable keys; a document
 * fills each by key with its own text, which Detach keeps. Rows from before
 * 3.4 have no keys: their parts are named `<block>#<n>`, and a value stored
 * under that name still fills the part.
 */
import * as React from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';

import type { Block, SavedBlock, WhityDocument } from '@whity/ui/editor/contract';
import { DocEditor } from '@whity/ui/editor/doc-editor';
import { EDITOR_LABELS } from '@whity/ui/editor/labels';
import { SAMPLE_DOCUMENT } from '@whity/ui/editor/sample';
import { applyOverrides, assignPartKeys, mintPartKey, perDocumentKeys, perDocumentParts, segments } from '@whity/ui/editor/saved-parts';

const t = (text: string) => ({ type: 'text' as const, text });
const pd = (text: string, key = 'pd_signer01') => ({ type: 'text' as const, text, marks: [{ type: 'perDocument' as const, key }] });
const signer = { type: 'field' as const, path: 'signer.name', label: 'Signer', perDocument: 'pd_witness1' };
// A pre-3.4 row: the mark has no key.
const old = (text: string) => ({ type: 'text', text, marks: [{ type: 'perDocument' }] });

const SIGNATURE: Block[] = [
  { id: 'a', type: 'paragraph', content: [t('Signed by '), pd('Amro Saleh'), t(' on '), pd('12 November', 'pd_signdate')] },
  { id: 't', type: 'toggle', open: true, summary: [t('Witness')], children: [{ id: 'b', type: 'paragraph', content: [signer, pd(', witness', 'pd_witness1')] }] },
];
const SIGN: SavedBlock = {
  id: '7',
  name: 'Signature line',
  mode: 'synced',
  sharing: 'workspace',
  owner: { kind: 'person', id: '12', name: 'Amro Saleh' },
  blocks: SIGNATURE,
  perDocumentParts: [
    { key: 'pd_signer01', label: { en: 'Signer name', ar: 'اسم الموقّع' }, kind: 'text' },
    { key: 'pd_signdate', label: { en: 'Date' }, kind: 'text' },
    { key: 'pd_witness1', label: { en: 'Witness' }, kind: 'text' },
  ],
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

it('splits a line into parts by key; adjacent runs with the same key are one part', () => {
  const segs = segments('a', SIGNATURE[0].type === 'paragraph' ? SIGNATURE[0].content : []);
  expect(segs.map((s) => s.part)).toEqual([null, 'pd_signer01', null, 'pd_signdate']);
  // A field and marked text with the same key are one part; different keys side by side are two.
  expect(segments('b', [signer, pd(', witness', 'pd_witness1'), t('.')]).map((s) => [s.part, s.runs.length])).toEqual([['pd_witness1', 2], [null, 1]]);
  expect(segments('c', [pd('x', 'pd_one00000'), pd('y', 'pd_two00000')]).map((s) => s.part)).toEqual(['pd_one00000', 'pd_two00000']);
  expect(perDocumentKeys(SIGNATURE)).toEqual(['pd_signer01', 'pd_signdate', 'pd_witness1']);
});

it('keys stay with their parts when the original is reordered', () => {
  const reordered: Block[] = [{ id: 'a', type: 'paragraph', content: [pd('12 November', 'pd_signdate'), t(', signed by '), pd('Amro Saleh')] }];
  const out = applyOverrides(reordered, { pd_signer01: [t('Awss Haddad')] });
  expect(out[0]).toEqual({ id: 'a', type: 'paragraph', content: [t('12 November'), t(', signed by '), t('Awss Haddad')] });
});

it('a pre-3.4 part gets a key once, and a value under its old name still fills it', () => {
  const legacy = [{ id: 'a', type: 'paragraph', content: [t('Signed by '), old('Amro Saleh'), t(' on '), old('12 November')] }] as unknown as Block[];
  const content = (legacy[0] as Extract<Block, { type: 'paragraph' }>).content;
  expect(segments('a', content).map((s) => s.part)).toEqual([null, 'a#0', null, 'a#1']);
  expect(applyOverrides(legacy, { 'a#1': [t('13 November')] })[0]).toMatchObject({ content: [t('Signed by '), t('Amro Saleh'), t(' on '), t('13 November')] });

  const map = new Map<string, string>();
  const keyed = assignPartKeys(legacy, map);
  const [k0, k1] = perDocumentKeys(keyed);
  expect(k0).toMatch(/^pd_[A-Za-z0-9_-]{8}$/);
  expect([...map]).toEqual([['a#0', k0], ['a#1', k1]]);
  // The old name still finds the part after it has a key.
  expect(applyOverrides(keyed, { 'a#0': [t('Awss Haddad')] })[0]).toMatchObject({ content: [t('Signed by '), t('Awss Haddad'), t(' on '), t('12 November')] });
  // Keying again changes nothing.
  expect(assignPartKeys(keyed)).toEqual(keyed);
  expect(new Set(Array.from({ length: 50 }, mintPartKey)).size).toBe(50);
});

it('lists the parts with labels, kept by key; a new part is labelled with its text', () => {
  expect(perDocumentParts(SIGNATURE, SIGN.perDocumentParts.slice(0, 1))).toEqual([
    { key: 'pd_signer01', label: { en: 'Signer name', ar: 'اسم الموقّع' }, kind: 'text' },
    { key: 'pd_signdate', label: { en: '12 November' }, kind: 'text' },
    { key: 'pd_witness1', label: { en: 'Signer, witness' }, kind: 'text' },
  ]);
});

it('fills parts with the document\'s own text and drops the mark; unfilled parts keep the original', () => {
  const out = applyOverrides(SIGNATURE, { pd_signer01: [t('Awss Haddad')] });
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
  expect(saved.blocks[0]).toMatchObject({ type: 'savedBlock', overrides: { pd_signer01: [t('Awss Haddad')] } });

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
  const doc: WhityDocument = { ...SAMPLE_DOCUMENT, blocks: [{ id: 'r1', type: 'savedBlock', savedBlockId: '7', version: 1, overrides: { pd_signer01: [t('Awss Haddad')] } }] };
  render(<DocEditor document={doc} onChange={jest.fn()} canEdit={false} mode="viewing" services={{ savedBlocks: [SIGN] }} />);
  await act(async () => {});
  expect(screen.queryByRole('textbox', { name: /Filled per document/ })).toBeNull();
  expect(screen.getByText('Awss Haddad')).toBeInTheDocument();
  expect(screen.getByText('12 November')).toBeInTheDocument();
});
