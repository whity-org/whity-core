'use client';

import { useMemo } from 'react';
import { useRouter } from 'next/navigation';
import type { FormsScreenEnv } from '@whity/features/forms';
import type { Transport } from '@whity/features/record';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';

/**
 * The web transport: the cookie-authenticated `apiClient`, mapped into the
 * `{ status, body }` seam every feature package is written over.
 */
const webTransport: Transport = {
  async request(method: string, path: string, body?: unknown) {
    // A FormData body is a multipart upload: the browser writes its own
    // Content-Type with the boundary, so none is set here.
    const response = await apiClient(path, {
      method,
      ...(body instanceof FormData
        ? { body }
        : body !== undefined
          ? { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
          : {}),
    });
    const parsed: unknown = await response.json().catch(() => null);
    return { status: response.status, body: parsed };
  },
};

/** What every forms screen needs from web: its transport, its toasts and its router. */
export function useFormsEnv(): FormsScreenEnv {
  const router = useRouter();
  const { addToast } = useToast();

  return useMemo(
    () => ({
      transport: webTransport,
      notify: (message, type, action) => addToast(message, type, action ? 10000 : 3000, action),
      navigate: (path: string) => router.push(path),
    }),
    [addToast, router]
  );
}
