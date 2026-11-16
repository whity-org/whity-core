/**
 * Editor milestone M8: the document editor has no axe violations in the ways
 * people open it (editing, viewing, the page view, Arabic), and its controls
 * can be reached and named from the keyboard.
 *
 * axe-core can't evaluate colour contrast in jsdom (no canvas), so these
 * cover structure, roles, names and labels; contrast is the tokens' job and
 * is checked against the screenshots.
 */
import * as React from 'react';
import { act, render, screen } from '@testing-library/react';
import axe from 'axe-core';

import type { WhityDocument } from '@whity/ui/editor/contract';
import { DocEditor } from '@whity/ui/editor/doc-editor';
import { SAMPLE_DOCUMENT } from '@whity/ui/editor/sample';

const t = (text: string) => ({ type: 'text' as const, text });
const DOC: WhityDocument = {
  ...SAMPLE_DOCUMENT,
  blocks: [
    { id: 'h1', type: 'heading', level: 1, content: [t('Payment terms')] },
    { id: 'p1', type: 'paragraph', content: [t('Please pay within 30 days.')] },
    { id: 'l1', type: 'bullet', level: 0, content: [t('Example Bakery')] },
    { id: 'c1', type: 'checklist', level: 0, checked: false, content: [t('Send the reminder')] },
  ],
};

async function violations(container: HTMLElement) {
  // The editing surface is ProseMirror's contenteditable; axe checks it like any region.
  const results = await axe.run(container, { rules: { 'color-contrast': { enabled: false } } });
  return results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

beforeAll(() => {
  // The page view measures; jsdom has no layout.
  global.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
});

it('has no axe violations while editing in the continuous view', async () => {
  const { container } = render(<DocEditor document={DOC} onChange={jest.fn()} canEdit />);
  await act(async () => {});

  expect(await violations(container)).toEqual([]);
});

it('has no axe violations when it can only be read', async () => {
  const { container } = render(<DocEditor document={DOC} onChange={jest.fn()} canEdit={false} />);
  await act(async () => {});

  expect(await violations(container)).toEqual([]);
});

it('has no axe violations in the page view', async () => {
  const { container } = render(<DocEditor document={{ ...DOC, defaultView: 'page' }} onChange={jest.fn()} canEdit />);
  await act(async () => {});

  expect(await violations(container)).toEqual([]);
});

it('has no axe violations in Arabic', async () => {
  const { container } = render(
    <div dir="rtl" lang="ar">
      <DocEditor document={{ ...DOC, lang: 'ar', title: 'شروط الدفع' }} onChange={jest.fn()} canEdit />
    </div>
  );
  await act(async () => {});

  expect(await violations(container)).toEqual([]);
});

it('names every control in the toolbar, so each can be found from the keyboard', async () => {
  render(<DocEditor document={DOC} onChange={jest.fn()} canEdit />);
  await act(async () => {});

  const toolbar = screen.getAllByRole('toolbar')[0];
  const controls = Array.from(toolbar.querySelectorAll('button, [role="button"], select, input'));
  expect(controls.length).toBeGreaterThan(5);
  for (const control of controls) {
    const name = control.getAttribute('aria-label') ?? control.textContent ?? '';
    expect(name.trim()).not.toBe('');
    expect((control as HTMLElement).tabIndex).toBeGreaterThanOrEqual(-1);
  }
  // The document itself is one Tab stop: the editing surface.
  const surface = document.querySelector('.ProseMirror') as HTMLElement;
  expect(surface.getAttribute('contenteditable')).toBe('true');
});
