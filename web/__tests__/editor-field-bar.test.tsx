/**
 * WHIT-999: a selected merge field gets its own bar, with what fills it and
 * Change field / Remove field. Before it, clicking a chip only selected it.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';
import { Editor } from '@tiptap/core';
import { NodeSelection } from '@tiptap/pm/state';

import { documentExtensions } from '@whity/ui/editor/extensions';
import { toEditorContent } from '@whity/ui/editor/convert';
import { EDITOR_LABELS } from '@whity/ui/editor/labels';
import { FieldBar } from '@whity/ui/editor/field-bar';
import type { Block } from '@whity/ui/editor/contract';
import type { FieldGroup } from '@whity/ui/editor/fields';

const GROUPS: FieldGroup[] = [
  {
    group: 'Customer',
    items: [
      { path: 'customer.name', label: 'Customer name', sample: 'Example Clinic' },
      { path: 'customer.tax', label: 'Customer tax number', sample: '[000000000]' },
    ],
  },
];

function make() {
  const element = document.createElement('div');
  document.body.append(element);
  const blocks = [
    { type: 'paragraph', content: [{ type: 'text', text: 'Dear ' }, { type: 'field', path: 'customer.name', label: 'Customer name' }] },
  ] as unknown as Block[];
  const editor = new Editor({ element, extensions: documentExtensions(), content: toEditorContent(blocks) });
  let fieldPos = -1;
  editor.state.doc.descendants((node, pos) => {
    if (node.type.name === 'field') fieldPos = pos;
  });
  return { editor, fieldPos };
}

function mount(editor: Editor) {
  const container = { current: document.createElement('div') };
  document.body.append(container.current);
  render(<FieldBar editor={editor} labels={EDITOR_LABELS} groups={GROUPS} container={container} />);
}

describe('the field bar', () => {
  it('shows nothing until a field is selected', () => {
    const { editor } = make();
    mount(editor);
    expect(screen.queryByRole('toolbar', { name: 'Field' })).toBeNull();
  });

  it('names the field, its group and its example, and changes it for another field', () => {
    const { editor, fieldPos } = make();
    mount(editor);
    act(() => {
      editor.view.dispatch(editor.state.tr.setSelection(NodeSelection.create(editor.state.doc, fieldPos)));
    });

    const bar = screen.getByRole('toolbar', { name: 'Field' });
    expect(bar.textContent).toContain('Customer name');
    expect(bar.textContent).toContain('Customer · e.g. Example Clinic');

    fireEvent.click(screen.getByRole('button', { name: 'Change field' }));
    fireEvent.click(screen.getByRole('option', { name: /Customer tax number/ }));

    const field = editor.state.doc.nodeAt(fieldPos);
    expect(field?.attrs.path).toBe('customer.tax');
    expect(field?.attrs.label).toBe('Customer tax number');
  });

  it('removes the field', () => {
    const { editor, fieldPos } = make();
    mount(editor);
    act(() => {
      editor.view.dispatch(editor.state.tr.setSelection(NodeSelection.create(editor.state.doc, fieldPos)));
    });

    fireEvent.click(screen.getByRole('button', { name: 'Remove field' }));

    let fields = 0;
    editor.state.doc.descendants((node) => {
      if (node.type.name === 'field') fields += 1;
    });
    expect(fields).toBe(0);
  });
});
