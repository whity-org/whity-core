'use client';

/**
 * The calendar's bar (design 3.5, CV.3, `TplCalendarWeek`): Today, ‹ ›, the
 * range as a 22px title, Colour by, the view, and the gear holding Services,
 * Rooms and branches, Hours and time off and Settings (the old tabs).
 */

import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { ToggleGroup } from '@whity/ui/toggle-group';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { useTranslation } from '@whity/features/i18n';
import type { ColourBy } from '@/lib/calendar/colours';
import type { CalendarView } from '@/lib/calendar/layout';
import { useCalendarAccess } from './use-calendar-basics';

export interface CalendarBarProps {
  title: string;
  view: CalendarView;
  views: CalendarView[];
  onView: (view: CalendarView) => void;
  colourBy: ColourBy;
  onColourBy: (by: ColourBy) => void;
  onToday: () => void;
  onStep: (direction: 1 | -1) => void;
}

/** The keys each view answers to (CV.7). */
export const VIEW_KEYS: Readonly<Record<string, CalendarView>> = { d: 'day', x: 'four_days', w: 'week', m: 'month', y: 'year' };

export function useViewLabels(): Record<CalendarView, string> {
  const t = useTranslation('calendar');
  return {
    day: t('view.day', 'Day'),
    three_days: t('view.threeDays', '3 days'),
    four_days: t('view.fourDays', '4 days'),
    week: t('view.week', 'Week'),
    month: t('view.month', 'Month'),
    year: t('view.year', 'Year'),
  };
}

export function useColourByLabels(): Record<ColourBy, string> {
  const t = useTranslation('calendar');
  return {
    person: t('colourBy.person', 'Person'),
    service: t('colourBy.service', 'Service'),
    room: t('colourBy.room', 'Room or branch'),
  };
}

export function CalendarSetupMenu() {
  const t = useTranslation('calendar');
  const access = useCalendarAccess();
  const pages = [
    { href: '/calendar/services', label: t('nav.services', 'Services'), icon: 'tool' as const },
    { href: '/calendar/rooms', label: t('nav.rooms', 'Rooms and branches'), icon: 'branch' as const },
    { href: '/calendar/hours', label: t('nav.hours', 'Hours and time off'), icon: 'clock' as const },
    ...(access.canManage ? [{ href: '/calendar/settings', label: t('nav.settings', 'Settings'), icon: 'settings' as const }] : []),
  ];
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          aria-label={t('bar.setup', 'Services, hours and settings')}
          title={t('bar.setup', 'Services, hours and settings')}
          data-testid="calendar-setup"
        >
          <Icon name="settings" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {pages.map((page) => (
          <DropdownMenuItem key={page.href} asChild>
            <Link href={page.href}>
              <Icon name={page.icon} />
              {page.label}
            </Link>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export function ColourByMenu({ value, onChange }: { value: ColourBy; onChange: (by: ColourBy) => void }) {
  const t = useTranslation('calendar');
  const labels = useColourByLabels();
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" data-testid="calendar-colour-by">
          <span className="text-muted-foreground">{t('colourBy.label', 'Colour by')}</span>
          <span className="font-medium">{labels[value]}</span>
          <Icon name="chevron-down" size={12} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-72">
        <DropdownMenuLabel>{t('colourBy.label', 'Colour by')}</DropdownMenuLabel>
        <DropdownMenuRadioGroup value={value} onValueChange={(next) => onChange(next as ColourBy)}>
          {(['person', 'service', 'room'] as const).map((by) => (
            <DropdownMenuRadioItem key={by} value={by}>
              {labels[by]}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <p className="px-2 py-1.5 text-xs text-muted-foreground">
          {t('colourBy.hint', 'Only changes what you see. Everyone else keeps their own choice.')}
        </p>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export function CalendarBar({ title, view, views, onView, colourBy, onColourBy, onToday, onStep }: CalendarBarProps) {
  const t = useTranslation('calendar');
  const labels = useViewLabels();
  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="calendar-bar">
      <Button variant="outline" onClick={onToday} data-testid="calendar-today">
        {t('toolbar.today', 'Today')}
      </Button>
      <Button
        variant="ghost"
        size="icon"
        onClick={() => onStep(-1)}
        aria-label={t('toolbar.previous', 'Previous')}
        title={t('toolbar.previous', 'Previous')}
        data-testid="calendar-previous"
      >
        <Icon name="chevron-left" className="rtl:-scale-x-100" />
      </Button>
      <Button
        variant="ghost"
        size="icon"
        onClick={() => onStep(1)}
        aria-label={t('toolbar.next', 'Next')}
        title={t('toolbar.next', 'Next')}
        data-testid="calendar-next"
      >
        <Icon name="chevron-right" className="rtl:-scale-x-100" />
      </Button>
      <h2 className="mx-2 text-xl leading-tight font-semibold whitespace-nowrap text-foreground" data-testid="calendar-title" aria-live="polite">
        {title}
      </h2>
      <span className="flex-1" />
      <ColourByMenu value={colourBy} onChange={onColourBy} />
      <ToggleGroup
        label={t('toolbar.view', 'View')}
        required
        value={view}
        onChange={(value) => {
          if (typeof value === 'string' && (views as string[]).includes(value)) onView(value as CalendarView);
        }}
        options={views.map((v) => ({ value: v, label: labels[v] }))}
      />
      <CalendarSetupMenu />
    </div>
  );
}
