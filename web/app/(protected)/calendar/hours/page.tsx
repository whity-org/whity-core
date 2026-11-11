'use client';

/**
 * /calendar/hours (WHIT-944): a person's weekly working hours and their time
 * off. One's own on `calendar:write`; anyone's on `calendar:manage`, who can
 * also add a member of the team to the calendar and choose whether customers
 * can book them online.
 *
 * HOURS ARE WALL-CLOCK TIME in the workspace's zone ("Sunday 09:00 to 17:00"),
 * not instants, so they need no zone arithmetic here (ADR 0017 section 2). A
 * day can have several ranges: a lunch break is two. The week is listed from
 * the workspace's own first day. Edited in place; the save bar replaces the
 * whole week in one request, as the API does.
 *
 * TIME OFF is instants: picked on the workspace's clock and sent with its
 * offset. The list covers the next 60 days (the API answers at most 62 at a
 * time).
 */

import { useMemo, useState } from 'react';
import { AdminHeader } from '@/components/admin/admin-header';
import { Input } from '@/components/ui/input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { FieldSelect } from '@/components/field-select';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { CalendarNav } from '@/components/calendar/calendar-nav';
import { useCalendarAccess, useCalendarBasics } from '@/components/calendar/use-calendar-basics';
import { useCalendarWords } from '@/components/calendar/use-calendar-words';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { apiClient } from '@/lib/api-client';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { Icon } from '@whity/ui/icon';
import { AreaError, EmptyFirstTime, ReadOnlyNotice } from '@whity/ui/page-states';
import { RadioGroup, RadioGroupItem } from '@whity/ui/radio-group';
import { SaveBar } from '@whity/ui/save-bar';
import { Skeleton } from '@whity/ui/skeleton';
import { Switch } from '@whity/ui/switch';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation } from '@whity/features/i18n';
import { toZonedIso, useDateDisplay, zonedDate, zonedInstant } from '@whity/features/datetime';
import { calendarApi, type CalendarStaff, type CalendarTimeOff } from '@/lib/calendar/api';
import { addDays, dateKey, isoWeekdayOf, parseDateKey, rangeQuery, zonedIsoFor } from '@/lib/calendar/layout';
import { firstBadDay, rowsOf, weekOf, weekOrder, type HoursRange, type HoursWeek } from '@/lib/calendar/hours';
import { timeOptions, timeToMinute } from '@/lib/calendar/times';
import { ForbiddenError } from '@/lib/forbidden';

const STARTS = timeOptions(15);
const ENDS = timeOptions(15, true);

type Range = HoursRange;
type Week = HoursWeek;

export default function CalendarHoursPage() {
  const t = useTranslation('calendar');
  const access = useCalendarAccess();
  const { user } = useAuth();
  const basics = useCalendarBasics();
  const [chosen, setChosen] = useState<string>('');
  const [adding, setAdding] = useState(false);

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('hours.area', 'working hours')} permission="calendar:read" />;
  }

  const staff = basics.data?.staff ?? [];
  // Everyone with a calendar, and oneself, who gets a calendar on first save.
  const people: CalendarStaff[] =
    access.me !== null && !staff.some((s) => s.profile_id === access.me)
      ? [
          ...staff,
          {
            profile_id: access.me,
            display_name: user?.display_name || user?.email || t('hours.me', 'Me'),
            bookable: false,
            sort_order: 0,
            feed_enabled: false,
          },
        ]
      : staff;
  const defaultPerson = access.me !== null ? String(access.me) : people[0] ? String(people[0].profile_id) : '';
  const personId = chosen !== '' ? chosen : defaultPerson;
  const person = people.find((p) => String(p.profile_id) === personId) ?? null;
  const visible = access.canManage ? people : people.filter((p) => p.profile_id === access.me);

  return (
    <div className="space-y-4 pb-16">
      <AdminHeader
        title={t('hours.title', 'Hours and time off')}
        description={t('hours.description', 'When each person works, and the days they are away. Bookings are only offered inside working hours.')}
      />
      <CalendarNav active="hours" />

      {basics.error ? (
        <LoadProblem
          forbidden={basics.forbidden}
          message={t('hours.error.load', 'Working hours could not be loaded.')}
          onRetry={basics.refetch}
          area={t('hours.area', 'working hours')}
        />
      ) : basics.data === null ? (
        <div role="status" aria-busy="true" aria-label={t('loading.hours', 'Loading working hours')} className="space-y-3">
          <Skeleton className="h-10 w-64 rounded-control" />
          <Skeleton className="h-80 w-full rounded-card" />
        </div>
      ) : person === null ? (
        <EmptyFirstTime
          icon="clock"
          title={t('hours.empty.title', 'Nobody has a calendar yet')}
          description={t('hours.empty.description', 'Add the people customers can book, then set their hours.')}
          action={
            access.canManage ? (
              <Button onClick={() => setAdding(true)}>{t('hours.addPerson', 'Add a person to the calendar')}</Button>
            ) : undefined
          }
        />
      ) : (
        <>
          <div className="flex flex-wrap items-end gap-3">
            {visible.length > 1 && (
              <div className="w-full sm:w-72">
                <label htmlFor="hours-person" className="mb-1 block text-sm font-medium text-foreground">
                  {t('hours.person', 'Person')}
                </label>
                <FieldSelect
                  id="hours-person"
                  value={personId}
                  onChange={setChosen}
                  options={visible.map((p) => ({ value: String(p.profile_id), label: p.display_name }))}
                />
              </div>
            )}
            {access.canManage && (
              <Button variant="outline" onClick={() => setAdding(true)} data-testid="hours-add-person">
                <Icon name="plus" />
                {t('hours.addPerson', 'Add a person to the calendar')}
              </Button>
            )}
          </div>

          {!access.loading && !access.canChange(person.profile_id) && (
            <ReadOnlyNotice
              title={t('hours.readOnly.title', 'You can see these hours but not change them')}
              description={
                access.canWrite
                  ? t('hours.readOnly.other', 'You can change your own. Someone who manages the calendar changes other people’s.')
                  : t('hours.readOnly.none', 'Ask someone who manages the calendar if you need to change hours or time off.')
              }
            />
          )}

          {access.canManage && staff.some((s) => s.profile_id === person.profile_id) && (
            <OnlineBookingCard person={person} onChanged={basics.refetch} />
          )}

          <HoursEditor
            key={`hours-${person.profile_id}`}
            profileId={person.profile_id}
            name={person.display_name}
            weekStart={basics.data.settings.week_start}
            canChange={access.canChange(person.profile_id)}
            onSaved={basics.refetch}
          />

          <TimeOffList
            key={`off-${person.profile_id}`}
            profileId={person.profile_id}
            timeZone={basics.data.timeZone}
            canChange={access.canChange(person.profile_id)}
          />
        </>
      )}

      <AddPersonDialog
        open={adding}
        onOpenChange={setAdding}
        existing={staff.map((s) => s.profile_id)}
        onAdded={(profileId) => {
          setAdding(false);
          setChosen(String(profileId));
          basics.refetch();
        }}
      />
    </div>
  );
}

function OnlineBookingCard({ person, onChanged }: { person: CalendarStaff; onChanged: () => void }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const [saving, setSaving] = useState(false);

  return (
    <Card>
      <CardContent className="pt-6">
        <label className="flex min-h-10 items-start justify-between gap-3">
          <span className="space-y-0.5">
            <span className="block text-sm font-medium text-foreground">
              {t('hours.bookable', 'Customers can book {name} online', { name: person.display_name })}
            </span>
            <span className="block text-sm text-muted-foreground">
              {t('hours.bookableHint', 'When off, only the team can book them, at the desk.')}
            </span>
          </span>
          <Switch
            checked={person.bookable}
            disabled={saving}
            aria-label={t('hours.bookable', 'Customers can book {name} online', { name: person.display_name })}
            onCheckedChange={async (bookable) => {
              setSaving(true);
              try {
                await calendarApi.saveStaff(person.profile_id, { bookable });
                onChanged();
              } catch (error) {
                addToast(words.errorText(error, t('hours.error.bookable', 'That could not be changed. Try again.')), 'error');
              } finally {
                setSaving(false);
              }
            }}
          />
        </label>
      </CardContent>
    </Card>
  );
}

function HoursEditor({
  profileId,
  name,
  weekStart,
  canChange,
  onSaved,
}: {
  profileId: number;
  name: string;
  weekStart: string;
  canChange: boolean;
  onSaved: () => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const { data, error, forbidden, refetch } = useFetch(() => calendarApi.hours(profileId), [profileId]);
  const [draft, setDraft] = useState<Week | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const saved = useMemo(() => (data ? weekOf(data) : null), [data]);
  const current = draft ?? saved;
  const order = weekOrder(isoWeekdayOf(weekStart));
  const dirty = saved !== null && current !== null && JSON.stringify(rowsOf(current)) !== JSON.stringify(rowsOf(saved));

  const setDay = (day: number, ranges: Range[]) => {
    if (current === null) return;
    setDraft({ ...current, [day]: ranges });
    setProblem(null);
  };

  const save = async () => {
    if (current === null) return;
    const bad = firstBadDay(current);
    if (bad !== null) {
      setProblem(t('hours.error.overlap', 'On {day}, each end must come after its start, and the hours must not overlap.', { day: words.weekday(bad) }));
      return;
    }
    setSaving(true);
    try {
      await calendarApi.saveHours(profileId, rowsOf(current));
      setDraft(null);
      addToast(t('hours.saved', 'Working hours saved'), 'success');
      refetch();
      onSaved();
    } catch (err) {
      setProblem(words.errorText(err, t('hours.error.save', 'The working hours could not be saved. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card data-testid="hours-editor">
      <CardHeader>
        <CardTitle>{t('hours.week.title', 'Weekly hours')}</CardTitle>
        <CardDescription>
          {t('hours.week.description', '{name}’s usual week, on the workspace’s clock. Add a second range for a break.', { name })}
        </CardDescription>
      </CardHeader>
      <CardContent>
        {error ? (
          <LoadProblem
            forbidden={forbidden}
            message={t('hours.error.load', 'Working hours could not be loaded.')}
            onRetry={refetch}
            area={t('hours.area', 'working hours')}
          />
        ) : current === null || saved === null ? (
          <Skeleton className="h-72 w-full rounded-card" />
        ) : (
          <ul className="divide-y divide-divider">
            {order.map((day) => {
              const ranges = current[day] ?? [];
              return (
                <li key={day} className="grid gap-2 py-3 sm:grid-cols-[9rem_1fr]" data-testid={`hours-day-${day}`}>
                  <span className="pt-2 text-sm font-medium text-foreground">{words.weekday(day)}</span>
                  <div className="space-y-2">
                    {ranges.length === 0 && (
                      <p className="flex min-h-10 items-center text-sm text-muted-foreground">{t('hours.closed', 'Not working')}</p>
                    )}
                    {ranges.map((range, index) => (
                      <div key={index} className="flex flex-wrap items-center gap-2">
                        <div className="w-28">
                          <FieldSelect
                            id={`hours-${day}-${index}-start`}
                            value={range.start}
                            disabled={!canChange}
                            onChange={(start) => setDay(day, ranges.map((r, i) => (i === index ? { ...r, start } : r)))}
                            options={STARTS.map((v) => ({ value: v, label: v }))}
                          />
                        </div>
                        <span className="text-sm text-muted-foreground">{t('hours.to', 'to')}</span>
                        <div className="w-28">
                          <FieldSelect
                            id={`hours-${day}-${index}-end`}
                            value={range.end}
                            disabled={!canChange}
                            onChange={(end) => setDay(day, ranges.map((r, i) => (i === index ? { ...r, end } : r)))}
                            options={ENDS.map((v) => ({ value: v, label: v }))}
                          />
                        </div>
                        {canChange && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setDay(day, ranges.filter((_, i) => i !== index))}
                          >
                            {t('hours.removeRange', 'Remove')}
                          </Button>
                        )}
                      </div>
                    ))}
                    {canChange && (
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-testid={`hours-add-${day}`}
                        onClick={() => {
                          const last = ranges[ranges.length - 1];
                          const start = last ? last.end : '09:00';
                          const startMinute = timeToMinute(start) ?? 540;
                          const end = ENDS[Math.min(ENDS.indexOf(start) + 16, ENDS.length - 1)] ?? '24:00';
                          setDay(day, [...ranges, { start: startMinute >= 1440 ? '23:00' : start, end }]);
                        }}
                      >
                        <Icon name="plus" />
                        {ranges.length === 0 ? t('hours.addHours', 'Add hours') : t('hours.addRange', 'Add another range')}
                      </Button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
        {problem && (
          <Alert variant="destructive" className="mt-3" data-testid="hours-problem">
            <AlertDescription>{problem}</AlertDescription>
          </Alert>
        )}
        <SaveBar
          dirty={dirty}
          summary={t('hours.changed', 'Weekly hours changed')}
          discardLabel={t('action.discard', 'Discard')}
          saveLabel={t('action.save', 'Save changes')}
          saving={saving}
          onDiscard={() => {
            setDraft(null);
            setProblem(null);
          }}
          onSave={() => void save()}
        />
      </CardContent>
    </Card>
  );
}

function TimeOffList({ profileId, timeZone, canChange }: { profileId: number; timeZone: string; canChange: boolean }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const { addToast } = useToast();
  const [adding, setAdding] = useState(false);
  const [removing, setRemoving] = useState<CalendarTimeOff | null>(null);

  const today = zonedDate(timeZone);
  const range = rangeQuery({ first: today, end: addDays(today, 60) }, timeZone);
  const { data, loading, error, forbidden, refetch } = useFetch(
    () => calendarApi.timeOff({ ...range, profileId }),
    [profileId, range.from, range.to]
  );

  return (
    <Card data-testid="time-off">
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
        <div className="space-y-1.5">
          <CardTitle>{t('timeOff.title', 'Time off and extra hours')}</CardTitle>
          <CardDescription>{t('timeOff.description', 'The next 60 days. Time off stops bookings; extra hours open a day that is normally closed.')}</CardDescription>
        </div>
        {canChange && (
          <Button variant="outline" onClick={() => setAdding(true)} data-testid="time-off-add">
            <Icon name="plus" />
            {t('timeOff.add', 'Add time off')}
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {error ? (
          forbidden ? (
            <LoadProblem forbidden message="" onRetry={refetch} area={t('timeOff.area', 'time off')} />
          ) : (
            <AreaError message={t('timeOff.error.load', 'Time off could not be loaded.')} retryLabel={t('action.retry', 'Try again')} onRetry={refetch} />
          )
        ) : loading && data === null ? (
          <Skeleton className="h-24 w-full rounded-card" />
        ) : (data ?? []).length === 0 ? (
          <p className="py-4 text-sm text-muted-foreground" data-testid="time-off-empty">
            {t('timeOff.empty', 'No time off in the next 60 days.')}
          </p>
        ) : (
          <ul className="divide-y divide-divider">
            {(data ?? []).map((entry) => (
              <li key={entry.id} className="flex min-h-12 flex-wrap items-center gap-3 py-2" data-testid="time-off-entry">
                <Badge variant={entry.kind === 'off' ? 'warning' : 'info'}>
                  {entry.kind === 'off' ? t('timeOff.kind.off', 'Time off') : t('timeOff.kind.open', 'Extra hours')}
                </Badge>
                <span className="min-w-0 flex-1 text-sm text-foreground">
                  {dates.hidden ? null : (
                    <span className="tabular-nums">
                      {t('timeOff.range', '{from} to {to}', {
                        from: dates.dateTime(entry.starts_at) ?? '—',
                        to: dates.dateTime(entry.ends_at) ?? '—',
                      })}
                    </span>
                  )}
                  {entry.note && <span className="block text-muted-foreground">{entry.note}</span>}
                </span>
                {canChange && (
                  <Button variant="ghost" size="sm" onClick={() => setRemoving(entry)}>
                    {t('timeOff.remove', 'Remove')}
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardContent>

      <AddTimeOffDialog
        open={adding}
        onOpenChange={setAdding}
        profileId={profileId}
        timeZone={timeZone}
        onAdded={() => {
          setAdding(false);
          addToast(t('timeOff.added', 'Time off added'), 'success');
          refetch();
        }}
      />

      <ConfirmAction
        open={removing !== null}
        onOpenChange={(open) => {
          if (!open) setRemoving(null);
        }}
        title={t('timeOff.removeTitle', 'Remove this time off?')}
        description={t('timeOff.removeDescription', 'Those hours become bookable again. This can’t be undone; add it again if you need to.')}
        confirmLabel={t('timeOff.remove', 'Remove')}
        cancelLabel={t('timeOff.keep', 'Keep it')}
        variant="destructive"
        onConfirm={async () => {
          const entry = removing;
          setRemoving(null);
          if (entry === null) return;
          try {
            await calendarApi.removeTimeOff(entry.id);
            addToast(t('timeOff.removed', 'Time off removed'), 'success');
            refetch();
          } catch (err) {
            addToast(words.errorText(err, t('timeOff.error.remove', 'The time off could not be removed. Try again.')), 'error');
          }
        }}
      />
    </Card>
  );
}

function AddTimeOffDialog({
  open,
  onOpenChange,
  profileId,
  timeZone,
  onAdded,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  profileId: number;
  timeZone: string;
  onAdded: () => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const today = dateKey(zonedDate(timeZone));
  const [kind, setKind] = useState<'off' | 'open'>('off');
  const [allDay, setAllDay] = useState(true);
  const [from, setFrom] = useState(today);
  const [until, setUntil] = useState(today);
  const [startTime, setStartTime] = useState('09:00');
  const [endTime, setEndTime] = useState('17:00');
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async () => {
    const first = parseDateKey(from);
    const last = parseDateKey(until);
    if (first === null || last === null) {
      setProblem(t('timeOff.error.dates', 'Choose the first and last day.'));
      return;
    }
    const startsAt = allDay ? toZonedIso(zonedInstant(first, 0, timeZone), timeZone) : zonedIsoFor(first, startTime, timeZone);
    const endsAt = allDay ? toZonedIso(zonedInstant(addDays(last, 1), 0, timeZone), timeZone) : zonedIsoFor(last, endTime, timeZone);
    if (startsAt === null || endsAt === null || new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
      setProblem(t('event.error.order', 'The end must come after the start.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      await calendarApi.addTimeOff({ profile_id: profileId, kind, starts_at: startsAt, ends_at: endsAt, note: note.trim() });
      setNote('');
      onAdded();
    } catch (err) {
      setProblem(words.errorText(err, t('timeOff.error.add', 'The time off could not be added. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg" data-testid="time-off-dialog">
        <DialogHeader>
          <DialogTitle>{t('timeOff.add', 'Add time off')}</DialogTitle>
          <DialogDescription>{t('timeOff.addDescription', 'Times are on the workspace’s clock.')}</DialogDescription>
        </DialogHeader>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            void save();
          }}
        >
          <RadioGroup value={kind} onValueChange={(value) => setKind(value === 'open' ? 'open' : 'off')} className="space-y-2">
            <label htmlFor="time-off-kind-off" className="flex min-h-10 cursor-pointer items-center gap-3">
              <RadioGroupItem id="time-off-kind-off" value="off" />
              <span className="text-sm text-foreground">{t('timeOff.kind.offHint', 'Time off: no bookings then')}</span>
            </label>
            <label htmlFor="time-off-kind-open" className="flex min-h-10 cursor-pointer items-center gap-3">
              <RadioGroupItem id="time-off-kind-open" value="open" />
              <span className="text-sm text-foreground">{t('timeOff.kind.openHint', 'Extra hours: bookable although normally closed')}</span>
            </label>
          </RadioGroup>
          <label className="flex min-h-10 items-center justify-between gap-3">
            <span className="text-sm font-medium text-foreground">{t('event.field.allDay', 'All day')}</span>
            <Switch checked={allDay} onCheckedChange={setAllDay} aria-label={t('event.field.allDay', 'All day')} />
          </label>
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              id="time-off-from"
              type="date"
              label={t('event.field.firstDay', 'First day')}
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                if (until < e.target.value) setUntil(e.target.value);
              }}
            />
            <Input id="time-off-until" type="date" label={t('event.field.lastDay', 'Last day')} value={until} onChange={(e) => setUntil(e.target.value)} />
          </div>
          {!allDay && (
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-1">
                <label htmlFor="time-off-start" className="text-sm font-medium text-foreground">
                  {t('timeOff.field.from', 'From, on the first day')}
                </label>
                <FieldSelect id="time-off-start" value={startTime} onChange={setStartTime} options={STARTS.map((v) => ({ value: v, label: v }))} />
              </div>
              <div className="space-y-1">
                <label htmlFor="time-off-end" className="text-sm font-medium text-foreground">
                  {t('timeOff.field.until', 'Until, on the last day')}
                </label>
                <FieldSelect id="time-off-end" value={endTime} onChange={setEndTime} options={ENDS.map((v) => ({ value: v, label: v }))} />
              </div>
            </div>
          )}
          <div className="space-y-1">
            <label htmlFor="time-off-note" className="text-sm font-medium text-foreground">
              {t('timeOff.field.note', 'Note for the team (optional)')}
            </label>
            <Textarea id="time-off-note" rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
          </div>
          {problem && (
            <Alert variant="destructive">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button type="submit" loading={saving}>
              {t('timeOff.save', 'Add')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** A workspace member, from the People list (needs `users:read`). */
interface Member {
  id: number;
  name: string;
  email?: string | null;
}

function AddPersonDialog({
  open,
  onOpenChange,
  existing,
  onAdded,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  existing: number[];
  onAdded: (profileId: number) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const [chosen, setChosen] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const { data, error, forbidden } = useFetch<Member[]>(async () => {
    if (!open) return [];
    const res = await apiClient('/api/v1/users?per_page=100');
    if (res.status === 403) throw new ForbiddenError();
    if (!res.ok) throw new Error('members');
    return ((await res.json()) as { data?: Member[] }).data ?? [];
  }, [open]);

  const candidates = (data ?? []).filter((m) => !existing.includes(m.id));

  const add = async () => {
    if (chosen === '') {
      setProblem(t('hours.addPerson.missing', 'Choose a person.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      await calendarApi.saveStaff(Number(chosen), {});
      setChosen('');
      onAdded(Number(chosen));
    } catch (err) {
      setProblem(words.errorText(err, t('hours.addPerson.error', 'That person could not be added. Only members of the team can have a calendar.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-testid="add-person-dialog">
        <DialogHeader>
          <DialogTitle>{t('hours.addPerson', 'Add a person to the calendar')}</DialogTitle>
          <DialogDescription>
            {t('hours.addPerson.description', 'They get a calendar the team can book. Set their hours next.')}
          </DialogDescription>
        </DialogHeader>
        {error ? (
          <p className="text-sm text-muted-foreground">
            {forbidden
              ? t('hours.addPerson.noPeople', 'You can’t see the list of people in this workspace. Ask an owner to add them, or to give you access to People.')
              : t('hours.addPerson.loadError', 'The list of people could not be loaded.')}
          </p>
        ) : data === null ? (
          <Skeleton className="h-10 w-full rounded-control" />
        ) : candidates.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('hours.addPerson.everyone', 'Everyone in the team already has a calendar.')}</p>
        ) : (
          <div className="space-y-1">
            <label htmlFor="add-person" className="text-sm font-medium text-foreground">
              {t('hours.person', 'Person')}
            </label>
            <FieldSelect
              id="add-person"
              value={chosen}
              onChange={setChosen}
              placeholder={t('hours.addPerson.placeholder', 'Choose a person')}
              options={candidates.map((m) => ({ value: String(m.id), label: m.name }))}
            />
          </div>
        )}
        {problem && (
          <Alert variant="destructive">
            <AlertDescription>{problem}</AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button onClick={() => void add()} loading={saving} disabled={candidates.length === 0}>
            {t('hours.addPerson.submit', 'Add to the calendar')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
