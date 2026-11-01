/**
 * Editor milestone M5 in the editor itself: the mode follows permissions
 * (E.10), Suggesting sends edits as suggestions and never saves the draft,
 * Editing saves the draft without the suggestion marks drawn on it, and open
 * threads highlight their quote on screen only (E.39).
 */
import * as React from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import type { Editor } from '@tiptap/core';

import type { EditorCollab, RailSuggestion, RailThread } from '@whity-org/ui/editor/collab';
import type { WhityDocument } from '@whity-org/ui/editor/contract';
import { DocEditor } from '@whity-org/ui/editor/doc-editor';
import { SAMPLE_DOCUMENT } from '@whity-org/ui/editor/sample';

const t = (text: string) => ({ type: 'text' as const, text });
const DOC: WhityDocument = {
  ...SAMPLE_DOCUMENT,
  blocks: [
    { id: 'p1', type: 'paragraph', content: [t('Please pay within 30 days.')] },
    { id: 'p2', type: 'paragraph', content: [t('Payments usually take two days.')] },
  ],
};
const RANA = { kind: 'person' as const, id: '11', name: 'Rana Haddad' };
const OPEN: RailSuggestion = {
  id: '41',
  by: RANA,
  at: '2026-10-29T13:52:00Z',
  kind: 'text',
  summary: 'Delete “usually ”',
  status: 'open',
  patch: { op: 'replace-text', blockId: 'p2', deleteText: 'usually ', insert: [], occurrence: 0 },
};
const THREAD: RailThread = {
  id: '7',
  anchorBlockId: 'p1',
  quote: 'pay within',
  messages: [{ id: '71', by: RANA, at: '2026-10-29T13:40:00Z', body: [t('Late fee?')], mentions: [] }],
};

function editorOf(container: HTMLElement): Editor {
  const dom = container.querySelector('.ProseMirror') as HTMLElement & { editor?: Editor };
  if (!dom?.editor) throw new Error('no editor');
  return dom.editor;
}

function collab(over: Partial<EditorCollab> = {}): EditorCollab {
  return {
    suggestions: [OPEN],
    threads: [THREAD],
    canSuggest: true,
    canComment: true,
    me: RANA,
    onSuggest: jest.fn(async (change) => ({ id: '99', by: RANA, at: '2026-10-29T14:00:00Z', status: 'open' as const, ...change })),
    onComment: jest.fn(),
    ...over,
  };
}

beforeEach(() => jest.useFakeTimers());
afterEach(() => jest.useRealTimers());

it('without edit permission it opens in Suggesting, with Editing locked (E.10)', async () => {
  render(<DocEditor document={DOC} onChange={jest.fn()} canEdit={false} collab={collab()} />);
  await act(async () => {});
  const button = screen.getByRole('button', { name: 'Mode: Suggesting' });
  expect(button.className).toContain('bg-primary-tint');
  fireEvent.keyDown(button, { key: 'Enter' });
  await act(async () => {});
  const editing = await screen.findByRole('menuitem', { name: /Editing/ });
  expect(editing.getAttribute('aria-disabled')).toBe('true');
  expect(editing.textContent).toContain('Ask an owner for edit access');
});

/** The screen's part: what the server returns joins the open suggestions. */
function WithServer({ onChange, onSuggest }: { onChange: jest.Mock; onSuggest: jest.Mock }) {
  const [list, setList] = React.useState<RailSuggestion[]>([OPEN]);
  const c = React.useMemo(
    () =>
      collab({
        suggestions: list,
        onSuggest: async (change) => {
          const saved = await onSuggest(change);
          setList((l) => [...l, saved]);
          return saved;
        },
      }),
    [list, onSuggest]
  );
  return <DocEditor document={DOC} onChange={onChange} canEdit mode="suggesting" collab={c} />;
}

it('Suggesting sends typing as a suggestion after a pause and never saves the draft', async () => {
  const onChange = jest.fn();
  const c = { onSuggest: jest.fn(async (change) => ({ id: '99', by: RANA, at: '2026-10-29T14:00:00Z', status: 'open' as const, ...change })) };
  const { container } = render(<WithServer onChange={onChange} onSuggest={c.onSuggest} />);
  await act(async () => {});
  const editor = editorOf(container);
  act(() => {
    editor.commands.setTextSelection(1 + 'Please pay within 30 days'.length);
    editor.view.dispatch(editor.state.tr.insertText(' net'));
  });
  expect(c.onSuggest).not.toHaveBeenCalled();
  await act(async () => {
    jest.advanceTimersByTime(1500);
  });
  expect(c.onSuggest).toHaveBeenCalledWith({
    kind: 'text',
    summary: 'Add “ net”',
    patch: { op: 'replace-text', blockId: 'p1', deleteText: '', insert: [t(' net')], before: 'Please pay within 30 days', occurrence: 0 },
  });
  // The server's id replaces the local one.
  expect(container.querySelector('ins[data-suggestion="99"]')?.textContent).toBe(' net');
  // Drawn again from the server's suggestions, it is the same document.
  await act(async () => {});
  expect(container.querySelector('ins[data-suggestion="99"]')?.textContent).toBe(' net');
  expect(container.querySelector('del[data-suggestion="41"]')?.textContent).toBe('usually ');
  expect(onChange).not.toHaveBeenCalled();
});

it('Editing saves the draft without the suggestions drawn on it', async () => {
  const onChange = jest.fn();
  const { container } = render(<DocEditor document={DOC} onChange={onChange} canEdit collab={collab()} />);
  await act(async () => {});
  expect(container.querySelector('del[data-suggestion="41"]')?.textContent).toBe('usually ');
  const editor = editorOf(container);
  act(() => {
    editor.commands.setTextSelection(1);
    editor.view.dispatch(editor.state.tr.insertText('Now: '));
  });
  const saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
  expect(saved.blocks).toEqual([
    { id: 'p1', type: 'paragraph', content: [t('Now: Please pay within 30 days.')] },
    { id: 'p2', type: 'paragraph', content: [t('Payments usually take two days.')] },
  ]);
});

it('highlights an open thread’s quote on screen, and not a resolved one (E.39)', async () => {
  const resolved = { ...THREAD, id: '8', quote: 'two days', anchorBlockId: 'p2', resolved: { by: RANA, at: '2026-10-29T14:00:00Z' } };
  const { container } = render(<DocEditor document={DOC} onChange={jest.fn()} canEdit collab={collab({ threads: [THREAD, resolved] })} />);
  await act(async () => {});
  const marks = [...container.querySelectorAll('.wy-cmt')].map((el) => el.textContent);
  expect(marks).toEqual(['pay within']);
  // The highlight is a decoration: nothing of it is in the document.
  expect(JSON.stringify(editorOf(container).getJSON())).not.toContain('comment');
  // The rail shows the thread and the suggestion, and "1 resolved".
  const rail = screen.getByRole('complementary', { name: 'Comments and suggestions' });
  expect(rail.textContent).toContain('1 suggestion · 1 comment');
  expect(rail.textContent).toContain('1 resolved');
  expect(rail.textContent).toContain('Late fee?');
});

describe('Editing inside someone else’s suggested insertion keeps what was typed', () => {
  const FOURTEEN: RailSuggestion = {
    id: '50',
    by: RANA,
    at: '2026-10-29T13:52:00Z',
    kind: 'text',
    summary: 'Add “fourteen”',
    status: 'open',
    patch: { op: 'replace-text', blockId: 'p1', deleteText: '', insert: [t('fourteen')], before: 'Please pay within ', occurrence: 0 },
  };

  it('saves the X typed in the middle of "fourteen" as draft text, and the suggestion stays', async () => {
    const onChange = jest.fn();
    const { container } = render(<DocEditor document={DOC} onChange={onChange} canEdit collab={collab({ suggestions: [FOURTEEN], threads: [] })} />);
    await act(async () => {});
    const ins = container.querySelector('ins[data-suggestion="50"]');
    expect(ins?.textContent).toBe('fourteen');
    const editor = editorOf(container);
    // "Please pay within four|teen 30 days."
    const at = 1 + 'Please pay within four'.length;
    act(() => {
      editor.commands.setTextSelection(at);
      editor.view.dispatch(editor.state.tr.insertText('X'));
    });
    // The save path: what Editing hands the screen to save (stripSuggestions applied).
    const saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
    expect(saved.blocks[0]).toEqual({ id: 'p1', type: 'paragraph', content: [t('Please pay within X30 days.')] });
    // On screen the suggestion is around the X as it was: "four" + X + "teen".
    expect([...container.querySelectorAll('ins[data-suggestion="50"]')].map((el) => el.textContent).join('')).toBe('fourteen');
    expect(container.querySelector('[data-block-id="p1"]')?.textContent).toBe('Please pay within fourXteen30 days.');
  });

  it('also when pasted text arrives already marked', async () => {
    const onChange = jest.fn();
    const { container } = render(<DocEditor document={DOC} onChange={onChange} canEdit collab={collab({ suggestions: [FOURTEEN], threads: [] })} />);
    await act(async () => {});
    const editor = editorOf(container);
    const marked = editor.schema.text('Y', [editor.schema.marks.insertion.create({ suggestionId: '50' })]);
    act(() => {
      editor.view.dispatch(editor.state.tr.insert(1, marked));
    });
    const saved = onChange.mock.calls.at(-1)?.[0] as WhityDocument;
    expect(saved.blocks[0]).toEqual({ id: 'p1', type: 'paragraph', content: [t('YPlease pay within 30 days.')] });
  });
});
