'use client';

/**
 * The customer's own booking, from the link in their email (WHIT-945, ADR 0017
 * section 6): what they booked, Add to calendar, Move and Cancel. Nobody is
 * signed in; the token in the address is the only key.
 *
 * MOVE gives the booking a NEW token and the old link stops working, so the
 * address is replaced (not pushed: Back must not lead to a dead link) and the
 * page carries on with the new one.
 *
 * CANCEL cannot be undone, so it is a dialog naming the consequence, with an
 * optional reason (section 21: confirm by how undoable an action is).
 *
 * A link that is unknown, expired (it ends when the booking starts) or not a
 * link we send at all reads the same: plain words, nothing to do.
 *
 * WHIT-1417 (AP.15): the reminder links here with `?answer=coming` or
 * `?answer=cancel`. Opening a link never answers: `coming` makes "I'm coming"
 * the main button, `cancel` opens the cancel dialog, and the customer presses.
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Skeleton } from '@whity/ui/skeleton';
import { Textarea } from '@whity/ui/textarea';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@whity/features/i18n';
import { safeTimeZone, toZonedIso, zonedDate } from '@whity/features/datetime';
import { PublicShell } from '@/components/auth/public-shell';
import { OutcomeIcon } from '@/components/auth/public-outcome';
import {
  PublicBookingError,
  icsUrl,
  isManageToken,
  publicBookingApi,
  type FreeTime,
  type PublicBooking,
} from '@/lib/booking/public-api';
import { FreeTimePicker } from './free-time-picker';
import { BookingFacts, InYourZone, ZoneNote, useWhenAt } from './booking-parts';
import { useBookingWords } from './use-booking-words';

type Phase = 'loading' | 'invalid' | 'error' | 'tooMany' | 'ready';

/** Replace the address with the booking's new link, without a new history entry. */
export function replaceManageUrl(token: string): void {
  window.history.replaceState(window.history.state, '', `/booking/${token}`);
}

/** What the reminder's link asks for, from `?answer=`. */
function answerAsked(): 'coming' | 'cancel' | null {
  if (typeof window === 'undefined') return null;
  const answer = new URLSearchParams(window.location.search).get('answer');
  return answer === 'coming' || answer === 'cancel' ? answer : null;
}

export function ManageBookingScreen({ token: initialToken }: { token: string }) {
  const t = useTranslation('booking');
  const words = useBookingWords();
  const [token, setToken] = useState(initialToken);
  const [phase, setPhase] = useState<Phase>(isManageToken(initialToken) ? 'loading' : 'invalid');
  const [booking, setBooking] = useState<PublicBooking | null>(null);
  const [mode, setMode] = useState<'view' | 'move'>('view');
  const [cancelling, setCancelling] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [taking, setTaking] = useState(false);
  const [takeProblem, setTakeProblem] = useState<string | null>(null);
  // WHIT-1014: the workspace's brand, from the first answer (later answers leave it out).
  const [brand, setBrand] = useState<PublicBooking['brand']>(null);
  const [asked] = useState(answerAsked);
  const [answering, setAnswering] = useState(false);
  const [answerProblem, setAnswerProblem] = useState<string | null>(null);

  useEffect(() => {
    if (!isManageToken(initialToken)) return;
    let cancelled = false;
    publicBookingApi
      .show(initialToken)
      .then((found) => {
        if (cancelled) return;
        setBooking(found);
        setBrand(found.brand ?? null);
        setPhase('ready');
        if (asked === 'cancel' && found.status === 'confirmed') setCancelling(true);
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        if (error instanceof PublicBookingError && (error.status === 404 || error.status === 401)) setPhase('invalid');
        else if (error instanceof PublicBookingError && error.status === 429) setPhase('tooMany');
        else setPhase('error');
      });
    return () => {
      cancelled = true;
    };
  }, [initialToken, attempt, asked]);

  if (phase === 'loading') {
    return (
      <PublicShell wide>
        <Card className="text-sm" aria-busy="true" data-testid="manage-loading">
          <CardHeader>
            <Skeleton className="h-7 w-1/2" />
          </CardHeader>
          <CardContent className="space-y-3">
            <span className="sr-only">{t('manage.loading', 'Loading your booking…')}</span>
            {Array.from({ length: 4 }, (_, i) => (
              <Skeleton key={i} className="h-12 rounded-card" />
            ))}
          </CardContent>
        </Card>
      </PublicShell>
    );
  }

  if (phase !== 'ready' || booking === null) {
    return (
      <PublicShell wide>
        <Card className="text-sm" data-testid={`manage-${phase}`}>
          <CardHeader className="flex items-center gap-4">
            <OutcomeIcon name={phase === 'invalid' ? 'search' : 'offline'} tone={phase === 'invalid' ? 'muted' : 'warn'} />
            <CardTitle className="text-xl font-semibold">
              {phase === 'invalid'
                ? t('manage.invalid.title', 'This booking link doesn’t work')
                : phase === 'tooMany'
                ? t('tooMany.title', 'Too many tries')
                : t('manage.failed.title', 'Your booking didn’t load')}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <CardDescription className="text-sm">
              {phase === 'invalid'
                ? t(
                    'manage.invalid.description',
                    'It may be from an old email, or the booking already happened, was moved or was cancelled. Your latest email from the business has the right link.'
                  )
                : phase === 'tooMany'
                ? t('error.tooMany', 'Too many tries from this connection. Wait a few minutes and try again.')
                : t('failed.description', 'Check your connection and try again.')}
            </CardDescription>
            {phase !== 'invalid' && (
              <Button variant="outline" onClick={() => {
                  setPhase('loading');
                  setAttempt((n) => n + 1);
                }}>
                {t('action.retry', 'Try again')}
              </Button>
            )}
          </CardContent>
        </Card>
      </PublicShell>
    );
  }

  const timeZone = safeTimeZone(booking.time_zone);
  const workspaceName = booking.workspace?.name ?? '';
  const open = booking.status === 'confirmed' || booking.status === 'pending_payment' || booking.status === 'requested';
  const requested = booking.status === 'requested';
  const suggested = requested && booking.suggested === true;
  // WHIT-1417: the reminder's I'm coming, until they have said so.
  const askComing = asked === 'coming' && booking.status === 'confirmed' && booking.coming !== true;

  const sayComing = async () => {
    setAnswering(true);
    setAnswerProblem(null);
    try {
      const answered = await publicBookingApi.coming(token);
      setBooking((current) => ({ ...answered, workspace: answered.workspace ?? current?.workspace, time_zone: answered.time_zone ?? current?.time_zone }));
      setNotice(t('manage.comingThanks', 'Thank you. {workspace} knows you’re coming.', { workspace: workspaceName || t('manage.theBusiness', 'The business') }));
    } catch (error) {
      setAnswerProblem(words.errorText(error, t('manage.comingError', 'Your answer didn’t reach us. Try again.')));
    } finally {
      setAnswering(false);
    }
  };

  // Design 3.3: take the time the business suggested. The link changes, like a move.
  const takeSuggestion = async () => {
    setTaking(true);
    setTakeProblem(null);
    try {
      const taken = await publicBookingApi.acceptSuggestion(token);
      setBooking((current) => ({ ...taken, workspace: taken.workspace ?? current?.workspace, time_zone: taken.time_zone ?? current?.time_zone }));
      if (taken.manage_token) {
        setToken(taken.manage_token);
        replaceManageUrl(taken.manage_token);
      }
      setNotice(t('manage.taken', 'Booked. We’ve emailed you the details and a new link; this page now uses it.'));
    } catch (error) {
      setTakeProblem(words.errorText(error, t('manage.takeError', 'The time could not be taken. Try again, or pick another time.')));
    } finally {
      setTaking(false);
    }
  };

  return (
    <PublicShell wide brand={brand}>
      <Card className="text-sm" data-testid="manage-card">
        {/* Moving has its own heading (the card's "Move your booking"). */}
        {mode === 'view' && (
          <CardHeader className="gap-3">
            <div className="flex items-center gap-4">
              {booking.status === 'confirmed' && <OutcomeIcon name="success" tone="ok" />}
              {booking.status === 'cancelled' && <OutcomeIcon name="close" tone="muted" />}
              {requested && <OutcomeIcon name="clock" tone="dash" />}
              <CardTitle className="text-xl font-semibold">
                {booking.status === 'confirmed'
                  ? t('manage.booked', 'You’re booked')
                  : booking.status === 'cancelled'
                  ? t('manage.cancelledTitle', 'Your booking is cancelled')
                  : workspaceName
                  ? t('manage.title', 'Your booking with {workspace}', { workspace: workspaceName })
                  : t('manage.titlePlain', 'Your booking')}
              </CardTitle>
            </div>
            <CardDescription className="text-sm">
              {booking.status === 'confirmed' && booking.customer.name
                ? t('manage.hello', 'Hello {name}. Here is your booking with {workspace}; keep this link to change it.', {
                    name: booking.customer.name,
                    workspace: workspaceName,
                  })
                : booking.status === 'cancelled'
                ? t('manage.cancelled', 'There is nothing more to do here. To come another time, book again.')
                : booking.status === 'expired'
                ? t('manage.expired', 'This booking wasn’t paid in time, so the time was given back. There is nothing more to do here.')
                : suggested
                ? t('manage.suggestedDescription', '{workspace} can’t do the time you asked for and suggests this one. Take it, pick another time, or cancel.', { workspace: workspaceName })
                : requested
                ? t('manage.requestedDescription', 'Your request is waiting for an answer. The time is held for you; you’ll get an email when it is accepted.')
                : t('manage.description', 'Here is your booking; keep this link to change it.')}
            </CardDescription>
          </CardHeader>
        )}
        <CardContent className="space-y-5">
          {notice && (
            <Alert variant="success" data-testid="manage-notice">
              <AlertDescription className="text-sm">{notice}</AlertDescription>
            </Alert>
          )}

          {mode === 'view' ? (
            <>
              <BookingFacts booking={booking} timeZone={timeZone} />
              {booking.status === 'confirmed' && booking.coming === true && notice === null && (
                <p className="text-sm text-muted-foreground" data-testid="manage-coming-said">
                  {t('manage.comingSaid', 'You told us you’re coming.')}
                </p>
              )}
              {answerProblem && (
                <Alert variant="destructive" data-testid="manage-coming-problem">
                  <AlertDescription className="text-sm">{answerProblem}</AlertDescription>
                </Alert>
              )}
              {takeProblem && (
                <Alert variant="destructive" data-testid="manage-take-problem">
                  <AlertDescription className="text-sm">{takeProblem}</AlertDescription>
                </Alert>
              )}
              {open && (
                <div className="grid gap-2">
                  {suggested && (
                    <Button size="lg" onClick={() => void takeSuggestion()} loading={taking} data-testid="manage-take">
                      {t('manage.take', 'Take this time')}
                    </Button>
                  )}
                  {askComing && (
                    <Button size="lg" onClick={() => void sayComing()} loading={answering} data-testid="manage-coming">
                      <Icon name="check" />
                      {t('manage.coming', 'I’m coming')}
                    </Button>
                  )}
                  {/* The main button, unless a suggested time is waiting to be taken or the reminder asks. */}
                  <Button
                    asChild
                    size={suggested || askComing ? 'default' : 'lg'}
                    variant={suggested || askComing ? 'outline' : 'default'}
                    data-testid="manage-ics"
                  >
                    <a href={icsUrl(token)} download="booking.ics">
                      <Icon name="calendar" />
                      {t('action.addToMyCalendar', 'Add to my calendar')}
                    </a>
                  </Button>
                  <div className="grid grid-cols-2 gap-2">
                    <Button
                      variant="outline"
                      onClick={() => {
                        setNotice(null);
                        setMode('move');
                      }}
                      data-testid="manage-move"
                    >
                      <Icon name="sync" />
                      {t('manage.move.action', 'Move it')}
                    </Button>
                    <Button variant="outline" onClick={() => setCancelling(true)} data-testid="manage-cancel">
                      <Icon name="close" />
                      {askComing ? t('manage.cantCome', 'I can’t come') : t('manage.cancel.action', 'Cancel it')}
                    </Button>
                  </div>
                </div>
              )}
            </>
          ) : (
            <MovePanel
              token={token}
              booking={booking}
              timeZone={timeZone}
              onBack={() => setMode('view')}
              onMoved={(moved, newToken) => {
                setBooking((current) => ({ ...moved, workspace: moved.workspace ?? current?.workspace, time_zone: moved.time_zone ?? current?.time_zone }));
                if (newToken) {
                  setToken(newToken);
                  replaceManageUrl(newToken);
                }
                setMode('view');
                setNotice(
                  t(
                    'manage.moved',
                    'Moved. We’ve emailed you the new time and a new link; the old link no longer works. This page now uses the new one.'
                  )
                );
              }}
            />
          )}
        </CardContent>
      </Card>

      <CancelDialog
        open={cancelling}
        token={token}
        booking={booking}
        timeZone={timeZone}
        serviceName={words.name(booking.service.name)}
        workspaceName={workspaceName}
        onOpenChange={setCancelling}
        onCancelled={(cancelled) => {
          setBooking((current) => ({ ...cancelled, workspace: cancelled.workspace ?? current?.workspace, time_zone: cancelled.time_zone ?? current?.time_zone }));
          setCancelling(false);
          setNotice(null);
        }}
      />
    </PublicShell>
  );
}

function MovePanel({
  token,
  booking,
  timeZone,
  onBack,
  onMoved,
}: {
  token: string;
  booking: PublicBooking;
  timeZone: string;
  onBack: () => void;
  onMoved: (booking: PublicBooking, token: string | null) => void;
}) {
  const t = useTranslation('booking');
  const words = useBookingWords();
  const whenAt = useWhenAt(timeZone);
  const [slot, setSlot] = useState<FreeTime | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const today = useMemo(() => zonedDate(timeZone), [timeZone]);

  const load = useCallback((range: { from: string; to: string }) => publicBookingApi.manageSlots(token, range), [token]);

  const save = async () => {
    if (slot === null) {
      setProblem(t('time.missing', 'Choose a day and a free time.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      const moved = await publicBookingApi.move(token, toZonedIso(new Date(slot.starts_at), timeZone));
      onMoved(moved, moved.manage_token ?? null);
    } catch (error) {
      if (error instanceof PublicBookingError && error.status === 409) {
        setSlot(null);
        setReloadKey((k) => k + 1);
      }
      setProblem(
        error instanceof PublicBookingError && error.status === 404
          ? t('manage.invalid.title', 'This booking link doesn’t work')
          : words.errorText(error, t('manage.move.error', 'The booking couldn’t be moved. Try again.'))
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-4" data-testid="manage-move-panel">
      <div className="space-y-1">
        <h2 className="text-xl font-semibold text-foreground">{t('manage.move.title', 'Move your booking')}</h2>
        <p className="text-sm text-muted-foreground">
          {t('manage.move.now', 'Now: {when}. Pick a new day and time; everything else stays the same.', {
            when: whenAt(booking.starts_at),
          })}
        </p>
        <ZoneNote timeZone={timeZone} />
      </div>
      <FreeTimePicker
        id="move"
        timeZone={timeZone}
        earliest={today}
        latest={null}
        load={load}
        loadKey={token}
        selected={slot}
        onSelect={(next) => {
          setSlot(next);
          if (next) setProblem(null);
        }}
        reloadKey={reloadKey}
        exclude={booking}
      />
      {slot && (
        <div className="rounded-card bg-muted px-4 py-3" data-testid="move-summary">
          <p className="text-foreground">
            {t('manage.move.to', 'New time: {when}', { when: whenAt(slot.starts_at) })}
          </p>
          <InYourZone item={slot} timeZone={timeZone} />
        </div>
      )}
      {problem && (
        <Alert variant="destructive" data-testid="move-problem">
          <AlertDescription className="text-sm">{problem}</AlertDescription>
        </Alert>
      )}
      <div className="grid gap-2">
        <Button size="lg" onClick={() => void save()} loading={saving} disabled={slot === null} data-testid="move-submit">
          {slot
            ? t('manage.move.submitTo', 'Move to {when}', { when: whenAt(slot.starts_at) })
            : t('manage.move.submit', 'Move to this time')}
        </Button>
        <Button variant="ghost" onClick={onBack} disabled={saving}>
          {t('manage.move.keep', 'Keep the original time')}
        </Button>
      </div>
    </div>
  );
}

function CancelDialog({
  open,
  token,
  booking,
  timeZone,
  serviceName,
  workspaceName,
  onOpenChange,
  onCancelled,
}: {
  open: boolean;
  token: string;
  booking: PublicBooking;
  timeZone: string;
  serviceName: string;
  workspaceName: string;
  onOpenChange: (open: boolean) => void;
  onCancelled: (booking: PublicBooking) => void;
}) {
  const t = useTranslation('booking');
  const words = useBookingWords();
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const when = useWhenAt(timeZone)(booking.starts_at);

  const confirm = async () => {
    setSaving(true);
    setProblem(null);
    try {
      onCancelled(await publicBookingApi.cancel(token, reason));
      setReason('');
    } catch (error) {
      setProblem(
        error instanceof PublicBookingError && error.status === 404
          ? t('manage.invalid.title', 'This booking link doesn’t work')
          : words.errorText(error, t('manage.cancel.error', 'The booking couldn’t be cancelled. Try again.'))
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-testid="manage-cancel-dialog">
        <DialogHeader>
          <DialogTitle>{t('manage.cancel.title', 'Cancel your booking?')}</DialogTitle>
          <DialogDescription>
            {t(
              'manage.cancel.description',
              'Your {service} on {when} will be cancelled and the time given to someone else. This can’t be undone: to come another time, book again.',
              { service: serviceName, when }
            )}
            {workspaceName ? ` ${t('manage.cancel.told', '{workspace} will be told.', { workspace: workspaceName })}` : ''}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-1.5">
          <label htmlFor="cancel-reason" className="text-sm font-medium text-foreground">
            {workspaceName
              ? t('manage.cancel.reasonFor', 'Tell {workspace} why (optional)', { workspace: workspaceName })
              : t('manage.cancel.reason', 'Reason (optional)')}
          </label>
          <Textarea id="cancel-reason" className="text-sm" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
        </div>
        {problem && (
          <Alert variant="destructive">
            <AlertDescription className="text-sm">{problem}</AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {t('manage.cancel.keep', 'No, keep it')}
          </Button>
          <Button variant="destructive" onClick={() => void confirm()} loading={saving} data-testid="manage-cancel-confirm">
            {t('manage.cancel.confirm', 'Yes, cancel my booking')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
