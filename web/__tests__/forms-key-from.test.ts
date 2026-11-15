/**
 * A form's key from its name (packages/features forms/text.ts). WHIT-1069
 * fills the name from ⌘K `/new form <name>`, so the trimming must stay linear
 * however many underscores a name collapses into.
 */

import { keyFrom } from '../../packages/features/src/forms/text';

describe('keyFrom', () => {
  it('lower-cases, joins words with one underscore and trims the ends', () => {
    expect(keyFrom('  Leave request!! ', new Set(), 'form')).toBe('leave_request');
    expect(keyFrom('__Stock -- count__', new Set(), 'form')).toBe('stock_count');
  });

  it('falls back when nothing usable is left, and starts with a letter', () => {
    expect(keyFrom('____', new Set(), 'form')).toBe('form');
    expect(keyFrom('طلب إجازة', new Set(), 'form')).toBe('form');
    expect(keyFrom('2026 survey', new Set(), 'form')).toBe('form_2026_survey');
  });

  it('numbers a key that is taken', () => {
    expect(keyFrom('Survey', new Set(['survey', 'survey_2']), 'form')).toBe('survey_3');
  });

  it('stays fast on a name of many separators', () => {
    const started = Date.now();
    expect(keyFrom(`a${'_ '.repeat(50_000)}!`, new Set(), 'form')).toBe('a');
    expect(Date.now() - started).toBeLessThan(500);
  });
});
