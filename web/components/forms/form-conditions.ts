/**
 * Which questions a form asks, given the answers so far: the browser's copy of
 * `Whity\Core\Form\FieldCondition`. The server decides what is required and what
 * is stored; this decides what is drawn. Both run
 * `tests/Unit/Core/Form/field-condition-vectors.json`, so they cannot disagree
 * without a test failing.
 */

type ConditionValue = string | number | boolean;

/**
 * Exactly one operator besides `field`. `notEquals` asks when the controller is
 * answered with something else (an unanswered controller hides it, as `equals`
 * does); `answered` asks when it holds any answer, a ticked box included.
 */
export type FieldConditionRule =
  | { field: string; equals: ConditionValue; notEquals?: undefined; in?: undefined; answered?: undefined }
  | { field: string; notEquals: ConditionValue; equals?: undefined; in?: undefined; answered?: undefined }
  | { field: string; in: string[]; equals?: undefined; notEquals?: undefined; answered?: undefined }
  | { field: string; answered: true; equals?: undefined; notEquals?: undefined; in?: undefined };

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

/** True, a number, text that is not blank, or a list or row holding one; a blank row is no row. */
function saysSomething(value: unknown): boolean {
  if (Array.isArray(value)) return value.some(saysSomething);
  if (value !== null && typeof value === 'object') return Object.values(value).some(saysSomething);

  return value === true || typeof value === 'number' || (typeof value === 'string' && value.trim() !== '');
}

/** For `answered`: a checkbox only when ticked, anything else when it says something. */
function isAnswered(type: string, raw: unknown): boolean {
  if (type === 'checkbox') return raw === true || raw === 1 || raw === 'true' || raw === '1';

  return saysSomething(raw);
}

function matches(rule: FieldConditionRule, answer: string[]): boolean {
  if (rule.equals !== undefined) return answer.includes(operand(rule.equals));
  if (rule.notEquals !== undefined) return answer.length > 0 && !answer.includes(operand(rule.notEquals));

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

    const raw = answers[rule.field];
    if (rule.answered === true) return (resolved[key] = isAnswered(controller.field_type, raw));

    return (resolved[key] = matches(rule, operands(controller.field_type, raw)));
  };

  for (const field of fields) resolve(field.field_key, new Set());

  return resolved;
}
