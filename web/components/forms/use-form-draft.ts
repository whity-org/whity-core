'use client';

/**
 * A person's unsent answers, kept on the server so they can carry on from another
 * device (migration 210, F.2).
 *
 * A draft is a convenience. Nothing here can stop somebody sending the form: a save
 * that fails says so and tries again on their next change.
 *
 * Answers an earlier version kept in this browser move across once, then leave it:
 * a shared computer shouldn't hold them.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import type { Answer } from '@/components/forms/form-fields';

/** A pause after typing before the save, so each keystroke isn't a request. */
export const DRAFT_SAVE_DELAY_MS = 1500;

type Answers = Record<string, Answer>;

export interface LoadedDraft {
  answers: Answers;
  step: number;
}

function legacyKey(id: string): string {
  return `whity:form-draft:${id}`;
}

function readLegacy(id: string): Answers | null {
  try {
    const raw = window.localStorage.getItem(legacyKey(id));
    if (raw === null) return null;
    const parsed: unknown = JSON.parse(raw);
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return null;

    return Object.keys(parsed).length > 0 ? (parsed as Answers) : null;
  } catch {
    // Private windows and browsers that block storage land here.
    return null;
  }
}

function dropLegacy(id: string): void {
  try {
    window.localStorage.removeItem(legacyKey(id));
  } catch {
    // Nothing to do.
  }
}

export function useFormDraft(id: string) {
  const address = `/api/v1/me/forms/${encodeURIComponent(id)}/draft`;
  const [savedAt, setSavedAt] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);

  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const waiting = useRef<{ data: Answers; step: number } | null>(null);
  const inFlight = useRef<Promise<void> | null>(null);
  const paused = useRef(false);

  const clearTimer = useCallback(() => {
    if (timer.current !== null) clearTimeout(timer.current);
    timer.current = null;
  }, []);

  const send = useCallback(
    (keepalive = false): Promise<void> => {
      clearTimer();
      const body = waiting.current;
      if (body === null || paused.current) return Promise.resolve();
      waiting.current = null;

      const run = (inFlight.current ?? Promise.resolve())
        .then(() =>
          apiClient(address, {
            method: 'PUT',
            keepalive,
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
          })
        )
        .then((response) => {
          setFailed(!response.ok);
          if (response.ok) {
            setSavedAt(new Date().toISOString());
            dropLegacy(id);
          }
        })
        .catch(() => setFailed(true))
        .finally(() => {
          if (inFlight.current === run) inFlight.current = null;
        });
      inFlight.current = run;

      return run;
    },
    [address, clearTimer, id]
  );

  // Leaving within the pause after typing still keeps what they typed.
  useEffect(() => () => void send(true), [send]);

  /** What they had, or null when this is a fresh start. */
  const load = useCallback(async (): Promise<LoadedDraft | null> => {
    let server: { data?: unknown; step?: unknown } | null = null;
    let reachable = false;
    try {
      const response = await apiClient(address, { headers: { Accept: 'application/json' } });
      reachable = response.ok;
      if (response.ok) {
        const body = (await response.json().catch(() => ({}))) as { data?: { data?: unknown; step?: unknown } | null };
        server = body.data ?? null;
      }
    } catch {
      // A form with no draft is still a form.
    }

    if (server !== null) {
      dropLegacy(id);
      const answers = server.data;
      const hasAnswers = answers !== null && typeof answers === 'object' && !Array.isArray(answers);

      return {
        answers: hasAnswers ? (answers as Answers) : {},
        step: typeof server.step === 'number' ? server.step : 0,
      };
    }

    const legacy = readLegacy(id);
    if (legacy === null) return null;
    if (reachable) {
      waiting.current = { data: legacy, step: 0 };
      void send();
    }

    return { answers: legacy, step: 0 };
  }, [address, id, send]);

  const schedule = useCallback(
    (data: Answers, step: number) => {
      waiting.current = { data, step };
      clearTimer();
      if (paused.current) return;
      timer.current = setTimeout(() => void send(), DRAFT_SAVE_DELAY_MS);
    },
    [clearTimer, send]
  );

  /** Holds saves while the answers are being sent, and waits for one already going. */
  const pause = useCallback(async () => {
    clearTimer();
    paused.current = true;
    await inFlight.current;
  }, [clearTimer]);

  /** The send was refused, so they are still working on it. */
  const resume = useCallback(() => {
    paused.current = false;
    if (waiting.current !== null) void send();
  }, [send]);

  /** The server has the answers and has dropped its draft. */
  const forget = useCallback(() => {
    clearTimer();
    waiting.current = null;
    dropLegacy(id);
    setSavedAt(null);
  }, [clearTimer, id]);

  /** Start over. */
  const discard = useCallback(async () => {
    clearTimer();
    waiting.current = null;
    await inFlight.current;
    dropLegacy(id);
    setSavedAt(null);
    setFailed(false);
    await apiClient(address, {
      method: 'DELETE',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).catch(() => undefined);
  }, [address, clearTimer, id]);

  return { savedAt, failed, load, schedule, pause, resume, forget, discard };
}
