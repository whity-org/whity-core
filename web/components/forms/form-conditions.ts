/**
 * Which questions a form asks, given the answers so far: the browser's copy of
 * `Whity\Core\Form\FieldCondition`. The server decides what is required and what
 * is stored; this decides what is drawn. Both run
 * `tests/Unit/Core/Form/field-condition-vectors.json`, so they cannot disagree
 * without a test failing.
 */

export type FieldConditionRule =
  | { field: string; equals: string | number | boolean; in?: undefined }
  | { field: string; in: string[]; equals?: undefined };

export interface ConditionalField {
  field_key: string;
  field_type: string;
  visible_when?: FieldConditionRule | null;
}

function operand(value: unknown): string {
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number') return String(value);

  return typeof value === 'string' ? value.trim() : '';
}

/** A checkbox nobody touched says no; any other unanswered question says nothing. */
function operands(type: string, raw: unknown): string[] {
  if (type === 'checkbox') {
    return [raw === true || raw === 1 || raw === 'true' || raw === '1' ? 'true' : 'false'];
  }
  if (Array.isArray(raw)) return raw.map(operand).filter((value) => value !== '');
  const value = operand(raw);

  return value === '' ? [] : [value];
}

function matches(rule: FieldConditionRule, answer: string[]): boolean {
  if (rule.equals !== undefined) return answer.includes(operand(rule.equals));

  return (rule.in ?? []).some((wanted) => answer.includes(operand(wanted)));
}

/** Every field key, true when the question is asked. */
export function fieldVisibility(
  fields: ConditionalField[],
  answers: Record<string, unknown>
): Record<string, boolean> {
  const byKey = new Map(fields.map((field) => [field.field_key, field]));
  const resolved: Record<string, boolean> = {};

  const resolve = (key: string, visiting: Set<string>): boolean => {
    if (key in resolved) return resolved[key];

    const rule = byKey.get(key)?.visible_when;
    if (rule === null || rule === undefined) return (resolved[key] = true);

    const controller = byKey.get(rule.field);
    // A rule that cannot be evaluated asks the question, as on the server.
    if (controller === undefined || visiting.has(key) || visiting.has(rule.field)) {
      return (resolved[key] = true);
    }
    if (!resolve(rule.field, new Set(visiting).add(key))) return (resolved[key] = false);

    return (resolved[key] = matches(rule, operands(controller.field_type, answers[rule.field])));
  };

  for (const field of fields) resolve(field.field_key, new Set());

  return resolved;
}
