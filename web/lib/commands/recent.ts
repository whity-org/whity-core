/**
 * What a person opened or ran from ⌘K lately, shown when the box opens empty
 * (WHIT-1070).
 *
 * Kept in this browser only, per person and workspace: it is a convenience,
 * like a remembered tab, and losing it costs nothing. Every read and write is
 * guarded, because storage can be off (a private window) or full. A record
 * kept here is only a link: opening it goes through the record's own page and
 * its permission check, so a record someone lost access to simply refuses.
 */

export interface RecentPlace {
  /** Stable across visits: `record-document-42`, `page-users`. */
  id: string;
  label: string;
  href: string;
  hint?: string;
}

export interface Recent {
  places: RecentPlace[];
  /** Command lines as typed: `/new task`, `/theme dark`. */
  commands: string[];
}

export const MAX_PLACES = 5;
export const MAX_COMMANDS = 3;

const EMPTY: Recent = { places: [], commands: [] };

function key(owner: string): string {
  return `whity:palette-recent:${owner}`;
}

function isPlace(value: unknown): value is RecentPlace {
  if (typeof value !== 'object' || value === null) return false;
  const v = value as Record<string, unknown>;
  // Only same-site paths: a tampered entry must not send anyone elsewhere.
  return (
    typeof v.id === 'string' &&
    typeof v.label === 'string' &&
    typeof v.href === 'string' &&
    v.href.startsWith('/') &&
    !v.href.startsWith('//') &&
    (v.hint === undefined || typeof v.hint === 'string')
  );
}

export function readRecent(owner: string): Recent {
  try {
    const raw = window.localStorage.getItem(key(owner));
    if (raw === null) return EMPTY;
    const parsed = JSON.parse(raw) as { places?: unknown; commands?: unknown };
    const places = Array.isArray(parsed.places) ? parsed.places.filter(isPlace).slice(0, MAX_PLACES) : [];
    const commands = Array.isArray(parsed.commands)
      ? parsed.commands.filter((c): c is string => typeof c === 'string' && c.startsWith('/')).slice(0, MAX_COMMANDS)
      : [];
    return { places, commands };
  } catch {
    return EMPTY;
  }
}

function write(owner: string, recent: Recent): void {
  try {
    window.localStorage.setItem(key(owner), JSON.stringify(recent));
  } catch {
    // Storage off or full: the palette works without it.
  }
}

/** Moves `place` to the front, once. */
export function rememberPlace(owner: string, place: RecentPlace): Recent {
  const now = readRecent(owner);
  const next: Recent = {
    places: [place, ...now.places.filter((p) => p.id !== place.id)].slice(0, MAX_PLACES),
    commands: now.commands,
  };
  write(owner, next);
  return next;
}

/** Moves a command line to the front, once; trailing details are kept as typed. */
export function rememberCommand(owner: string, line: string): Recent {
  const trimmed = line.trim();
  const now = readRecent(owner);
  if (!trimmed.startsWith('/')) return now;
  const next: Recent = {
    places: now.places,
    commands: [trimmed, ...now.commands.filter((c) => c !== trimmed)].slice(0, MAX_COMMANDS),
  };
  write(owner, next);
  return next;
}
