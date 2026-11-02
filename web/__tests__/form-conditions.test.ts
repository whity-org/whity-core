import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fieldVisibility, type ConditionalField } from '@/components/forms/form-conditions';

/**
 * The same vectors `tests/Unit/Core/Form/FieldConditionTest.php` runs, so the page
 * and the server are held to one answer about which questions are asked.
 */
const vectors = JSON.parse(
  readFileSync(join(__dirname, '../../tests/Unit/Core/Form/field-condition-vectors.json'), 'utf8')
) as {
  fields: ConditionalField[];
  cases: Array<{ name: string; data: Record<string, unknown>; hidden: string[] }>;
};

describe('fieldVisibility', () => {
  it.each(vectors.cases.map((c) => [c.name, c] as const))('%s', (_name, testCase) => {
    const hidden = Object.entries(fieldVisibility(vectors.fields, testCase.data))
      .filter(([, asked]) => !asked)
      .map(([key]) => key)
      .sort();

    expect(hidden).toEqual([...testCase.hidden].sort());
  });

  it('asks a question whose rule names a field that is not there', () => {
    const fields: ConditionalField[] = [
      { field_key: 'notes', field_type: 'text', visible_when: { field: 'gone', equals: 'x' } },
    ];

    expect(fieldVisibility(fields, {})).toEqual({ notes: true });
  });

  it('terminates on a loop', () => {
    const fields: ConditionalField[] = [
      { field_key: 'a', field_type: 'text', visible_when: { field: 'b', equals: 'x' } },
      { field_key: 'b', field_type: 'text', visible_when: { field: 'a', equals: 'x' } },
    ];

    expect(Object.keys(fieldVisibility(fields, {})).sort()).toEqual(['a', 'b']);
  });
});
