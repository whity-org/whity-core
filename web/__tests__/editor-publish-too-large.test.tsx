/**
 * #1435: a template too large for Whity Render can't be published. The
 * server sends a `too-large` blocker with the size and the ceiling, and the
 * publish check says so in the reader's language, sizes left to right.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { render, screen } from '@testing-library/react';

import { EDITOR_LABELS } from '@whity/ui/editor/labels';
import { PublishCheck } from '@whity/ui/editor/publish-check';

const BLOCKER = { kind: 'too-large' as const, bytes: 23_068_672, max: 20_971_520 };

function check(labels = EDITOR_LABELS, blocker: { kind: 'too-large'; bytes: number; max: number } = BLOCKER) {
  render(
    <PublishCheck
      open
      onOpenChange={() => {}}
      blockers={[blocker]}
      nextVersion={2}
      onPublish={() => {}}
      onFix={() => {}}
      labels={labels}
    />
  );
}

describe('Publish check: a template too large to print', () => {
  it('names the size and the limit, with no Show button, and Publish stays off', () => {
    check();
    const list = screen.getByRole('list', { name: 'What blocks publishing' });
    expect(list.textContent).toContain('This template is too large to print: 22.0 MB, and the limit is 20.0 MB');
    expect(list.textContent).toContain('Use smaller images or fewer of them, then publish again.');
    expect([...list.querySelectorAll('bdi')].map((b) => b.textContent)).toEqual(['22.0 MB', '20.0 MB']);
    expect(screen.queryByRole('button', { name: 'Show' })).toBeNull();
    expect((screen.getByRole('button', { name: 'Publish version 2' }) as HTMLButtonElement).disabled).toBe(true);
  });

  it('shows sizes under 1 MB in KB, never as 0.0 MB (WHIT-916)', () => {
    check(EDITOR_LABELS, { kind: 'too-large', bytes: 61_440, max: 51_200 });
    const list = screen.getByRole('list', { name: 'What blocks publishing' });
    expect([...list.querySelectorAll('bdi')].map((b) => b.textContent)).toEqual(['60 KB', '50 KB']);
    expect(list.textContent).not.toContain('0.0 MB');
  });

  it('keeps the sizes left to right inside the Arabic sentence', () => {
    const ar = JSON.parse(readFileSync(path.resolve(__dirname, '../../database/i18n/ar/documents.json'), 'utf8')).keys;
    check({ ...EDITOR_LABELS, blockerTooLarge: ar['blockEditor.blockerTooLarge'], blockerTooLargeHint: ar['blockEditor.blockerTooLargeHint'] });
    const bdi = document.querySelector('bdi');
    expect(bdi?.getAttribute('dir')).toBe('ltr');
    expect(bdi?.parentElement?.textContent).toBe('هذا القالب أكبر من أن يُطبع: 22.0 MB، والحد المسموح 20.0 MB');
  });

  it('is in the catalogue in English and Arabic', () => {
    const read = (p: string) => JSON.parse(readFileSync(path.resolve(__dirname, '../../database/i18n', p), 'utf8')).keys;
    const en = read('documents.json');
    const ar = read('ar/documents.json');
    for (const key of ['blockEditor.blockerTooLarge', 'blockEditor.blockerTooLargeHint']) {
      expect(en[key]).toBe(EDITOR_LABELS[key.slice('blockEditor.'.length) as keyof typeof EDITOR_LABELS]);
      expect(ar[key]).toBeTruthy();
    }
    expect(ar['blockEditor.blockerTooLarge']).toContain('{size}');
    expect(ar['blockEditor.blockerTooLarge']).toContain('{max}');
  });
});
