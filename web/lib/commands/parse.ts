/**
 * Reading what was typed into ⌘K as a command (WHIT-1069).
 *
 * `/new document Invoice` → `{ name: 'new', named: true, rest: 'document Invoice' }`.
 * Pure, so the rules are tested without a palette on screen.
 */

export interface ParsedCommand {
  /** The word after the slash, as typed (not folded). */
  name: string;
  /** True once a space follows the name: the person has moved on to its details. */
  named: boolean;
  /** Everything after the first space, trimmed at the start only. */
  rest: string;
}

/** `null` when the query is not a command (does not start with a slash). */
export function parseCommand(query: string): ParsedCommand | null {
  const q = query.replace(/^\s+/, '');
  if (!q.startsWith('/')) return null;
  const body = q.slice(1);
  const space = body.search(/\s/);
  if (space === -1) return { name: body, named: false, rest: '' };
  return { name: body.slice(0, space), named: true, rest: body.slice(space + 1).replace(/^\s+/, '') };
}

/**
 * Folds a word for matching the way server search does (SearchText::fold):
 * lower case, Arabic marks and tatweel gone, alef forms to bare alef, ta
 * marbuta to ha, alef maksura to ya, Arabic-Indic digits to Western.
 */
export function fold(text: string): string {
  return text
    .toLowerCase()
    .replace(/[ً-ٰٟـ]/g, '')
    .replace(/[آأإٱ]/g, 'ا')
    .replace(/ة/g, 'ه')
    .replace(/ى/g, 'ي')
    .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
    .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
    .trim();
}

/**
 * How well `typed` names something called any of `names`: 3 exact, 2 starts
 * with, 1 contains, 0 not at all. An empty `typed` matches everything at 1.
 */
export function nameScore(typed: string, names: readonly string[]): number {
  const t = fold(typed);
  if (t === '') return 1;
  let best = 0;
  for (const name of names) {
    const n = fold(name);
    if (n === t) return 3;
    if (n.startsWith(t)) best = Math.max(best, 2);
    else if (n.includes(t)) best = Math.max(best, 1);
  }
  return best;
}

/** `names` that `typed` matches, best first, stable for equal scores. */
export function rank<T>(typed: string, items: readonly T[], namesOf: (item: T) => readonly string[]): T[] {
  return items
    .map((item, index) => ({ item, index, score: nameScore(typed, namesOf(item)) }))
    .filter((row) => row.score > 0)
    .sort((a, b) => b.score - a.score || a.index - b.index)
    .map((row) => row.item);
}
