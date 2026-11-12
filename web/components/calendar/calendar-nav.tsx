'use client';

/**
 * The calendar area's sections (WHIT-944): the calendar itself, services,
 * working hours and time off, and the settings. A `<Link>` tab bar, the same
 * pattern as the approval-gating and settings tabs, so each section keeps its
 * own route and its own access state. A section the person can't use is not
 * offered; the page behind it still says so if it is opened directly.
 */

import Link from 'next/link';
import { cn } from '@/lib/utils';
import { tabsListVariants } from '@whity/ui/tabs';
import { useTranslation } from '@whity/features/i18n';
import { useCalendarAccess } from './use-calendar-basics';

export type CalendarSection = 'calendar' | 'services' | 'rooms' | 'hours' | 'settings';

export function CalendarNav({ active }: { active: CalendarSection }) {
  const t = useTranslation('calendar');
  const access = useCalendarAccess();

  const tabs: { id: CalendarSection; label: string; href: string; show: boolean }[] = [
    { id: 'calendar', label: t('nav.calendar', 'Calendar'), href: '/calendar', show: true },
    { id: 'services', label: t('nav.services', 'Services'), href: '/calendar/services', show: access.canRead },
    { id: 'rooms', label: t('nav.rooms', 'Rooms and branches'), href: '/calendar/rooms', show: access.canRead },
    { id: 'hours', label: t('nav.hours', 'Hours and time off'), href: '/calendar/hours', show: access.canRead },
    { id: 'settings', label: t('nav.settings', 'Settings'), href: '/calendar/settings', show: access.canManage },
  ];

  return (
    <nav
      aria-label={t('nav.label', 'Calendar sections')}
      data-testid="calendar-nav"
      className={cn(tabsListVariants({ variant: 'default' }), 'mb-6 h-auto w-full flex-wrap md:w-fit')}
    >
      {tabs
        .filter((tab) => tab.show)
        .map((tab) => {
          const isActive = tab.id === active;
          return (
            <Link
              key={tab.id}
              href={tab.href}
              data-testid={`calendar-nav-${tab.id}`}
              aria-current={isActive ? 'page' : undefined}
              className={cn(
                'relative inline-flex min-h-8 flex-1 items-center justify-center gap-1.5 rounded-md border border-transparent px-3 py-1 text-sm font-medium whitespace-nowrap text-muted-foreground transition-colors hover:text-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-1 focus-visible:outline-ring',
                isActive && 'bg-background text-foreground shadow-sm dark:border-input dark:bg-input/30'
              )}
            >
              {tab.label}
            </Link>
          );
        })}
    </nav>
  );
}
