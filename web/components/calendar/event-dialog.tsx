'use client';

/**
 * Add, change or remove an event that is not a booking (WHIT-944): a meeting,
 * a block of time, a day off for one person. Title, day, start and end (or all
 * day), whether it blocks bookings, and notes.
 *
 * One's own calendar on `calendar:write`; somebody else's needs
 * `calendar:manage`, so the person field is only offered to managers. Someone
 * who can't change the event sees it read-only.
 *
 * Times are picked on the workspace's clock and sent with its offset; an
 * all-day event runs from the first day's midnight to the midnight after the
 * last day, in that zone.
 */

import { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { FieldSelect } from '@/components/field-select';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Switch } from '@whity/ui/switch';
import { Textarea } from '@whity/ui/textarea';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { useTranslation } from '@whity/features/i18n';
import { toZonedIso, zonedDate, zonedInstant } from '@whity/features/datetime';
import { calendarApi, type CalendarEvent, type CalendarStaff } from '@/lib/calendar/api';
import {
  addDays,
  dateInZone,
  dateKey,
  parseDateKey,
  timeFieldValue,
  zonedIsoFor,
  type CalendarDate,
} from '@/lib/calendar/layout';
import { timeOptions, timeToMinute } from '@/lib/calendar/times';
import { useCalendarWords } from './use-calendar-words';

export interface EventDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The event to change; null adds one. */
  event: CalendarEvent | null;
  staff: CalendarStaff[];
  timeZone: string;
  me: number | null;
  canManage: boolean;
  canChange: boolean;
  initialDate?: CalendarDate | null;
  onSaved: () => void;
}

export function EventDialog(props: EventDialogProps) {
  return (
    <Dialog open={props.open} onOpenChange={props.onOpenChange}>
      {props.open && <EventForm {...props} />}
    </Dialog>
  );
}

const STARTS = timeOptions(15);
const ENDS = timeOptions(15, true);

function EventForm({ onOpenChange, event, staff, timeZone, me, canManage, canChange, initialDate = null, onSaved }: EventDialogProps) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();

  const startDate = event ? dateInZone(new Date(event.starts_at), timeZone) : initialDate ?? zonedDate(timeZone);
  // An all-day event ends at the midnight after its last day.
  const lastDate = event
    ? event.all_day
      ? addDays(dateInZone(new Date(event.ends_at), timeZone), -1)
      : dateInZone(new Date(event.ends_at), timeZone)
    : startDate;

  const [title, setTitle] = useState(event?.title ?? '');
  const [person, setPerson] = useState<string>(String(event?.profile_id ?? me ?? ''));
  const [from, setFrom] = useState(dateKey(startDate));
  const [until, setUntil] = useState(dateKey(lastDate));
  const [allDay, setAllDay] = useState(event?.all_day ?? false);
  const [startTime, setStartTime] = useState(event && !event.all_day ? timeFieldValue(event.starts_at, timeZone) : '09:00');
  const [endTime, setEndTime] = useState(event && !event.all_day ? timeFieldValue(event.ends_at, timeZone) : '10:00');
  const [busy, setBusy] = useState(event?.busy ?? true);
  const [notes, setNotes] = useState(event?.notes ?? '');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [removing, setRemoving] = useState(false);

  const readOnly = !canChange;

  const instants = (): { starts_at: string; ends_at: string } | null => {
    const first = parseDateKey(from);
    const last = parseDateKey(until);
    if (first === null || last === null) return null;
    if (allDay) {
      if (dateKey(last) < dateKey(first)) return null;
      return {
        starts_at: toZonedIso(zonedInstant(first, 0, timeZone), timeZone),
        ends_at: toZonedIso(zonedInstant(addDays(last, 1), 0, timeZone), timeZone),
      };
    }
    const start = zonedIsoFor(first, startTime, timeZone);
    const end = zonedIsoFor(last, endTime, timeZone);
    if (start === null || end === null) return null;
    if (new Date(end).getTime() <= new Date(start).getTime()) return null;
    return { starts_at: start, ends_at: end };
  };

  const save = async () => {
    setProblem(null);
    if (title.trim() === '') {
      setProblem(t('event.error.title', 'Give the event a title.'));
      return;
    }
    const when = instants();
    if (when === null) {
      setProblem(t('event.error.order', 'The end must come after the start.'));
      return;
    }
    setSaving(true);
    try {
      const body = {
        title: title.trim(),
        notes: notes.trim(),
        all_day: allDay,
        busy,
        ...when,
        ...(canManage && person !== '' && event === null ? { profile_id: Number(person) } : {}),
      };
      const saved = event ? await calendarApi.updateEvent(event.id, body) : await calendarApi.createEvent(body);
      const overlapping = saved.overlapping_bookings.length;
      if (busy && overlapping > 0) {
        addToast(
          t('event.overlap', 'Saved. It sits over {count} booking(s); they stay booked, so move them if needed.', { count: overlapping }),
          'warning'
        );
      } else {
        addToast(t('event.saved', 'Event saved'), 'success');
      }
      onSaved();
      onOpenChange(false);
    } catch (error) {
      setProblem(words.errorText(error, t('event.error.save', 'The event could not be saved. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (event === null) return;
    try {
      await calendarApi.removeEvent(event.id);
      addToast(t('event.removed', 'Event removed'), 'success');
      onSaved();
      onOpenChange(false);
    } catch (error) {
      setProblem(words.errorText(error, t('event.error.remove', 'The event could not be removed. Try again.')));
    }
  };

  return (
    <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto" data-testid="event-dialog">
      <DialogHeader>
        <DialogTitle>
          {event === null
            ? t('event.new.title', 'Add an event')
            : readOnly
              ? event.title
              : t('event.edit.title', 'Change event')}
        </DialogTitle>
        <DialogDescription>
          {readOnly
            ? t('event.readOnly', 'Only the person whose calendar this is, or someone who manages the calendar, can change it.')
            : t('event.description', 'Time that is not a booking, such as a meeting or a break.')}
        </DialogDescription>
      </DialogHeader>

      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          void save();
        }}
      >
        <fieldset disabled={readOnly || saving} className="space-y-4">
          <Input
            id="event-title"
            label={t('event.field.title', 'Title')}
            required
            maxLength={200}
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder={t('event.field.titlePlaceholder', 'Stock count')}
          />

          {canManage && event === null && staff.length > 0 && (
            <div className="space-y-1">
              <label htmlFor="event-person" className="text-sm font-medium text-foreground">
                {t('event.field.person', 'Whose calendar')}
              </label>
              <FieldSelect
                id="event-person"
                value={person}
                onChange={setPerson}
                options={staff.map((s) => ({ value: String(s.profile_id), label: s.display_name }))}
              />
            </div>
          )}

          <label className="flex min-h-10 items-center justify-between gap-3">
            <span className="text-sm font-medium text-foreground">{t('event.field.allDay', 'All day')}</span>
            <Switch checked={allDay} onCheckedChange={setAllDay} aria-label={t('event.field.allDay', 'All day')} />
          </label>

          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              id="event-from"
              type="date"
              label={allDay ? t('event.field.firstDay', 'First day') : t('event.field.day', 'Day')}
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                if (!allDay || until < e.target.value) setUntil(e.target.value);
              }}
            />
            {allDay && (
              <Input
                id="event-until"
                type="date"
                label={t('event.field.lastDay', 'Last day')}
                value={until}
                onChange={(e) => setUntil(e.target.value)}
              />
            )}
          </div>

          {!allDay && (
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-1">
                <label htmlFor="event-start" className="text-sm font-medium text-foreground">
                  {t('event.field.start', 'Starts')}
                </label>
                <FieldSelect
                  id="event-start"
                  value={startTime}
                  onChange={(value) => {
                    setStartTime(value);
                    const start = timeToMinute(value);
                    const end = timeToMinute(endTime);
                    if (start !== null && end !== null && end <= start) {
                      setEndTime(ENDS[Math.min(ENDS.indexOf(value) + 4, ENDS.length - 1)]);
                    }
                  }}
                  options={STARTS.map((v) => ({ value: v, label: v }))}
                />
              </div>
              <div className="space-y-1">
                <label htmlFor="event-end" className="text-sm font-medium text-foreground">
                  {t('event.field.end', 'Ends')}
                </label>
                <FieldSelect
                  id="event-end"
                  value={endTime}
                  onChange={setEndTime}
                  options={ENDS.map((v) => ({ value: v, label: v }))}
                />
              </div>
            </div>
          )}

          <label className="flex min-h-10 items-start justify-between gap-3">
            <span className="space-y-0.5">
              <span className="block text-sm font-medium text-foreground">{t('event.field.busy', 'Blocks bookings')}</span>
              <span className="block text-sm text-muted-foreground">
                {t('event.field.busyHint', 'Customers can’t book this person while it is on.')}
              </span>
            </span>
            <Switch checked={busy} onCheckedChange={setBusy} aria-label={t('event.field.busy', 'Blocks bookings')} />
          </label>

          <div className="space-y-1">
            <label htmlFor="event-notes" className="text-sm font-medium text-foreground">
              {t('event.field.notes', 'Notes')}
            </label>
            <Textarea id="event-notes" rows={3} maxLength={5000} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>
        </fieldset>

        {problem && (
          <Alert variant="destructive" data-testid="event-problem">
            <AlertDescription>{problem}</AlertDescription>
          </Alert>
        )}

        <DialogFooter className="gap-2">
          {event !== null && !readOnly && (
            <Button type="button" variant="outline" className="sm:me-auto" onClick={() => setRemoving(true)} data-testid="event-remove">
              {t('event.remove', 'Remove event')}
            </Button>
          )}
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {readOnly ? t('action.close', 'Close') : t('action.cancel', 'Cancel')}
          </Button>
          {!readOnly && (
            <Button type="submit" loading={saving} data-testid="event-save">
              {event === null ? t('event.new.submit', 'Add event') : t('event.edit.submit', 'Save event')}
            </Button>
          )}
        </DialogFooter>
      </form>

      <ConfirmAction
        open={removing}
        onOpenChange={setRemoving}
        title={t('event.removeTitle', 'Remove “{title}”?', { title: event?.title ?? '' })}
        description={t('event.removeDescription', 'It disappears from the calendar and its time is free for bookings again. This can’t be undone.')}
        confirmLabel={t('event.remove', 'Remove event')}
        cancelLabel={t('event.keep', 'Keep event')}
        variant="destructive"
        onConfirm={async () => {
          setRemoving(false);
          await remove();
        }}
      />
    </DialogContent>
  );
}
