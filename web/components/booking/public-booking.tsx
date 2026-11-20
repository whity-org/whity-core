'use client';

/**
 * The public booking page (WHIT-945, ADR 0017 section 6): a customer of the
 * workspace picks a service, a person or anyone, a day and a free time, gives
 * a name and an email, and books. Nobody is signed in.
 *
 * ONE PAGE, PHONE FIRST. The steps are sections of one card that open as the
 * one before is answered, so a person on a phone scrolls down rather than
 * pressing Next four times, and can change an earlier answer without going
 * back. "Book this time" is the one filled button.
 *
 * SPAM. A hidden `website` field nobody sees (not people, not screen readers)
 * is sent as it is, with how long the page has been open (`elapsed_ms`); the
 * server refuses a filled field or a page sent in under 3 seconds. Turnstile
 * appears only when the page's `bot_check` says so.
 *
 * A TIME JUST TAKEN (409) keeps everything typed, lets the time go and fetches
 * the free times again, saying so in plain words.
 */

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type RefObject } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Skeleton } from '@whity/ui/skeleton';
import { Textarea } from '@whity/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@whity/ui/radio-group';
import { Input } from '@/components/ui/input';
import { useCurrentLanguage, useTranslation } from '@whity/features/i18n';
import { safeTimeZone, toZonedIso, useDateDisplay, zonedDate } from '@whity/features/datetime';
import { useThemeMode } from '@/lib/theme-mode-context';
import { PublicShell } from '@/components/auth/public-shell';
import { TurnstileWidget } from '@/components/auth/turnstile-widget';
import { addDays } from '@/lib/calendar/layout';
import {
  MIN_FILL_MS,
  PublicBookingError,
  bookingRequestBody,
  descriptionOf,
  icsUrl,
  publicBookingApi,
  type BookedResult,
  type FreeTime,
  type PublicBookingPage,
  type PublicService,
  type ServicePick,
} from '@/lib/booking/public-api';
import { FieldSelect } from '@/components/field-select';
import { FreeTimePicker } from './free-time-picker';
import { BookingFacts, InYourZone, ZoneNote, useWhenAt } from './booking-parts';
import { moneyText, useBookingWords } from './use-booking-words';
import { cn } from '@/lib/utils';

const ANYONE = 'anyone';
/** Several services: a different person for each (WHIT-1173). */
const EACH = 'each';
/** At most this many services in one booking (the server's limit). */
const MAX_SERVICES = 8;

/** The time in ms, read only from the submit handler (kept out of render for the compiler). */
function nowMs(): number {
  return Date.now();
}

type Phase = 'loading' | 'closed' | 'notReady' | 'error' | 'tooMany' | 'ready' | 'booked';
type Field = 'name' | 'email' | 'phone';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE = /^\+?[0-9 ()-]{6,40}$/;

export function PublicBookingScreen({ workspace }: { workspace: string }) {
  const t = useTranslation('booking');
  const [phase, setPhase] = useState<Phase>('loading');
  const [page, setPage] = useState<PublicBookingPage | null>(null);
  const [result, setResult] = useState<BookedResult | null>(null);
  const [attempt, setAttempt] = useState(0);

  // When the page loaded: the server wants to know how long it was open before a booking.
  const loadedAt = useRef<number | null>(null);
  useEffect(() => {
    loadedAt.current = Date.now();
  }, []);

  useEffect(() => {
    let cancelled = false;
    publicBookingApi
      .page(workspace)
      .then((found) => {
        if (cancelled) return;
        setPage(found);
        // WHIT-1298 (#2203): booking is refused until the bot check is set up.
        setPhase(found.bot_check_ready === false ? 'notReady' : 'ready');
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        if (error instanceof PublicBookingError && error.status === 404) setPhase('closed');
        else if (error instanceof PublicBookingError && error.status === 429) setPhase('tooMany');
        else setPhase('error');
      });
    return () => {
      cancelled = true;
    };
  }, [workspace, attempt]);

  if (phase === 'loading') {
    return (
      <PublicShell wide>
        <Card className="border-t-4 border-t-primary text-sm" aria-busy="true" data-testid="book-loading">
          <CardHeader>
            <Skeleton className="h-7 w-2/3" />
            <Skeleton className="h-5 w-1/2" />
          </CardHeader>
          <CardContent className="space-y-3">
            <span className="sr-only">{t('loading', 'Loading the booking page…')}</span>
            {Array.from({ length: 3 }, (_, i) => (
              <Skeleton key={i} className="h-16 rounded-card" />
            ))}
          </CardContent>
        </Card>
      </PublicShell>
    );
  }

  if (phase === 'notReady') {
    return (
      <PublicShell wide brand={page?.brand}>
        <Card className="border-t-4 border-t-primary text-sm" data-testid="book-notReady">
          <CardHeader>
            <CardTitle className="text-xl font-semibold">
              {t('notReady.title', '{name} isn’t taking bookings here yet', { name: page?.workspace.name ?? '' })}
            </CardTitle>
            <CardDescription className="text-sm">
              {t('notReady.description', 'They’re still setting up this page. Try again later, or contact them to book.')}
            </CardDescription>
          </CardHeader>
        </Card>
      </PublicShell>
    );
  }

  if (phase === 'closed' || phase === 'error' || phase === 'tooMany' || page === null) {
    return (
      <PublicShell wide>
        <Card className="border-t-4 border-t-primary text-sm" data-testid={`book-${phase}`}>
          <CardHeader>
            <CardTitle className="text-xl font-semibold">
              {phase === 'closed'
                ? t('closed.title', 'This booking page isn’t available')
                : phase === 'tooMany'
                ? t('tooMany.title', 'Too many tries')
                : t('failed.title', 'The booking page didn’t load')}
            </CardTitle>
            <CardDescription className="text-sm">
              {phase === 'closed'
                ? t(
                    'closed.description',
                    'The address may be wrong, or the business isn’t taking bookings online right now. Check the link they gave you, or contact them.'
                  )
                : phase === 'tooMany'
                ? t('error.tooMany', 'Too many tries from this connection. Wait a few minutes and try again.')
                : t('failed.description', 'Check your connection and try again.')}
            </CardDescription>
          </CardHeader>
          {phase !== 'closed' && (
            <CardContent>
              <Button variant="outline" onClick={() => {
                  setPhase('loading');
                  setAttempt((n) => n + 1);
                }}>
                {t('action.retry', 'Try again')}
              </Button>
            </CardContent>
          )}
        </Card>
      </PublicShell>
    );
  }

  // WHIT-1014: once the page is known, it shows in the workspace's own brand.
  if (phase === 'booked' && result !== null) {
    return (
      <PublicShell wide brand={page.brand}>
        <BookedView page={page} result={result} />
      </PublicShell>
    );
  }

  return (
    <PublicShell wide brand={page.brand}>
      {page.services.length === 0 ? (
        <Card className="border-t-4 border-t-primary text-sm" data-testid="book-no-services">
          <CardHeader>
            <CardTitle className="text-xl font-semibold">
              {t('title', 'Book with {workspace}', { workspace: page.workspace.name })}
            </CardTitle>
            <CardDescription className="text-sm">
              {t(
                'noServices',
                'Nothing can be booked online here yet. Contact {workspace} to make a booking.',
                { workspace: page.workspace.name }
              )}
            </CardDescription>
          </CardHeader>
        </Card>
      ) : (
        <BookingForm
          workspace={workspace}
          page={page}
          loadedAt={loadedAt}
          onBooked={(booked) => {
            setResult(booked);
            setPhase('booked');
            window.scrollTo?.({ top: 0 });
          }}
          onClosed={() => setPhase('closed')}
        />
      )}
    </PublicShell>
  );
}

function SectionTitle({ n, children, id }: { n: number; children: string; id?: string }) {
  return (
    <h2 id={id} className="flex items-center gap-2 text-base font-semibold text-foreground">
      <span
        aria-hidden="true"
        className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-tint text-sm text-primary-tint-foreground tabular-nums"
      >
        {n}
      </span>
      {children}
    </h2>
  );
}

function BookingForm({
  workspace,
  page,
  loadedAt,
  onBooked,
  onClosed,
}: {
  workspace: string;
  page: PublicBookingPage;
  loadedAt: RefObject<number | null>;
  onBooked: (result: BookedResult) => void;
  onClosed: () => void;
}) {
  const t = useTranslation('booking');
  const words = useBookingWords();
  const { currentLanguage } = useCurrentLanguage();
  const { resolved: theme } = useThemeMode();
  const timeZone = safeTimeZone(page.settings.time_zone);
  const whenAt = useWhenAt(timeZone);

  const [serviceId, setServiceId] = useState<number | null>(page.services.length === 1 ? page.services[0].id : null);
  const [person, setPerson] = useState<string>(ANYONE);
  const [slot, setSlot] = useState<FreeTime | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [note, setNote] = useState('');
  const [website, setWebsite] = useState('');
  const [botToken, setBotToken] = useState<string | null>(null);
  const [botFailed, setBotFailed] = useState(false);
  const [resetKey, setResetKey] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<Field, string>>>({});
  const [prefilled, setPrefilled] = useState(false);

  // WHIT-1179: someone signed in to Whity finds their own name and email
  // already filled. Only their own account is asked (a no-store route), only
  // empty fields are filled so nothing they typed is replaced, and every
  // field stays editable: what they send is what the booking uses.
  useEffect(() => {
    let cancelled = false;
    void publicBookingApi.visitor(workspace).then((me) => {
      if (cancelled || me === null) return;
      let filled = false;
      if (me.name !== '') {
        setName((current) => (current === '' ? me.name : current));
        filled = true;
      }
      if (me.email !== '') {
        setEmail((current) => (current === '' ? me.email : current));
        filled = true;
      }
      if (me.phone) {
        const phoneValue = me.phone;
        setPhone((current) => (current === '' ? phoneValue : current));
        filled = true;
      }
      setPrefilled(filled);
    });
    return () => {
      cancelled = true;
    };
  }, [workspace]);

  const service: PublicService | null = page.services.find((s) => s.id === serviceId) ?? null;

  // WHIT-1173: more services after the first, back to back, when the
  // workspace allows it; one person for all, anyone, or a person for each.
  const multiOn = page.settings.multi_service_booking === true;
  const [more, setMore] = useState<{ key: number; serviceId: number | null }[]>([]);
  const [nextKey, setNextKey] = useState(1);
  const [each, setEach] = useState<Record<number, string>>({});
  const extra = more.map((row) => page.services.find((s) => s.id === row.serviceId) ?? null);
  const severalOn = service !== null && extra.length > 0 && extra.every((s) => s !== null);
  const chosen: PublicService[] = service ? [service, ...extra.filter((s): s is PublicService => s !== null)] : [];

  // Who does every chosen service (one service: who does it).
  const providers =
    chosen.length === 0 ? [] : page.staff.filter((p) => chosen.every((s) => s.staff_profile_ids.includes(p.profile_id)));
  const profileId = person === ANYONE || person === EACH ? null : Number(person);
  const several: ServicePick[] | null = severalOn
    ? chosen.map((s, i) => ({
        service_id: s.id,
        profile_id: person === EACH ? (each[i] && each[i] !== ANYONE ? Number(each[i]) : null) : profileId,
      }))
    : null;
  const severalKey = several ? several.map((s) => `${s.service_id}:${s.profile_id ?? 0}`).join(',') : '';
  const showPerson = severalOn ? true : providers.length > 1;
  const totalMinutes = chosen.reduce((sum, s) => sum + s.duration_minutes, 0);
  const pricedServices = chosen.filter((s) => s.price_minor !== null && s.price_minor !== undefined);
  const totalPrice = pricedServices.length > 0 ? pricedServices.reduce((sum, s) => sum + (s.price_minor ?? 0), 0) : null;
  const sendsRequest = chosen.some((s) => s.sends_request);
  const servicesChanged = () => {
    setPerson(ANYONE);
    setEach({});
    setSlot(null);
    setProblem(null);
  };

  const today = useMemo(() => zonedDate(timeZone), [timeZone]);
  const latest = useMemo(
    () => (page.settings.max_advance_days > 0 ? addDays(today, page.settings.max_advance_days) : null),
    [today, page.settings.max_advance_days]
  );

  // The picker fetches when `loadKey` changes, not when this function does.
  const load = (range: { from: string; to: string }) =>
    serviceId === null
      ? Promise.resolve([])
      : publicBookingApi.slots(workspace, { serviceId, profileId, ...range, several });

  const onToken = useCallback((token: string | null) => {
    setBotToken(token);
    if (token !== null) setBotFailed(false);
  }, []);
  const onBotError = useCallback(() => setBotFailed(true), []);

  const chooseService = (value: string) => {
    setServiceId(Number(value));
    servicesChanged();
  };

  const validate = (): Partial<Record<Field, string>> => {
    const errors: Partial<Record<Field, string>> = {};
    if (name.trim() === '') errors.name = t('field.name.error', 'Enter your name.');
    if (!EMAIL.test(email.trim())) errors.email = t('field.email.error', 'Enter a valid email address.');
    if (phone.trim() !== '' && !PHONE.test(phone.trim())) {
      errors.phone = t('field.phone.error', 'Enter a phone number with digits only, such as +962 79 555 0142.');
    }
    return errors;
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setProblem(null);
    if (service === null || (extra.length > 0 && !severalOn)) {
      setProblem(t('service.missing', 'Choose a service.'));
      return;
    }
    if (slot === null) {
      setProblem(t('time.missing', 'Choose a day and a free time.'));
      return;
    }
    const errors = validate();
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) {
      const firstField = (['name', 'email', 'phone'] as Field[]).find((f) => errors[f]);
      if (firstField) document.getElementById(`book-${firstField}`)?.focus();
      return;
    }
    if (page.bot_check && botToken === null) {
      setProblem(t('bot.pending', 'Wait for the check above the button to finish, then try again.'));
      return;
    }

    setSubmitting(true);
    try {
      // Someone who is quicker than the server's minimum is held back, not refused.
      const opened = loadedAt.current ?? nowMs();
      const waited = nowMs() - opened;
      if (waited < MIN_FILL_MS) await new Promise((resolve) => setTimeout(resolve, MIN_FILL_MS - waited));
      const body = bookingRequestBody({
        serviceId: service.id,
        profileId,
        startsAt: toZonedIso(new Date(slot.starts_at), timeZone),
        name,
        email,
        phone,
        note,
        website,
        elapsedMs: nowMs() - opened,
        botToken,
        several,
      });
      const booked = await publicBookingApi.book(workspace, body);
      onBooked(booked);
    } catch (error) {
      // A bot token is single use: ask for a fresh one whatever happened.
      if (page.bot_check) {
        setBotToken(null);
        setResetKey((k) => k + 1);
      }
      if (error instanceof PublicBookingError && error.status === 404) {
        onClosed();
        return;
      }
      if (error instanceof PublicBookingError && error.status === 409) {
        setSlot(null);
        setReloadKey((k) => k + 1);
      }
      if (
        error instanceof PublicBookingError &&
        error.status === 422 &&
        (error.field === 'name' || error.field === 'email' || error.field === 'phone')
      ) {
        setFieldErrors({ [error.field]: words.errorText(error, '') });
        document.getElementById(`book-${error.field}`)?.focus();
        return;
      }
      setProblem(words.errorText(error, t('error.generic', 'We couldn’t take this booking. Try again.')));
    } finally {
      setSubmitting(false);
    }
  };

  const serviceName = chosen.map((s) => words.name(s.name)).join(' + ');
  const providerName = profileId !== null ? page.staff.find((s) => s.profile_id === profileId)?.display_name ?? '' : '';

  return (
    <Card className="border-t-4 border-t-primary text-sm" data-testid="book-form-card">
      <CardHeader>
        <CardTitle className="text-xl font-semibold">
          {t('title', 'Book with {workspace}', { workspace: page.workspace.name })}
        </CardTitle>
        <CardDescription className="text-sm">
          {t('intro', 'Choose what you’d like, then a time that suits you. We’ll email you the details.')}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} noValidate className="space-y-8" data-testid="book-form">
          {/* 1. Service */}
          <section className="space-y-3" aria-labelledby="book-step-service">
            <SectionTitle n={1} id="book-step-service">
              {t('step.service', 'What would you like to book?')}
            </SectionTitle>
            <RadioGroup
              value={serviceId === null ? '' : String(serviceId)}
              onValueChange={chooseService}
              aria-labelledby="book-step-service"
              className="gap-2"
            >
              {page.services.map((s) => {
                const on = s.id === serviceId;
                const description = descriptionOf(s);
                const price = words.price(s.price_minor, s.currency);
                return (
                  <label
                    key={s.id}
                    htmlFor={`book-service-${s.id}`}
                    data-testid="book-service"
                    className={cn(
                      'flex cursor-pointer items-start gap-3 rounded-card border border-border bg-card px-4 py-3 transition-colors hover:bg-muted',
                      on && 'border-primary bg-primary-tint hover:bg-primary-tint'
                    )}
                  >
                    <RadioGroupItem id={`book-service-${s.id}`} value={String(s.id)} className="mt-1" />
                    <span className="min-w-0 flex-1">
                      <span className="block font-medium text-foreground">{words.name(s.name)}</span>
                      {description && (
                        <span className="block text-sm text-muted-foreground">{words.name(description)}</span>
                      )}
                      <span className="mt-0.5 block text-sm text-muted-foreground">
                        {words.duration(s.duration_minutes)}
                      </span>
                      {s.sends_request && (
                        <span className="mt-0.5 block text-sm text-muted-foreground" data-testid="book-service-request">
                          {t('service.request', 'You send a request; the business confirms the time.')}
                        </span>
                      )}
                    </span>
                    {price !== null && (
                      <span className="shrink-0 text-sm font-medium text-foreground tabular-nums" dir="ltr">
                        {price}
                      </span>
                    )}
                  </label>
                );
              })}
            </RadioGroup>

            {/* WHIT-1173: more services after the first, back to back. */}
            {multiOn && service && (
              <div className="space-y-3" data-testid="book-more">
                {more.map((row, i) => (
                  <div key={row.key} className="flex items-end gap-2" data-testid="book-more-service">
                    <div className="min-w-0 flex-1 space-y-1.5">
                      <label htmlFor={`book-more-${row.key}`} className="text-sm font-medium text-foreground">
                        {t('more.then', 'Then')}
                      </label>
                      <FieldSelect
                        id={`book-more-${row.key}`}
                        value={row.serviceId === null ? '' : String(row.serviceId)}
                        onChange={(value) => {
                          setMore((rows) => rows.map((r) => (r.key === row.key ? { ...r, serviceId: Number(value) } : r)));
                          servicesChanged();
                        }}
                        placeholder={t('more.choose', 'Choose a service')}
                        invalid={extra[i] === null && problem !== null}
                        options={page.services.map((s) => ({
                          value: String(s.id),
                          label: [words.name(s.name), words.duration(s.duration_minutes), words.price(s.price_minor, s.currency)]
                            .filter((part): part is string => part !== null && part !== '')
                            .join(' · '),
                        }))}
                      />
                    </div>
                    <Button
                      type="button"
                      variant="ghost"
                      onClick={() => {
                        setMore((rows) => rows.filter((r) => r.key !== row.key));
                        servicesChanged();
                      }}
                      aria-label={t('more.remove', 'Remove this service')}
                      title={t('more.remove', 'Remove this service')}
                      data-testid="book-more-remove"
                    >
                      <Icon name="close" />
                    </Button>
                  </div>
                ))}
                <div className="flex flex-wrap items-center justify-between gap-3">
                  {more.length + 1 < MAX_SERVICES && (
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => {
                        setMore((rows) => [...rows, { key: nextKey, serviceId: null }]);
                        setNextKey((k) => k + 1);
                        servicesChanged();
                      }}
                      data-testid="book-add-service"
                    >
                      <Icon name="plus" />
                      {t('more.add', 'Add another service')}
                    </Button>
                  )}
                  {more.length > 0 && (
                    <p className="text-sm text-muted-foreground" aria-live="polite" data-testid="book-total">
                      {t('more.total', 'In all: {time}', { time: words.duration(totalMinutes) ?? '' })}
                      {totalPrice !== null && (
                        <>
                          {' · '}
                          <span dir="ltr" className="tabular-nums">
                            {words.price(totalPrice, service.currency)}
                          </span>
                        </>
                      )}
                    </p>
                  )}
                </div>
              </div>
            )}
          </section>

          {/* 2. Who, when more than one person offers it (or several services) */}
          {service && showPerson && (
            <section className="space-y-3" aria-labelledby="book-step-person">
              <SectionTitle n={2} id="book-step-person">
                {t('step.person', 'Who would you like to see?')}
              </SectionTitle>
              <RadioGroup
                value={person}
                onValueChange={(value) => {
                  setPerson(value);
                  setSlot(null);
                }}
                aria-labelledby="book-step-person"
                className="grid-cols-1 gap-2 sm:grid-cols-2"
              >
                {[
                  { value: ANYONE, label: t('person.anyone', 'Anyone who is free') },
                  ...providers.map((p) => ({ value: String(p.profile_id), label: p.display_name })),
                  ...(severalOn ? [{ value: EACH, label: t('person.each', 'A different person for each service') }] : []),
                ].map(
                  (choice) => (
                    <label
                      key={choice.value}
                      htmlFor={`book-person-${choice.value}`}
                      data-testid="book-person"
                      className={cn(
                        'flex h-12 cursor-pointer items-center gap-3 rounded-card border border-border bg-card px-4 transition-colors hover:bg-muted',
                        person === choice.value && 'border-primary bg-primary-tint hover:bg-primary-tint'
                      )}
                    >
                      <RadioGroupItem id={`book-person-${choice.value}`} value={choice.value} />
                      <span className="truncate text-foreground">{choice.label}</span>
                    </label>
                  )
                )}
              </RadioGroup>
              {severalOn && person === EACH && (
                <div className="grid gap-3 sm:grid-cols-2" data-testid="book-person-each">
                  {chosen.map((s, i) => (
                    <div key={`${s.id}-${i}`} className="space-y-1.5">
                      <label htmlFor={`book-each-${i}`} className="text-sm font-medium text-foreground">
                        {words.name(s.name)}
                      </label>
                      <FieldSelect
                        id={`book-each-${i}`}
                        value={each[i] ?? ANYONE}
                        onChange={(value) => {
                          setEach((current) => ({ ...current, [i]: value }));
                          setSlot(null);
                        }}
                        options={[
                          { value: ANYONE, label: t('person.anyone', 'Anyone who is free') },
                          ...page.staff
                            .filter((p) => s.staff_profile_ids.includes(p.profile_id))
                            .map((p) => ({ value: String(p.profile_id), label: p.display_name })),
                        ]}
                      />
                    </div>
                  ))}
                </div>
              )}
            </section>
          )}

          {/* 3. Day and time */}
          {service && (
            <section className="space-y-3" aria-labelledby="book-step-time">
              <SectionTitle n={showPerson ? 3 : 2} id="book-step-time">
                {t('step.time', 'Choose a day and time')}
              </SectionTitle>
              <ZoneNote timeZone={timeZone} />
              <FreeTimePicker
                id="book"
                timeZone={timeZone}
                earliest={today}
                latest={latest}
                load={load}
                loadKey={`${serviceId}:${person}:${severalKey}`}
                selected={slot}
                onSelect={(next) => {
                  setSlot(next);
                  if (next) setProblem(null);
                }}
                reloadKey={reloadKey}
              />
            </section>
          )}

          {/* 4. Details */}
          {service && slot && (
            <section className="space-y-4" aria-labelledby="book-step-details">
              <SectionTitle n={showPerson ? 4 : 3} id="book-step-details">
                {t('step.details', 'Your details')}
              </SectionTitle>
              <p className="text-sm text-muted-foreground">
                {t('field.email.hint', 'We email the confirmation and a link to change the booking.')}
              </p>
              {prefilled && (
                <p className="text-sm text-muted-foreground" data-testid="book-prefilled">
                  {t('field.prefilled', 'Filled in from your Whity account. You can change it.')}
                </p>
              )}
              <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                  id="book-name"
                  label={t('field.name.label', 'Your name')}
                  value={name}
                  onChange={setName}
                  autoComplete="name"
                  maxLength={200}
                  error={fieldErrors.name}
                  disabled={submitting}
                />
                <TextField
                  id="book-email"
                  label={t('field.email.label', 'Email')}
                  value={email}
                  onChange={setEmail}
                  type="email"
                  autoComplete="email"
                  ltr
                  maxLength={320}
                  error={fieldErrors.email}
                  disabled={submitting}
                />
                <TextField
                  id="book-phone"
                  label={t('field.phone.label', 'Phone (optional)')}
                  value={phone}
                  onChange={setPhone}
                  type="tel"
                  autoComplete="tel"
                  ltr
                  maxLength={40}
                  error={fieldErrors.phone}
                  disabled={submitting}
                />
              </div>
              <div className="space-y-1.5">
                <label htmlFor="book-note" className="text-sm font-medium text-foreground">
                  {t('field.note.label', 'Anything we should know? (optional)')}
                </label>
                <Textarea
                  id="book-note"
                  rows={3}
                  className="text-sm"
                  maxLength={1000}
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  disabled={submitting}
                />
              </div>

              {/* Hidden from people and from screen readers; only a bot fills it. */}
              <div aria-hidden="true" className="pointer-events-none absolute -start-[9999px] top-0 h-px w-px overflow-hidden opacity-0">
                <label htmlFor="book-website">Website</label>
                <input
                  id="book-website"
                  name="website"
                  type="text"
                  tabIndex={-1}
                  autoComplete="off"
                  value={website}
                  onChange={(e) => setWebsite(e.target.value)}
                  data-testid="book-website"
                />
              </div>
            </section>
          )}

          {service && slot && (
            <section className="space-y-4 rounded-card bg-muted px-4 py-4" aria-live="polite" data-testid="book-summary">
              <p className="text-foreground">
                <span className="block font-medium">
                  {providerName
                    ? t('summary.withPerson', '{service} with {person}', { service: serviceName, person: providerName })
                    : serviceName}
                </span>
                <span className="block" data-testid="book-summary-when">
                  {whenAt(slot.starts_at)}
                </span>
                {severalOn && (
                  <span className="block text-sm text-muted-foreground" data-testid="book-summary-total">
                    {t('more.total', 'In all: {time}', { time: words.duration(totalMinutes) ?? '' })}
                  </span>
                )}
                {words.price(totalPrice, service.currency) !== null && (
                  <span className="block text-sm text-muted-foreground">
                    <span dir="ltr" className="tabular-nums">
                      {words.price(totalPrice, service.currency)}
                    </span>
                  </span>
                )}
              </p>
              <InYourZone item={slot} timeZone={timeZone} />

              {page.bot_check?.site_key && (
                <div className="space-y-1.5">
                  <TurnstileWidget
                    siteKey={page.bot_check.site_key}
                    language={currentLanguage ?? 'en'}
                    theme={theme === 'dark' ? 'dark' : 'light'}
                    resetKey={resetKey}
                    onToken={onToken}
                    onError={onBotError}
                  />
                  {botFailed && (
                    <p className="text-sm text-destructive">
                      {t('bot.failed', 'The check didn’t load. Refresh the page and try again.')}
                    </p>
                  )}
                </div>
              )}

              {problem && (
                <Alert variant="destructive" data-testid="book-problem">
                  <AlertDescription className="text-sm">{problem}</AlertDescription>
                </Alert>
              )}

              <Button type="submit" size="lg" className="w-full sm:w-auto" loading={submitting} data-testid="book-submit">
                {sendsRequest ? t('submitRequest', 'Send request') : t('submit', 'Book this time')}
              </Button>
            </section>
          )}

          {problem && !(service && slot) && (
            <Alert variant="destructive" data-testid="book-problem">
              <AlertDescription className="text-sm">{problem}</AlertDescription>
            </Alert>
          )}
        </form>
      </CardContent>
    </Card>
  );
}

function TextField({
  id,
  label,
  hint,
  value,
  onChange,
  type = 'text',
  autoComplete,
  ltr = false,
  maxLength,
  error,
  disabled,
}: {
  id: string;
  label: string;
  hint?: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  autoComplete?: string;
  ltr?: boolean;
  maxLength?: number;
  error?: string;
  disabled?: boolean;
}) {
  const describedBy = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-medium text-foreground">
        {label}
      </label>
      <Input
        id={id}
        type={type}
        dir={ltr ? 'ltr' : undefined}
        autoComplete={autoComplete}
        value={value}
        maxLength={maxLength}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy}
        disabled={disabled}
      />
      {hint && (
        <p id={`${id}-hint`} className="text-sm text-muted-foreground">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="text-sm text-destructive" data-testid={`${id}-error`}>
          {error}
        </p>
      )}
    </div>
  );
}

/** After booking: what was booked, the link to change it, and a payment to make when one is held. */
function BookedView({ page, result }: { page: PublicBookingPage; result: BookedResult }) {
  const t = useTranslation('booking');
  const timeZone = safeTimeZone(page.settings.time_zone);
  const holdUntil = useDateDisplay({ timeZone }).time(result.payment?.hold_expires_at ?? null);
  const { booking, manageToken, payment } = result;
  const owes = payment !== null;
  const requested = booking.status === 'requested';

  return (
    <Card className="border-t-4 border-t-primary text-sm" data-testid="book-done">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-xl font-semibold">
          <Icon name={owes || requested ? 'clock' : 'success'} className="size-6 text-primary" />
          {owes
            ? t('done.payTitle', 'One more step: pay to confirm')
            : requested
              ? t('done.requestTitle', 'Request sent')
              : t('done.title', 'You’re booked')}
        </CardTitle>
        <CardDescription className="text-sm">
          {owes
            ? t('done.payDescription', 'We’re holding this time for you. It’s confirmed once you pay.')
            : requested
              ? t('done.requestDescription', 'We’re holding this time while {workspace} looks at your request. We’ll email {email} when they accept it or suggest another time.', {
                  workspace: page.workspace.name,
                  email: booking.customer.email,
                })
              : t('done.description', 'We’ve sent the details to {email}.', { email: booking.customer.email })}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        <BookingFacts booking={booking} timeZone={timeZone} />

        {payment && (
          <div className="space-y-3 rounded-card border border-warning bg-warning-tint px-4 py-4" data-testid="book-payment">
            <p className="text-foreground">
              {t('done.owed', 'To pay now:')}{' '}
              <span dir="ltr" className="font-semibold tabular-nums">
                {moneyText(payment.amount_minor, payment.currency)}
              </span>
              {payment.label ? <span className="block text-sm text-muted-foreground">{payment.label}</span> : null}
            </p>
            {holdUntil !== null && (
              <p className="text-sm text-muted-foreground">
                {t('done.holdUntil', 'If it isn’t paid by {time}, the time is given back.', { time: holdUntil })}
              </p>
            )}
            <Button asChild size="lg" className="w-full sm:w-auto" data-testid="book-pay">
              <a href={payment.url}>{t('done.pay', 'Go to payment')}</a>
            </Button>
          </div>
        )}

        {manageToken && (
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline" data-testid="book-ics">
              <a href={icsUrl(manageToken)} download="booking.ics">
                <Icon name="calendar" />
                {t('action.addToCalendar', 'Add to calendar')}
              </a>
            </Button>
            <Button asChild variant="outline" data-testid="book-manage">
              <a href={`/booking/${manageToken}`}>{t('action.manage', 'Change or cancel')}</a>
            </Button>
          </div>
        )}
        {manageToken && (
          <p className="text-sm text-muted-foreground">
            {t('done.keepLink', 'Keep the email we sent: its link lets you move or cancel this booking.')}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
