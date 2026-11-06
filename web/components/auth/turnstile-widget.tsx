'use client';

import { useEffect, useRef } from 'react';

/**
 * Cloudflare Turnstile, rendered explicitly (WHIT-915).
 *
 * Loads Cloudflare's script once, renders the widget into a box it owns and
 * reports each fresh token. A token is single-use, so the parent calls
 * `reset` (via the `resetKey` prop changing) after a failed submit.
 *
 * Only shown when the server says a bot check is on; the server decides whether
 * the form needs one, never this component.
 */

interface TurnstileApi {
  render: (el: HTMLElement, options: Record<string, unknown>) => string;
  reset: (id?: string) => void;
  remove: (id: string) => void;
}

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let scriptPromise: Promise<void> | null = null;

function loadScript(): Promise<void> {
  if (typeof window === 'undefined') return Promise.resolve();
  if (window.turnstile) return Promise.resolve();
  if (scriptPromise) return scriptPromise;

  scriptPromise = new Promise<void>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    script.onload = () => resolve();
    script.onerror = () => {
      scriptPromise = null;
      reject(new Error('turnstile script failed to load'));
    };
    document.head.appendChild(script);
  });

  return scriptPromise;
}

export function TurnstileWidget({
  siteKey,
  language,
  theme,
  resetKey,
  onToken,
  onError,
}: {
  siteKey: string;
  language: string;
  theme: 'light' | 'dark';
  resetKey: number;
  onToken: (token: string | null) => void;
  onError: () => void;
}) {
  const box = useRef<HTMLDivElement>(null);
  const widgetId = useRef<string | null>(null);
  const callbacks = useRef({ onToken, onError });

  useEffect(() => {
    callbacks.current = { onToken, onError };
  }, [onToken, onError]);

  useEffect(() => {
    let cancelled = false;

    loadScript()
      .then(() => {
        if (cancelled || !box.current || !window.turnstile) return;
        widgetId.current = window.turnstile.render(box.current, {
          sitekey: siteKey,
          language,
          theme,
          callback: (token: string) => callbacks.current.onToken(token),
          'expired-callback': () => callbacks.current.onToken(null),
          'error-callback': () => callbacks.current.onError(),
        });
      })
      .catch(() => callbacks.current.onError());

    return () => {
      cancelled = true;
      if (widgetId.current && window.turnstile) {
        window.turnstile.remove(widgetId.current);
      }
      widgetId.current = null;
    };
  }, [siteKey, language, theme]);

  useEffect(() => {
    if (resetKey > 0 && widgetId.current && window.turnstile) {
      window.turnstile.reset(widgetId.current);
    }
  }, [resetKey]);

  return <div ref={box} className="min-h-[65px]" data-testid="signup-bot-check" />;
}
