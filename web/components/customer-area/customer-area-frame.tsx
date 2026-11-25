'use client';

/**
 * Decides what a customer area page shows (WHIT-1416): the page itself, in
 * the customer shell, for a signed-in customer; the front door for everyone
 * else; plain words while it loads or when the address names nothing.
 */

import type { ReactNode } from 'react';
import { PublicShell } from '@/components/auth/public-shell';
import { WorkspaceBrandProvider } from '@/lib/branding-context';
import { CustomerAreaProvider, isCustomer, useCustomerArea } from '@/lib/customer-area';
import { DateDisplayProvider } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { AreaDoor } from './area-door';
import { LoadState } from './area-parts';
import { CustomerShell } from './customer-shell';

export function CustomerAreaFrame({ handle, children }: { handle: string; children: ReactNode }) {
  return (
    <CustomerAreaProvider handle={handle.toLowerCase()}>
      <DateDisplayProvider hidden={false}>
        <Frame>{children}</Frame>
      </DateDisplayProvider>
    </CustomerAreaProvider>
  );
}

function Frame({ children }: { children: ReactNode }) {
  const t = useTranslation('customer');
  const { phase, reload } = useCustomerArea();

  if (phase.kind !== 'ready') {
    return (
      <PublicShell>
        <LoadState
          state={phase.kind === 'gone' ? { kind: 'gone' } : phase}
          onRetry={reload}
          goneTitle={t('state.noWorkspace', 'There is no business at this address')}
        />
      </PublicShell>
    );
  }
  if (!isCustomer(phase)) {
    // Signed out, not a customer yet, waiting, or the area off.
    return <AreaDoor area={phase.area} onChanged={reload} />;
  }

  return (
    <WorkspaceBrandProvider brand={phase.area.workspace.brand}>
      <CustomerShell area={phase.area}>{children}</CustomerShell>
    </WorkspaceBrandProvider>
  );
}
