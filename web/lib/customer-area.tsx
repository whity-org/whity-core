'use client';

/**
 * The customer area's state (WHIT-1416, design section 41): the workspace the
 * `/@handle` address names, who is looking, and the tabs they get.
 *
 * Loaded once per area by `app/w/[handle]/layout.tsx` and read by every page
 * under it. The API answers signed out too, so the front door and the sign-in
 * page draw from the same call.
 */

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import type { components } from '@whity/api-client';
import { api } from '@/lib/api/client';
import type { WorkspaceBrand } from '@/lib/branding-context';

export type CustomerAreaData = Omit<components['schemas']['CustomerArea'], 'workspace'> & {
  workspace: { name: string; handle: string; brand: WorkspaceBrand };
};
export type CustomerTabId = 'home' | 'bookings' | 'bills' | 'messages' | 'account';

export type CustomerAreaPhase =
  | { kind: 'loading' }
  | { kind: 'gone' }
  | { kind: 'busy' }
  | { kind: 'failed' }
  | { kind: 'ready'; area: CustomerAreaData };

interface CustomerAreaContextValue {
  handle: string;
  phase: CustomerAreaPhase;
  reload: () => void;
}

const CustomerAreaContext = createContext<CustomerAreaContextValue | null>(null);

export function CustomerAreaProvider({ handle, children }: { handle: string; children: ReactNode }) {
  const [phase, setPhase] = useState<CustomerAreaPhase>({ kind: 'loading' });
  const [tick, setTick] = useState(0);

  useEffect(() => {
    let live = true;
    api
      .GET('/api/v1/customer-area/{handle}', { params: { path: { handle } } })
      .then(({ data, response }) => {
        if (!live) return;
        if (response.status === 404) setPhase({ kind: 'gone' });
        else if (response.status === 429) setPhase({ kind: 'busy' });
        else if (!response.ok || !data) setPhase({ kind: 'failed' });
        else setPhase({ kind: 'ready', area: (data as { data: CustomerAreaData }).data });
      })
      .catch(() => live && setPhase({ kind: 'failed' }));
    return () => {
      live = false;
    };
  }, [handle, tick]);

  const reload = useCallback(() => setTick((n) => n + 1), []);
  const value = useMemo(() => ({ handle, phase, reload }), [handle, phase, reload]);

  return <CustomerAreaContext.Provider value={value}>{children}</CustomerAreaContext.Provider>;
}

export function useCustomerArea(): CustomerAreaContextValue {
  const value = useContext(CustomerAreaContext);
  if (value === null) {
    throw new Error('useCustomerArea is used outside the customer area');
  }
  return value;
}

/** The shared address of a page in the area: `/@example-bakery/bills`. */
export function areaHref(handle: string, path = ''): string {
  return `/@${handle}${path === '' ? '' : path.startsWith('/') ? path : `/${path}`}`;
}

/** Whether the person may use the area: signed in, a customer, the area on. */
export function isCustomer(phase: CustomerAreaPhase): boolean {
  return phase.kind === 'ready' && phase.area.enabled && phase.area.me?.state === 'customer';
}
