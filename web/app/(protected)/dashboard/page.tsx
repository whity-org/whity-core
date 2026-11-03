'use client';

/**
 * Home (A01; TplHome, TplHomeMember, TplAdminDashboard). One home, built per
 * person (H.1): each card appears only when the person can see what it shows.
 *
 * Left out until a server item returns them, never faked: Finish setting up,
 * From your apps (S2), and Your recent documents.
 */

import { useState } from 'react';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { useCapabilitiesContext } from '@/lib/capabilities-context';
import { AgentsToday } from './agents-today';
import { PlatformCards } from './platform-cards';
import { partOfDay } from './relative-time';
import { WaitingForYou } from './waiting-for-you';

/** The system tenant's id: its people are the platform's operators. */
const SYSTEM_TENANT_ID = 0;

export default function DashboardPage() {
  const t = useTranslation('common');
  const dates = useDateDisplay();
  const { memberships, user } = useAuth();
  const capabilities = useCapabilitiesContext();
  // Bumped when a decision is made so Agents today follows Waiting for you.
  const [refreshKey, setRefreshKey] = useState(0);

  const greeting = {
    morning: t('dashboard.greeting.morning', 'Good morning'),
    afternoon: t('dashboard.greeting.afternoon', 'Good afternoon'),
    evening: t('dashboard.greeting.evening', 'Good evening'),
  }[partOfDay()];

  const workspace = memberships.find((m) => m.tenant_id === user?.tenant_id)?.tenant_name;
  // Through the shared date display, so a workspace that hides dates shows none.
  const date = dates.date(new Date().toISOString());

  return (
    <div className="grid gap-6">
      <header>
        <h1 className="text-3xl font-semibold text-foreground">{greeting}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{[date, workspace].filter((part) => part).join(' · ')}</p>
      </header>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
        <WaitingForYou onChange={() => setRefreshKey((key) => key + 1)} />
        <AgentsToday refreshKey={refreshKey} />
      </div>

      {/* Platform cards are for operators: the system tenant, with stats:read. */}
      {user?.tenant_id === SYSTEM_TENANT_ID && capabilities.has('stats:read') && <PlatformCards />}
    </div>
  );
}
