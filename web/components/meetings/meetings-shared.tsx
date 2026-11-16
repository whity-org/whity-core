'use client';

/**
 * Pieces the meetings screens share (WHIT-1115): who may do what, the two
 * section links, the status words, and people's names. People appear as names,
 * never ids; when the team list can't be read (no calendar access) a person is
 * "A member" rather than a number.
 */

import Link from 'next/link';
import { useCallback } from 'react';
import { Badge } from '@whity/ui/badge';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { calendarApi } from '@/lib/calendar/api';
import { CONVENING_DECIDE, CONVENING_MANAGE, CONVENING_READ, type MeetingStatus } from '@/lib/meetings/api';
import { cn } from '@/lib/utils';

export interface MeetingAccess {
  loading: boolean;
  canRead: boolean;
  canManage: boolean;
  canDecide: boolean;
}

export function useMeetingAccess(): MeetingAccess {
  const { has, loading } = useCapabilities();
  return {
    loading,
    canRead: has(CONVENING_READ),
    canManage: has(CONVENING_MANAGE),
    canDecide: has(CONVENING_DECIDE),
  };
}

/** The section links under the header: the meetings, their bodies, and back to the calendar. */
export function MeetingsNav({ active }: { active: 'meetings' | 'bodies' }) {
  const t = useTranslation('meetings');
  const tabs = [
    { id: 'meetings', label: t('nav.meetings', 'Meetings'), href: '/calendar/meetings' },
    { id: 'bodies', label: t('nav.bodies', 'Meeting bodies'), href: '/calendar/meetings/bodies' },
  ] as const;
  return (
    <nav aria-label={t('nav.label', 'Meetings')} className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-border">
      <Link
        href="/calendar"
        className="inline-flex min-h-10 items-center gap-1 text-sm text-muted-foreground outline-none hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        <Icon name="chevron-left" />
        {t('nav.calendar', 'Calendar')}
      </Link>
      {tabs.map((tab) => (
        <Link
          key={tab.id}
          href={tab.href}
          aria-current={tab.id === active ? 'page' : undefined}
          className={cn(
            '-mb-px inline-flex min-h-10 items-center border-b-2 border-transparent px-1 text-sm font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
            tab.id === active && 'border-primary text-foreground'
          )}
        >
          {tab.label}
        </Link>
      ))}
    </nav>
  );
}

export function useStatusWords() {
  const t = useTranslation('meetings');
  return useCallback(
    (status: MeetingStatus) =>
      ({
        draft: t('status.draft', 'Draft'),
        scheduled: t('status.scheduled', 'Scheduled'),
        held: t('status.held', 'Held'),
        cancelled: t('status.cancelled', 'Cancelled'),
      })[status],
    [t]
  );
}

const STATUS_VARIANT = {
  draft: 'outline',
  scheduled: 'info',
  held: 'success',
  cancelled: 'destructive',
} as const;

export function MeetingStatusBadge({ status }: { status: MeetingStatus }) {
  const words = useStatusWords();
  return <Badge variant={STATUS_VARIANT[status]}>{words(status)}</Badge>;
}

/** Names for profile ids, from the team list. Unknown people are "A member", never a number. */
export function usePeopleNames() {
  const t = useTranslation('meetings');
  const { data } = useFetch(async () => {
    try {
      return await calendarApi.staff();
    } catch {
      return [];
    }
  }, []);
  const nameOf = useCallback(
    (profileId: number | null | undefined): string =>
      (data ?? []).find((p) => p.profile_id === profileId)?.display_name ?? t('people.unknown', 'A member'),
    [data, t]
  );
  return { nameOf, people: data ?? [] };
}
