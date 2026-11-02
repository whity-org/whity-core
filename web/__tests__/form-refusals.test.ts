import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { renderHook } from '@testing-library/react';
import { useRefusalMessage } from '@/components/forms/form-refusals';

const labelOf = (key: string) => (key === 'quantity' ? 'الكمية' : key);

/** With no language loaded the hook returns its English default, placeholders filled. */
const refusalMessage = () => renderHook(() => useRefusalMessage()).result.current;

describe('useRefusalMessage', () => {
  it('words a known reason itself and names the question as the reader sees it', () => {
    expect(
      refusalMessage()(
        { error: 'Quantity must be at most 5', details: { reason: 'above_max', field_key: 'quantity', params: { max: 5 } } },
        labelOf,
        'fallback'
      )
    ).toBe('الكمية must be at most 5.');
  });

  it("falls back to the server's sentence for a reason it does not know", () => {
    expect(refusalMessage()({ error: 'A newer refusal', details: { reason: 'from_the_future' } }, labelOf, 'fallback')).toBe(
      'A newer refusal'
    );
  });

  it("uses the server's sentence when there is no reason", () => {
    expect(refusalMessage()({ error: 'Not now' }, labelOf, 'fallback')).toBe('Not now');
  });

  it('uses the fallback when the server sent nothing', () => {
    expect(refusalMessage()({}, labelOf, 'fallback')).toBe('fallback');
  });

  it('says the upload limit in a number the person can compare', () => {
    expect(refusalMessage()({ details: { reason: 'file_too_large', params: { limit_mb: 5 } } }, labelOf, 'fallback')).toBe(
      'That file is too large. The limit is 5 MB.'
    );
  });
});

describe('the Arabic catalogue', () => {
  const read = (path: string) =>
    (JSON.parse(readFileSync(join(__dirname, '../../database/i18n', path), 'utf8')) as { keys: Record<string, string> }).keys;

  it('covers every English string on the fill pages', () => {
    expect(Object.keys(read('ar/forms.json')).sort()).toEqual(Object.keys(read('forms.json')).sort());
  });

  it('keeps every placeholder of the English text', () => {
    const english = read('forms.json');
    const arabic = read('ar/forms.json');
    const placeholders = (text: string) => (text.match(/\{[a-z_]+\}/g) ?? []).sort();

    for (const [key, text] of Object.entries(english)) {
      expect([key, placeholders(arabic[key] ?? '')]).toEqual([key, placeholders(text)]);
    }
  });
});
