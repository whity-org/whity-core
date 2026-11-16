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
import { api } from '@/lib/api/client';
import type { Answer } from '@/components/forms/form-fields';

/** A pause after typing before the save, so each keystroke isn't a request. */
export const DRAFT_SAVE_DELAY_MS = 1500;

type Answers = Record<string, Answer>;

export interface LoadedDraft {
  answers: Answers;
  step: number;
}

export function useFormDraft(id: string) {
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
          api.PUT('/api/v1/me/forms/{id}/draft', {
            params: { path: { id: id as never } },
            keepalive,
            headers: { Accept: 'application/json' },
            body: body as never,
          })
        )
        .then(({ response }) => {
          setFailed(!response.ok);
          if (response.ok) {
            setSavedAt(new Date().toISOString());
          }
        })
        .catch(() => setFailed(true))
        .finally(() => {
          if (inFlight.current === run) inFlight.current = null;
        });
      inFlight.current = run;

      return run;
    },
    [clearTimer, id]
  );

  // Leaving within the pause after typing still keeps what they typed.
  useEffect(() => () => void send(true), [send]);

  /** What they had, or null when this is a fresh start. */
  const load = useCallback(async (): Promise<LoadedDraft | null> => {
    let server: { data?: unknown; step?: unknown } | null = null;
    try {
      const { data, response } = await api.GET('/api/v1/me/forms/{id}/draft', {
        params: { path: { id: id as never } },
        headers: { Accept: 'application/json' },
      });
      if (response.ok) {
        const body = (data ?? {}) as { data?: { data?: unknown; step?: unknown } | null };
        server = body.data ?? null;
      }
    } catch {
      // A form with no draft is still a form.
    }

    if (server !== null) {
      const answers = server.data;
      const hasAnswers = answers !== null && typeof answers === 'object' && !Array.isArray(answers);

      return {
        answers: hasAnswers ? (answers as Answers) : {},
        step: typeof server.step === 'number' ? server.step : 0,
      };
    }

    return null;
  }, [id]);

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
    setSavedAt(null);
  }, [clearTimer, id]);

  /** Start over. */
  const discard = useCallback(async () => {
    clearTimer();
    waiting.current = null;
    await inFlight.current;
    setSavedAt(null);
    setFailed(false);
    await api
      .DELETE('/api/v1/me/forms/{id}/draft', {
        params: { path: { id: id as never } },
        headers: { Accept: 'application/json' },
      })
      .catch(() => undefined);
  }, [clearTimer, id]);

  return { savedAt, failed, load, schedule, pause, resume, forget, discard };
}
