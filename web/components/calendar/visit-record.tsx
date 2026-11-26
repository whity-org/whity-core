'use client';

/**
 * On a booking (WHIT-1443, design section 39 §7.2 and §7.3):
 *
 * - **Intake forms** (AP.20): each form the booking asks for, done or not
 *   done, with Send again (emails the customer) and Fill in at the desk (the
 *   form on a tablet, in kiosk mode). A missing form never blocks check-in.
 * - **The visit record** (AP.21): the notes of the person treating the
 *   customer, the forms sent for it with their printouts, and files. Shown
 *   only to the treating person and to whoever was granted
 *   `calendar:visit_notes`; anyone else sees nothing, not even that it exists.
 *   Finish visit signs the notes; after it only dated corrections are added.
 *   Never shown to customers. Stored in the EU.
 */

import { useRef, useState } from 'react';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Textarea } from '@whity/ui/textarea';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { calendarApi, type CalendarBooking, type CalendarVisitRecord } from '@/lib/calendar/api';
import { useCalendarWords } from './use-calendar-words';

export function IntakeSection({ booking, canChange }: { booking: CalendarBooking; canChange: boolean }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const [busy, setBusy] = useState<number | null>(null);
  const forms = booking.intake?.forms ?? [];
  if (forms.length === 0) return null;

  const sendAgain = async (formId: number) => {
    setBusy(formId);
    try {
      await calendarApi.intakeLink(booking.id, formId, true);
      addToast(t('intake.sent', 'Sent to {name}', { name: booking.customer.name ?? '' }), 'success');
    } catch (error) {
      addToast(words.errorText(error, t('intake.error', 'The form could not be sent. Try again.')), 'error');
    } finally {
      setBusy(null);
    }
  };
  const atTheDesk = async (formId: number) => {
    setBusy(formId);
    try {
      const link = await calendarApi.intakeLink(booking.id, formId, false);
      if (link.url) window.open(link.url, '_blank', 'noopener');
    } catch (error) {
      addToast(words.errorText(error, t('intake.errorOpen', 'The form could not be opened. Try again.')), 'error');
    } finally {
      setBusy(null);
    }
  };

  return (
    <section className="space-y-2" data-testid="booking-intake">
      <h3 className="m-0 text-sm font-semibold text-foreground">{t('intake.title', 'Forms to fill')}</h3>
      <ul className="m-0 grid gap-2 p-0">
        {forms.map((form) => (
          <li key={form.form_id} className="flex flex-wrap items-center gap-2">
            <span className="min-w-0 flex-1 text-sm text-foreground">{words.label(form.names ?? { en: form.name })}</span>
            <Badge variant={form.done ? 'success' : form.required ? 'warning' : 'outline'}>
              {form.done ? t('intake.done', 'Done') : t('intake.notDone', 'Not done')}
            </Badge>
            {canChange && !form.done && (
              <>
                <Button variant="outline" size="sm" loading={busy === form.form_id} onClick={() => void sendAgain(form.form_id)} data-testid={`intake-send-${form.form_id}`}>
                  {t('intake.sendAgain', 'Send again')}
                </Button>
                <Button variant="ghost" size="sm" disabled={busy === form.form_id} onClick={() => void atTheDesk(form.form_id)} data-testid={`intake-desk-${form.form_id}`}>
                  {t('intake.atDesk', 'Fill in at the desk')}
                </Button>
              </>
            )}
          </li>
        ))}
      </ul>
      <p className="m-0 text-sm text-muted-foreground">{t('intake.neverBlocks', 'A missing form never stops check-in.')}</p>
    </section>
  );
}

export function VisitRecordSection({ booking, timeZone }: { booking: CalendarBooking; timeZone: string }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const { addToast } = useToast();
  const { data, forbidden, refetch } = useFetch(() => calendarApi.visit(booking.id), [booking.id]);
  const [draft, setDraft] = useState<string | null>(null);
  const [correction, setCorrection] = useState('');
  const [saving, setSaving] = useState(false);
  const [finishing, setFinishing] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);

  // Nobody else learns the record exists; anything that isn't a record shows nothing.
  if (forbidden || data === null || data === undefined || !Array.isArray(data.corrections) || !Array.isArray(data.files) || !Array.isArray(data.forms)) return null;
  const record: CalendarVisitRecord = data;
  const notes = draft ?? record.notes?.body ?? '';

  const act = async (work: () => Promise<unknown>, done: string, failed: string) => {
    setSaving(true);
    try {
      await work();
      addToast(done, 'success');
      setDraft(null);
      setCorrection('');
      refetch();
    } catch (error) {
      addToast(words.errorText(error, failed), 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="space-y-3 rounded-card border border-border px-4 py-3" data-testid="visit-record">
      <div className="flex items-center justify-between gap-2">
        <h3 className="m-0 text-sm font-semibold text-foreground">{t('visit.title', 'Visit record')}</h3>
        {record.finished && <Badge variant="success">{t('visit.finished', 'Finished')}</Badge>}
      </div>
      <p className="m-0 text-sm text-muted-foreground">{t('visit.private', 'Only the person treating them and people allowed to see visit records see this. Never shown to the customer.')}</p>

      {record.finished ? (
        <div className="space-y-1" data-testid="visit-notes">
          <p className="m-0 whitespace-pre-wrap text-sm text-foreground">{record.notes?.body}</p>
          <p className="m-0 text-sm text-muted-foreground">
            {t('visit.signed', 'Finished by {name} on {when}', {
              name: record.notes?.finished_by?.name ?? '',
              when: dates.dateTime(record.notes?.finished_at ?? null) ?? '—',
            })}
          </p>
        </div>
      ) : (
        <Textarea
          aria-label={t('visit.notes', 'Visit notes')}
          value={notes}
          maxLength={20000}
          rows={5}
          onChange={(e) => setDraft(e.target.value)}
          data-testid="visit-notes-input"
        />
      )}

      {record.corrections.length > 0 && (
        <ul className="m-0 grid gap-2 border-s-2 border-border ps-3" data-testid="visit-corrections">
          {record.corrections.map((c) => (
            <li key={c.id} className="text-sm">
              <p className="m-0 whitespace-pre-wrap text-foreground">{c.body}</p>
              <p className="m-0 text-muted-foreground">
                {t('visit.correctionBy', 'Correction by {name} on {when}', { name: c.author?.name ?? '', when: dates.dateTime(c.created_at) ?? '—' })}
              </p>
            </li>
          ))}
        </ul>
      )}

      {record.forms.length > 0 && (
        <ul className="m-0 grid gap-1 p-0" data-testid="visit-forms">
          {record.forms.map((f) => (
            <li key={f.id} className="flex flex-wrap items-center gap-2 text-sm">
              <span className="min-w-0 flex-1 text-foreground">{words.label(f.names ?? { en: f.name })}</span>
              <a className="font-medium text-primary hover:underline" href={f.printout_path} target="_blank" rel="noopener">
                {t('visit.printout', 'Printout')}
              </a>
            </li>
          ))}
        </ul>
      )}

      {record.files.length > 0 && (
        <ul className="m-0 grid gap-1 p-0" data-testid="visit-files">
          {record.files.map((f) => (
            <li key={f.id} className="text-sm">
              <a className="font-medium text-primary hover:underline" href={calendarApi.visitFileUrl(f.id)}>
                {f.filename}
              </a>
              <span className="text-muted-foreground"> · {f.added_by?.name ?? ''}</span>
            </li>
          ))}
        </ul>
      )}

      {record.finished && (
        <Textarea
          aria-label={t('visit.correction', 'Add a correction')}
          placeholder={t('visit.correctionHint', 'What to add or put right. It is dated and signed; the notes stay as they were.')}
          value={correction}
          rows={3}
          onChange={(e) => setCorrection(e.target.value)}
          data-testid="visit-correction-input"
        />
      )}

      <div className="flex flex-wrap gap-2">
        {record.finished ? (
          <Button
            variant="outline"
            size="sm"
            loading={saving}
            disabled={correction.trim() === ''}
            onClick={() => void act(() => calendarApi.correctVisit(booking.id, correction), t('visit.corrected', 'Correction added'), t('visit.error.correct', 'The correction could not be added. Try again.'))}
            data-testid="visit-correct"
          >
            {t('visit.addCorrection', 'Add correction')}
          </Button>
        ) : (
          <>
            <Button
              variant="outline"
              size="sm"
              loading={saving}
              disabled={draft === null}
              onClick={() => void act(() => calendarApi.saveVisitNotes(booking.id, notes), t('visit.saved', 'Notes saved'), t('visit.error.save', 'The notes could not be saved. Try again.'))}
              data-testid="visit-save"
            >
              {t('visit.save', 'Save notes')}
            </Button>
            <Button variant="outline" size="sm" disabled={saving || (record.notes === null && draft === null)} onClick={() => setFinishing(true)} data-testid="visit-finish">
              {t('visit.finish', 'Finish visit')}
            </Button>
          </>
        )}
        <Button variant="ghost" size="sm" disabled={saving} onClick={() => fileInput.current?.click()} data-testid="visit-add-file">
          {t('visit.addFile', 'Add a file')}
        </Button>
        <input
          ref={fileInput}
          type="file"
          className="hidden"
          accept="application/pdf,image/jpeg,image/png,image/webp,image/heic,text/plain"
          onChange={(e) => {
            const file = e.target.files?.[0];
            e.target.value = '';
            if (file) void act(() => calendarApi.addVisitFile(booking.id, file), t('visit.fileAdded', 'File added'), t('visit.error.file', 'The file could not be added. Add a PDF, a photo or a text file up to 20 MB.'));
          }}
        />
      </div>

      <ConfirmAction
        open={finishing}
        onOpenChange={setFinishing}
        title={t('visit.finishTitle', 'Finish this visit?')}
        description={t('visit.finishBody', 'The notes are signed with your name and can’t be changed after this. You can still add dated corrections.')}
        confirmLabel={t('visit.finish', 'Finish visit')}
        cancelLabel={t('action.cancel', 'Cancel')}
        onConfirm={async () => {
          setFinishing(false);
          await act(
            async () => {
              if (draft !== null) await calendarApi.saveVisitNotes(booking.id, notes);
              return calendarApi.finishVisit(booking.id);
            },
            t('visit.finishedToast', 'Visit finished'),
            t('visit.error.finish', 'The visit could not be finished. Try again.')
          );
        }}
      />
    </section>
  );
}
