'use client';

/**
 * The calendar area's sections (WHIT-944): the calendar itself, services,
 * working hours and time off, and the settings. A `<Link>` tab bar, the same
 * pattern as the approval-gating and settings tabs, so each section keeps its
 * own route and its own access state. A section the person can't use is not
 * offered; the page behind it still says so if it is opened directly.
 *
 * From 768px the setup pages are underlined tabs after a link back to the
 * calendar; the calendar itself reaches them from the gear in its bar (design
 * 3.5, CV.3). `part` lets a page place the phone's button and the tabs apart.
 */

import Link from 'next/link';
import { useState } from 'react';
import { Button } from '@whity/ui/button';
import { Icon, type IconName } from '@whity/ui/icon';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import { cn } from '@/lib/utils';
import { useTranslation } from '@whity/features/i18n';
import { useCalendarAccess } from './use-calendar-basics';

export type CalendarSection = 'calendar' | 'services' | 'rooms' | 'hours' | 'settings';

export function CalendarNav({
  active,
  part = 'both',
}: {
  active: CalendarSection;
  /** Which half to draw: the phone's sheet button, the tabs, or both (the default). */
  part?: 'phone' | 'desktop' | 'both';
}) {
  const t = useTranslation('calendar');
  const access = useCalendarAccess();

  const [open, setOpen] = useState(false);

  const tabs: { id: CalendarSection; label: string; href: string; show: boolean }[] = [
    { id: 'calendar', label: t('nav.phone.bookings', 'Bookings'), href: '/calendar', show: true },
    { id: 'services', label: t('nav.services', 'Services'), href: '/calendar/services', show: access.canRead },
    { id: 'rooms', label: t('nav.rooms', 'Rooms and branches'), href: '/calendar/rooms', show: access.canRead },
    { id: 'hours', label: t('nav.hours', 'Hours and time off'), href: '/calendar/hours', show: access.canRead },
    { id: 'settings', label: t('nav.settings', 'Settings'), href: '/calendar/settings', show: access.canManage },
  ];

  // The phone's version (PwCalendar): the pages are a sheet behind the page's
  // name, with a line saying what each holds.
  const phonePages: { id: CalendarSection; label: string; hint: string; icon: IconName }[] = [
    { id: 'calendar', label: t('nav.phone.bookings', 'Bookings'), hint: t('nav.phone.bookingsHint', 'Day, week and month'), icon: 'calendar' },
    { id: 'services', label: t('nav.services', 'Services'), hint: t('nav.phone.servicesHint', 'What customers can book'), icon: 'tool' },
    { id: 'rooms', label: t('nav.rooms', 'Rooms and branches'), hint: t('nav.phone.roomsHint', 'Where bookings happen'), icon: 'branch' },
    { id: 'hours', label: t('nav.hours', 'Hours and time off'), hint: t('nav.phone.hoursHint', 'Who works when'), icon: 'clock' },
    { id: 'settings', label: t('nav.phone.settings', 'Calendar settings'), hint: t('nav.phone.settingsHint', 'Booking rules and reminders'), icon: 'settings' },
  ];
  const visible = tabs.filter((tab) => tab.show).map((tab) => tab.id);
  const current = phonePages.find((page) => page.id === active) ?? phonePages[0];

  return (
    <>
    {part !== 'desktop' && (
    <div className="mb-4 md:hidden">
      <Button
        type="button"
        variant="outline"
        className="h-12 w-full justify-between text-base"
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => setOpen(true)}
        data-testid="calendar-nav-phone"
      >
        <span className="min-w-0 truncate">{current.label}</span>
        <Icon name="chevron-down" size={18} />
      </Button>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent
          side="bottom"
          closeLabel={t('nav.phone.close', 'Close')}
          className="gap-3 rounded-t-2xl pb-[calc(1.25rem+env(safe-area-inset-bottom))] text-sm"
          data-testid="calendar-nav-sheet"
        >
          <div aria-hidden className="mx-auto h-1 w-10 rounded-full bg-border" />
          <SheetHeader>
            <SheetTitle className="text-lg font-semibold">{t('nav.calendar', 'Calendar')}</SheetTitle>
            <SheetDescription className="sr-only">{t('nav.label', 'Calendar sections')}</SheetDescription>
          </SheetHeader>
          <ul className="overflow-hidden rounded-xl border border-border">
            {phonePages
              .filter((page) => visible.includes(page.id))
              .map((page) => {
                const href = tabs.find((tab) => tab.id === page.id)?.href ?? '/calendar';
                const isActive = page.id === active;
                return (
                  <li key={page.id} className="border-b border-divider last:border-b-0">
                    <Link
                      href={href}
                      onClick={() => setOpen(false)}
                      aria-current={isActive ? 'page' : undefined}
                      className="flex min-h-14 items-center gap-3 px-3 py-2 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      <span className="text-muted-foreground"><Icon name={page.icon} size={20} /></span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-base">{page.label}</span>
                        <span className="block text-sm text-muted-foreground">{page.hint}</span>
                      </span>
                      {isActive && <span className="text-primary"><Icon name="check" size={20} /></span>}
                    </Link>
                  </li>
                );
              })}
          </ul>
        </SheetContent>
      </Sheet>
    </div>
    )}
    {part !== 'phone' && (
    <nav
      aria-label={t('nav.label', 'Calendar sections')}
      data-testid="calendar-nav"
      // One row that scrolls sideways on a phone, like every tab strip, rather
      // than three ragged rows; it wraps from 768px as before.
      className="mb-6 hidden w-full gap-1 border-b border-border md:flex md:flex-wrap"
    >
      {/* Design 3.5 (CV.3): the calendar's gear opens these pages, so the
          calendar itself is a way back rather than a tab. */}
      {active !== 'calendar' && (
        <Link
          href="/calendar"
          data-testid="calendar-nav-back"
          className="-mb-px me-2 inline-flex min-h-10 items-center gap-1 border-e border-border pe-3 text-sm whitespace-nowrap text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
        >
          <Icon name="chevron-left" className="rtl:-scale-x-100" />
          {t('nav.calendar', 'Calendar')}
        </Link>
      )}
      {tabs
        .filter((tab) => tab.show && tab.id !== 'calendar')
        .map((tab) => {
          const isActive = tab.id === active;
          return (
            <Link
              key={tab.id}
              href={tab.href}
              data-testid={`calendar-nav-${tab.id}`}
              aria-current={isActive ? 'page' : undefined}
              className={cn(
                '-mb-px inline-flex min-h-10 items-center border-b-2 px-2.5 text-sm whitespace-nowrap outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring',
                isActive
                  ? 'border-primary font-medium text-primary'
                  : 'border-transparent text-muted-foreground hover:text-foreground'
              )}
            >
              {tab.label}
            </Link>
          );
        })}
    </nav>
    )}
    </>
  );
}
