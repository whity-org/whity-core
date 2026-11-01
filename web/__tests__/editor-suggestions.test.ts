/**
 * Editor milestone M5: Suggesting mode and its round trip to patches.
 *
 * Each case edits a real editor in Suggesting mode, then checks three things:
 * the marks the edit became (deleted text stays, struck; typed text is marked
 * inserted), the patches the flush would POST (`collectPatches`), and that the
 * server's rendering of those patches onto the draft (`renderSuggestions`)
 * gives back the same marked document.
 */
import { Editor } from '@tiptap/core';

import type { Block, Inline } from '@whity-org/ui/editor/contract';
import { fromEditorContent, toEditorContent } from '@whity-org/ui/editor/convert';
import { documentExtensions } from '@whity-org/ui/editor/extensions';
import { LOCAL_PREFIX, pendingFormats, renameSuggestion, suggestingKey } from '@whity-org/ui/editor/suggesting';
import {
  collectPatches,
  countMatches,
  renderSuggestions,
  replaceSuggestionId,
  stripSuggestions,
  suggestEdit,
  summarize,
  type LocalChange,
  type SuggestionPatch,
} from '@whity-org/ui/editor/suggestions';

const isLocal = (id: string) => id.startsWith(LOCAL_PREFIX);
const p = (id: string, ...content: Inline[]): Block => ({ id, type: 'paragraph', content });
function t(text: string, marks?: { type: string; [k: string]: unknown }[]): Inline {
  return marks ? ({ type: 'text', text, marks } as Inline) : { type: 'text', text };
}

function make(blocks: Block[]) {
  const element = document.createElement('div');
  document.body.append(element);
  const editor = new Editor({ element, extensions: documentExtensions(), content: toEditorContent(blocks) });
  editor.view.dispatch(editor.state.tr.setMeta(suggestingKey, { enabled: true }));
  return editor;
}

/** The position just inside block `index` at character `offset`. */
function at(editor: Editor, index: number, offset: number) {
  let pos = 0;
  for (let i = 0; i < index; i++) pos += editor.state.doc.child(i).nodeSize;
  return pos + 1 + offset;
}

function typeAt(editor: Editor, pos: number, chars: string) {
  editor.commands.setTextSelection(pos);
  for (const ch of chars) {
    const { from, to } = editor.state.selection;
    editor.view.dispatch(editor.state.tr.insertText(ch, from, to));
  }
}

function backspace(editor: Editor, times = 1) {
  for (let i = 0; i < times; i++) {
    const { from } = editor.state.selection;
    editor.view.dispatch(editor.state.tr.delete(from - 1, from));
  }
}

const blocks = (editor: Editor) => fromEditorContent(editor.getJSON());
const changes = (editor: Editor) => collectPatches(blocks(editor), pendingFormats(editor.state), isLocal);
const patches = (editor: Editor) => changes(editor).map((c) => c.patch);

/** Local ids → s1, s2… in flush order, as the server would answer. */
function asServer(marked: Block[], list: LocalChange[]): Block[] {
  let out = marked;
  list.forEach((c, i) => c.localIds.forEach((id) => (out = replaceSuggestionId(out, id, `s${i + 1}`))));
  return out;
}

/** The round trip: the draft plus the flushed patches renders back to the marked document. */
function expectRoundTrip(editor: Editor) {
  const marked = blocks(editor);
  const list = changes(editor);
  const draft = stripSuggestions(marked);
  const rendered = renderSuggestions(
    draft,
    list.filter((c) => c.kind !== 'format').map((c, i) => ({ id: `s${i + 1}`, patch: c.patch }))
  );
  expect(rendered.unplaced).toEqual([]);
  expect(rendered.blocks).toEqual(asServer(marked, list.filter((c) => c.kind !== 'format')));
}

const ins = (id: string) => ({ type: 'insertion', suggestionId: id });
const del = (id: string) => ({ type: 'deletion', suggestionId: id });
/** Replace every local id with "L" so expectations don't depend on the generated ids. */
const normal = (b: Block[]) => JSON.parse(JSON.stringify(b).replace(/local-[a-z0-9]+/g, 'L')) as Block[];

describe('Suggesting mode: edits become marks and patches', () => {
  it('typing inside a word marks the typed text inserted', () => {
    const editor = make([p('b1', t('Please pay within 30 days.'))]);
    typeAt(editor, at(editor, 0, 23), 'xx'); // "30 da|ys"
    expect(normal(blocks(editor))).toEqual([p('b1', t('Please pay within 30 da'), t('xx', [ins('L')]), t('ys.'))]);
    expect(patches(editor)).toEqual([
      { op: 'replace-text', blockId: 'b1', deleteText: '', insert: [t('xx')], before: 'Please pay within 30 da', occurrence: 0 },
    ]);
    expect(editor.state.selection.from).toBe(at(editor, 0, 25));
    expectRoundTrip(editor);
  });

  it('deleting a range across marks keeps the text, struck, with its formatting', () => {
    const editor = make([p('b1', t('Pay '), t('within', [{ type: 'bold' }]), t(' 30 days'))]);
    editor.view.dispatch(editor.state.tr.delete(at(editor, 0, 2), at(editor, 0, 13)));
    expect(normal(blocks(editor))).toEqual([
      p('b1', t('Pa'), t('y ', [del('L')]), t('within', [{ type: 'bold' }, del('L')]), t(' 30', [del('L')]), t(' days')),
    ]);
    expect(patches(editor)).toEqual([{ op: 'replace-text', blockId: 'b1', deleteText: 'y within 30', insert: [], occurrence: 0 }]);
    expectRoundTrip(editor);
  });

  it('replacing a selection strikes the old text and inserts the new after it', () => {
    const editor = make([p('b1', t('Please pay within 30 days of this letter.'))]);
    editor.commands.setTextSelection({ from: at(editor, 0, 18), to: at(editor, 0, 25) });
    editor.view.dispatch(editor.state.tr.insertText('1'));
    typeAt(editor, editor.state.selection.from, '4 days');
    expect(normal(blocks(editor))).toEqual([
      p('b1', t('Please pay within '), t('30 days', [del('L')]), t('14 days', [ins('L')]), t(' of this letter.')),
    ]);
    const list = changes(editor);
    expect(list.map((c) => c.patch)).toEqual([
      { op: 'replace-text', blockId: 'b1', deleteText: '30 days', insert: [t('14 days')], occurrence: 0 },
    ]);
    expect(list[0].summary).toBe('Replace “30 days” with “14 days”');
    expectRoundTrip(editor);
  });

  it('Backspace over your own pending insertion just removes it, then strikes the draft', () => {
    const editor = make([p('b1', t('Pay now'))]);
    typeAt(editor, at(editor, 0, 3), ' today');
    backspace(editor, 6);
    expect(blocks(editor)).toEqual([p('b1', t('Pay now'))]);
    expect(patches(editor)).toEqual([]);
    backspace(editor, 1);
    expect(normal(blocks(editor))).toEqual([p('b1', t('Pa'), t('y', [del('L')]), t(' now'))]);
    // The caret stays before the struck text, so the next Backspace goes on deleting.
    expect(editor.state.selection.from).toBe(at(editor, 0, 2));
    backspace(editor, 1);
    expect(patches(editor)).toEqual([{ op: 'replace-text', blockId: 'b1', deleteText: 'ay', insert: [], occurrence: 0 }]);
    expectRoundTrip(editor);
  });

  it('a formatting change becomes a format suggestion and leaves the text as it was', () => {
    const editor = make([p('b1', t('Pay within 30 days, 30 days at most'))]);
    editor.commands.setTextSelection({ from: at(editor, 0, 20), to: at(editor, 0, 27) });
    editor.commands.toggleMark('bold');
    expect(blocks(editor)).toEqual([p('b1', t('Pay within 30 days, 30 days at most'))]);
    const list = changes(editor);
    expect(list.map((c) => c.patch)).toEqual([
      { op: 'format', blockId: 'b1', deleteText: '', text: '30 days', occurrence: 1, mark: { type: 'bold' }, add: true },
    ]);
    expect(list[0].kind).toBe('format');
    // The server's format suggestion renders as the same range, to underline.
    const rendered = renderSuggestions(blocks(editor), [{ id: 's1', patch: list[0].patch }]);
    expect(rendered.formats).toEqual([{ suggestionId: 's1', blockId: 'b1', from: 20, to: 27 }]);
  });

  it('formatting your own pending text just formats it', () => {
    const editor = make([p('b1', t('Pay now'))]);
    typeAt(editor, at(editor, 0, 7), ' today');
    editor.commands.setTextSelection({ from: at(editor, 0, 8), to: at(editor, 0, 13) });
    editor.commands.toggleMark('bold');
    expect(normal(blocks(editor))).toEqual([p('b1', t('Pay now'), t(' ', [ins('L')]), t('today', [{ type: 'bold' }, ins('L')]))]);
    expect(patches(editor)).toEqual([
      {
        op: 'replace-text',
        blockId: 'b1',
        deleteText: '',
        insert: [t(' '), t('today', [{ type: 'bold' }])],
        before: 'Pay now',
        occurrence: 0,
      },
    ]);
  });

  it('a new block is a block insertion; a removed block is a block deletion', () => {
    const editor = make([p('b1', t('First')), p('b2', t('Second')), p('b3', t('Third'))]);
    editor.commands.setTextSelection(at(editor, 0, 5));
    editor.commands.splitBlock();
    typeAt(editor, editor.state.selection.from, 'Added');
    const marked = blocks(editor);
    expect(marked[1]).toMatchObject({ type: 'paragraph', content: [t('Added')], suggestion: { action: 'insert' } });
    const newId = marked[1].id;
    // Remove the third block whole.
    const third = editor.state.doc.child(3);
    let pos = 0;
    for (let i = 0; i < 3; i++) pos += editor.state.doc.child(i).nodeSize;
    editor.view.dispatch(editor.state.tr.delete(pos, pos + third.nodeSize));
    expect(blocks(editor)).toHaveLength(4);
    expect(blocks(editor)[3]).toMatchObject({ id: 'b3', suggestion: { action: 'delete' } });
    expect(patches(editor)).toEqual([
      { op: 'insert-blocks', afterBlockId: 'b1', blocks: [{ id: newId, type: 'paragraph', content: [t('Added')] }] },
      { op: 'delete-blocks', blockIds: ['b3'] },
    ]);
    expectRoundTrip(editor);
  });

  it('removing your own pending block just removes it', () => {
    const editor = make([p('b1', t('First'))]);
    editor.commands.setTextSelection(at(editor, 0, 5));
    editor.commands.splitBlock();
    expect(blocks(editor)).toHaveLength(2);
    editor.view.dispatch(editor.state.tr.delete(at(editor, 0, 5), at(editor, 1, 0)));
    expect(blocks(editor)).toEqual([p('b1', t('First'))]);
    expect(patches(editor)).toEqual([]);
  });

  it('Arabic text: typing and deleting in a right-to-left block', () => {
    const editor = make([p('b1', t('يرجى الدفع خلال 30 يومًا'))]);
    // Select "30 يومًا" and type "14 يومًا".
    const text = 'يرجى الدفع خلال 30 يومًا';
    const from = text.indexOf('30');
    editor.commands.setTextSelection({ from: at(editor, 0, from), to: at(editor, 0, text.length) });
    editor.view.dispatch(editor.state.tr.insertText('14 يومًا'));
    // Pasted in one go, the common end (" يومًا") is not part of the change.
    expect(normal(blocks(editor))).toEqual([p('b1', t('يرجى الدفع خلال '), t('30', [del('L')]), t('14', [ins('L')]), t(' يومًا'))]);
    expect(patches(editor)).toEqual([{ op: 'replace-text', blockId: 'b1', deleteText: '30', insert: [t('14')], occurrence: 0 }]);
    // Typed letter by letter after the struck text, Arabic extends the same insertion.
    typeAt(editor, at(editor, 0, from + 4), ' و');
    expect(patches(editor)).toEqual([{ op: 'replace-text', blockId: 'b1', deleteText: '30', insert: [t('14 و')], occurrence: 0 }]);
    expectRoundTrip(editor);
    // A pure insertion anchors on the Arabic text before it.
    const editor2 = make([p('b1', t('شكرًا لكم'))]);
    typeAt(editor2, at(editor2, 0, 5), ' جزيلًا');
    expect(patches(editor2)).toEqual([
      { op: 'replace-text', blockId: 'b1', deleteText: '', insert: [t(' جزيلًا')], before: 'شكرًا', occurrence: 0 },
    ]);
    expectRoundTrip(editor2);
  });

  it('refuses what it cannot express yet (turning a block into a heading) and leaves the block as it was', () => {
    const editor = make([p('b1', t('Title'))]);
    editor.commands.setTextSelection(at(editor, 0, 2));
    editor.commands.setNode('heading', { level: 1 });
    expect(blocks(editor)).toEqual([p('b1', t('Title'))]);
    expect(suggestingKey.getState(editor.state)?.refused).toBe(1);
  });

  it('the server id replaces the local one after the flush', () => {
    const editor = make([p('b1', t('Pay now'))]);
    typeAt(editor, at(editor, 0, 7), '!');
    const [change] = changes(editor);
    editor.view.dispatch(renameSuggestion(editor.state, change.localIds[0], '42'));
    expect(blocks(editor)).toEqual([p('b1', t('Pay now'), t('!', [ins('42')]))]);
    expect(changes(editor)).toEqual([]);
  });
});

describe('renderSuggestions: the server’s patches drawn onto the draft', () => {
  const draft = [p('b1', t('Invoice INV-1042 was due on 14 October. Please pay within 30 days of this letter.')), p('b2', t('Payments usually take two working days.'))];

  it('draws the card’s three suggestions', () => {
    const suggestions: { id: string; patch: SuggestionPatch }[] = [
      { id: '1', patch: { op: 'replace-text', blockId: 'b1', deleteText: '30 days', insert: [t('14 days')], occurrence: 0 } },
      {
        id: '2',
        patch: { op: 'replace-text', blockId: 'b1', deleteText: '', insert: [t(', or call us on 06 555 0100')], before: 'of this letter', occurrence: 0 },
      },
      { id: '3', patch: { op: 'replace-text', blockId: 'b2', deleteText: 'usually ', insert: [], occurrence: 0 } },
    ];
    const { blocks: out, unplaced } = renderSuggestions(draft, suggestions);
    expect(unplaced).toEqual([]);
    expect(out).toEqual([
      p(
        'b1',
        t('Invoice INV-1042 was due on 14 October. Please pay within '),
        t('30 days', [del('1')]),
        t('14 days', [ins('1')]),
        t(' of this letter'),
        t(', or call us on 06 555 0100', [ins('2')]),
        t('.')
      ),
      p('b2', t('Payments '), t('usually ', [del('3')]), t('take two working days.')),
    ]);
    expect(stripSuggestions(out)).toEqual(draft);
    expect(suggestions.map((s) => summarize(s.patch))).toEqual([
      'Replace “30 days” with “14 days”',
      'Add “, or call us on 06 555 0100”',
      'Delete “usually ”',
    ]);
  });

  it('inserts and deletes blocks, and reports a suggestion whose text has gone', () => {
    const { blocks: out, unplaced } = renderSuggestions(draft, [
      { id: '4', patch: { op: 'insert-blocks', afterBlockId: 'b1', blocks: [p('n1', t('New'))] } },
      { id: '5', patch: { op: 'delete-blocks', blockIds: ['b2'] } },
      { id: '6', patch: { op: 'replace-text', blockId: 'b1', deleteText: 'not there', insert: [], occurrence: 0 } },
    ]);
    expect(out.map((b) => [b.id, b.suggestion ?? null])).toEqual([
      ['b1', null],
      ['n1', { id: '4', action: 'insert' }],
      ['b2', { id: '5', action: 'delete' }],
    ]);
    expect(unplaced).toEqual(['6']);
    expect(stripSuggestions(out)).toEqual(draft);
  });

  it('counts occurrences in the draft text, not the marked text', () => {
    expect(countMatches('30 days, 30 days', '30 days', 9)).toBe(1);
    const { blocks: out } = renderSuggestions([p('b1', t('a b a b'))], [
      { id: '1', patch: { op: 'replace-text', blockId: 'b1', deleteText: '', insert: [t('a ')], before: '', occurrence: 0 } },
      { id: '2', patch: { op: 'replace-text', blockId: 'b1', deleteText: 'a', insert: [t('c')], occurrence: 1 } },
    ]);
    expect(out).toEqual([p('b1', t('a ', [ins('1')]), t('a b '), t('a', [del('2')]), t('c', [ins('2')]), t(' b'))]);
  });
});

describe('fields and breaks count as one U+FFFC character, as the server matches', () => {
  const OBJ = String.fromCharCode(0xfffc);
  const field = { type: 'field' as const, path: 'customer.name', label: 'Customer name' };

  it('anchors an insertion after a field', () => {
    const editor = make([p('b1', t('Dear '), field, t(','))]);
    typeAt(editor, at(editor, 0, 6), ' and team');
    expect(patches(editor)).toEqual([
      { op: 'replace-text', blockId: 'b1', deleteText: '', insert: [t(' and team')], before: `Dear ${OBJ}`, occurrence: 0 },
    ]);
    expectRoundTrip(editor);
  });

  it('renders around a line break', () => {
    const draft = [p('b1', t('Rana'), { type: 'break' }, t('Example Bakery'))];
    const { blocks: out } = renderSuggestions(draft, [
      { id: '1', patch: { op: 'replace-text', blockId: 'b1', deleteText: `${OBJ}Example`, insert: [t(', Example')], occurrence: 0 } },
    ]);
    expect(out).toEqual([
      p('b1', t('Rana'), { type: 'break', marks: undefined } as unknown as Inline, t('Example', [del('1')]), t(', Example', [ins('1')]), t(' Bakery')),
    ]);
  });
});

describe('suggestEdit (pure)', () => {
  it('returns null for a moved block', () => {
    const before = [p('a', t('A')), p('b', t('B'))];
    expect(suggestEdit(before, [before[1], before[0]], { localId: 'local-x', isLocal })).toBeNull();
  });
});
