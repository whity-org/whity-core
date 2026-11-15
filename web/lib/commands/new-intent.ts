'use client';

/**
 * "Start a new X" handed from wherever it was asked for (the ⌘K `/new`
 * command today, the phone Create sheet next) to the screen that creates X
 * (WHIT-1069).
 *
 * The asker records the request and goes to the screen; the screen, through
 * {@link useNewIntent}, opens its own New dialog. Nothing is created here:
 * the screen's dialog, its permission check and its Save button stay the
 * only way in.
 *
 * Why not a `?new=` query parameter: a screen that is already open does not
 * remount when only its query changes, and reading search params would need a
 * Suspense boundary on every one of these pages. A pending request plus an
 * event covers both cases: an open screen hears the event, a screen still
 * loading takes the pending request when it mounts.
 */

import { useEffect, useRef } from 'react';

/**
 * `ask` (WHIT-1070) is the one that is not a New dialog: it opens the person's
 * direct chat with an agent (`target`) with the words in the message box,
 * unsent, so the agent answers in Chat with a proposal to approve.
 */
export type NewKind = 'document' | 'form' | 'booking' | 'task' | 'message' | 'person' | 'ask';

export interface NewIntent {
  kind: NewKind;
  /** Words typed after the kind: a title, or for `person` the email addresses. */
  text: string;
  /** For `ask`: the agent's id in Chat's directory. */
  target?: number;
}

export const NEW_INTENT_EVENT = 'whity:new-intent';

/** A request older than this is stale: the person went somewhere else. */
const FRESH_MS = 15_000;

let pending: (NewIntent & { at: number }) | null = null;

/** Record a request; the caller then navigates to the kind's screen. */
export function requestNew(intent: NewIntent): void {
  pending = { ...intent, at: Date.now() };
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(NEW_INTENT_EVENT));
  }
}

function take(kind: NewKind): NewIntent | null {
  if (pending === null || pending.kind !== kind) return null;
  const fresh = Date.now() - pending.at <= FRESH_MS;
  const intent: NewIntent = { kind: pending.kind, text: pending.text, ...(pending.target !== undefined ? { target: pending.target } : {}) };
  pending = null;
  return fresh ? intent : null;
}

/** For tests. */
export function clearNewIntent(): void {
  pending = null;
}

/**
 * Opens this screen's New dialog when someone asked for one. `onIntent` runs
 * once per request, on mount for a request made before the screen loaded and
 * on the event for one made while it is open.
 */
export function useNewIntent(kind: NewKind, onIntent: (intent: NewIntent) => void): void {
  const handler = useRef(onIntent);
  useEffect(() => {
    handler.current = onIntent;
  });

  useEffect(() => {
    const consume = () => {
      const intent = take(kind);
      if (intent !== null) handler.current(intent);
    };
    consume();
    window.addEventListener(NEW_INTENT_EVENT, consume);
    return () => window.removeEventListener(NEW_INTENT_EVENT, consume);
  }, [kind]);
}
