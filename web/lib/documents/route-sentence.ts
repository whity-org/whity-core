/**
 * A route described in one sentence: "Branch manager, then Finance" (A32).
 *
 * The order is the stage order the author gave, not a walk of the graph, so a
 * route with branches still reads as its stages one after another; the flow
 * editor is where the branches are drawn.
 */

export interface SentenceStep {
  position: number;
  ruleKind: string;
  ruleConfig: Record<string, unknown>;
  label: string | null;
}

/** The words a sentence is made of, already translated by the caller. */
export interface SentenceWords {
  unnamed: string;
  empty: string;
  join: (before: string, next: string) => string;
}

/**
 * @param nameOf the words for one stage when it carries no label of its own
 *               (a role or group name); null when they are not known.
 */
export function routeSentence(
  steps: readonly SentenceStep[],
  nameOf: (step: SentenceStep) => string | null,
  words: SentenceWords
): string {
  const names = [...steps]
    .sort((a, b) => a.position - b.position)
    .map((step) => {
      const label = step.label?.trim() ?? '';
      return label !== '' ? label : (nameOf(step) ?? words.unnamed);
    });

  if (names.length === 0) return words.empty;

  return names.reduce((sentence, name) => words.join(sentence, name));
}
