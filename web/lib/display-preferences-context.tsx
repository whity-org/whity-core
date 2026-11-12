'use client';

import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import {
  DisplayPreferencesContextProvider,
  MOTION_STORAGE_KEY,
  TEXT_SIZE_STORAGE_KEY,
  type DisplayPreferences,
  type TextSize,
} from '@whity/ui/display-preferences';
import { MOTION_MODES, type MotionMode } from '@whity/ui/lottie';

import { useAuth } from '@/lib/auth-context';

/**
 * A person's text size, Show technical details and Animations (W.3, A.1, A.3, LT.4).
 *
 * The account holds them, so they follow the person across devices. localStorage
 * keeps the size so the blocking script below can set it before first paint, as
 * the theme does; the signed-in fetch then corrects it. Tokens scale from
 * `<html data-text-size>`, so nothing else has to know.
 */

function applyTextSize(size: TextSize): void {
  if (size === 'larger') document.documentElement.dataset.textSize = 'larger';
  else delete document.documentElement.dataset.textSize;
}

function storeTextSize(value: TextSize): void {
  try {
    localStorage.setItem(TEXT_SIZE_STORAGE_KEY, value);
  } catch {
    // Private windows can refuse storage; the account still holds the choice.
  }
}

/** The motion player reads `<html data-motion>`; Full is the default and needs no attribute. */
function applyMotion(mode: MotionMode): void {
  if (mode === 'full') delete document.documentElement.dataset.motion;
  else document.documentElement.dataset.motion = mode;
}

function storeMotion(mode: MotionMode): void {
  try {
    localStorage.setItem(MOTION_STORAGE_KEY, mode);
  } catch {
    // As for the text size.
  }
}

function toMotion(value: unknown): MotionMode {
  return (MOTION_MODES as readonly unknown[]).includes(value) ? (value as MotionMode) : 'full';
}

const INIT_SCRIPT = `try{var d=document.documentElement;if(localStorage.getItem(${JSON.stringify(TEXT_SIZE_STORAGE_KEY)})==='larger')d.dataset.textSize='larger';var m=localStorage.getItem(${JSON.stringify(MOTION_STORAGE_KEY)});if(m==='calm'||m==='off')d.dataset.motion=m}catch(e){}`;

export function DisplayPreferencesInitScript() {
  return <script dangerouslySetInnerHTML={{ __html: INIT_SCRIPT }} />;
}

export function DisplayPreferencesProvider({ children }: { children: ReactNode }) {
  const { user, apiClient } = useAuth();
  const [preferences, setPreferences] = useState<DisplayPreferences>({
    textSize: 'standard',
    showTechnicalDetails: false,
    animations: 'full',
  });

  const accept = useCallback((next: DisplayPreferences) => {
    setPreferences(next);
    applyTextSize(next.textSize);
    storeTextSize(next.textSize);
    applyMotion(next.animations);
    storeMotion(next.animations);
  }, []);

  const userId = user !== null ? user.id : null;
  useEffect(() => {
    if (userId === null) return;
    let cancelled = false;
    void (async () => {
      try {
        const response = await apiClient('/api/v1/me/display-preferences');
        if (!response.ok || cancelled) return;
        const body = (await response.json()) as { data?: Partial<DisplayPreferences> };
        if (cancelled || body.data === undefined) return;
        accept({
          textSize: body.data.textSize === 'larger' ? 'larger' : 'standard',
          showTechnicalDetails: body.data.showTechnicalDetails === true,
          animations: toMotion(body.data.animations),
        });
      } catch {
        // Offline or a failed request: keep what's showing.
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [userId, apiClient, accept]);

  const update = useCallback(
    (patch: Partial<DisplayPreferences>) => {
      setPreferences((current) => {
        const next = { ...current, ...patch };
        accept(next);
        return next;
      });
      void apiClient('/api/v1/me/display-preferences', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(patch),
      }).catch(() => {
        // The browser keeps the choice; the next change retries the account.
      });
    },
    [apiClient, accept],
  );

  const value = useMemo(() => ({ ...preferences, update }), [preferences, update]);

  return <DisplayPreferencesContextProvider value={value}>{children}</DisplayPreferencesContextProvider>;
}
