'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';

/** A window is announced by a person, so once a minute notices it soon enough. */
const POLL_MS = 60_000;

/**
 * A line across the top of the app while an operator has announced maintenance.
 *
 * A notice, not TplSystemStates' full page: the announcement is a signal and the
 * API keeps answering, so a page that blocked people would stop work that still
 * works and could lock an operator out of turning it off. If the status can't be
 * read, nothing shows: not knowing is not an announcement.
 */
export function MaintenanceNotice() {
  const t = useTranslation('common');
  const dates = useDateDisplay();
  const [maintenance, setMaintenance] = useState<{ active: boolean; until: string | null } | null>(null);

  const load = useCallback(async () => {
    try {
      const response = await fetch('/api/v1/status?days=1', { headers: { Accept: 'application/json' } });
      if (!response.ok) return;
      const body = (await response.json()) as { maintenance?: { active: boolean; until: string | null } };
      setMaintenance(body.maintenance ?? null);
    } catch {
      setMaintenance(null);
    }
  }, []);

  useEffect(() => {
    const kickoff = setTimeout(() => void load(), 0);
    const timer = setInterval(() => void load(), POLL_MS);
    return () => {
      clearTimeout(kickoff);
      clearInterval(timer);
    };
  }, [load]);

  if (!maintenance?.active) return null;

  const when = dates.dateTime(maintenance.until);

  return (
    <div
      role="status"
      data-testid="maintenance-notice"
      className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border bg-warning/10 px-6 py-2 text-sm md:px-8"
    >
      <span>
        {when !== null
          ? t('maintenance.noticeUntil', 'We’re updating the service, back at {when}. Your data is safe.', { when })
          : t('maintenance.notice', 'We’re updating the service. Your data is safe.')}
      </span>
      <Link href="/status" className="underline">
        {t('maintenance.seeStatus', 'See status')}
      </Link>
    </div>
  );
}
