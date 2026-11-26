'use client';

/**
 * "No time that suits you? Join the waitlist" (WHIT-1442, design section 39
 * §8, AP.23), under the free times on the booking page. The customer says
 * which days and times of day suit them; when one frees up they are emailed
 * a link to take it, and the first to take it gets it. The same protection as
 * booking: a hidden field, time on the page, and the bot check when set up.
 */

import { useState, type RefObject } from 'react';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useCurrentLanguage, useTranslation } from '@whity/features/i18n';
import { useThemeMode } from '@/lib/theme-mode-context';
import { zonedDate } from '@whity/features/datetime';
import { TurnstileWidget } from '@/components/auth/turnstile-widget';
import { publicBookingApi, PublicBookingError, type PublicBookingPage, type WaitlistTime } from '@/lib/booking/public-api';
import { useBookingWords } from './use-booking-words';

const TIMES: WaitlistTime[] = ['morning', 'afternoon', 'evening'];

/** The time in ms, read only from the submit handler (kept out of render for the compiler). */
function nowMs(): number {
  return Date.now();
}

function ymdOf(d: { year: number; month: number; day: number }): string {
  return `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')}`;
}

export function WaitlistJoin({
  workspace,
  page,
  serviceId,
  profileId,
  timeZone,
  loadedAt,
  defaults,
}: {
  workspace: string;
  page: PublicBookingPage;
  serviceId: number;
  /** The person chosen, or null for anyone. */
  profileId: number | null;
  timeZone: string;
  loadedAt: RefObject<number | null>;
  /** What the customer already typed on the page. */
  defaults: { name: string; email: string; phone: string };
}) {
  const t = useTranslation('booking');
  const words = useBookingWords();
  const { currentLanguage } = useCurrentLanguage();
  const { resolved: theme } = useThemeMode();
  const today = ymdOf(zonedDate(timeZone));
  const [open, setOpen] = useState(false);
  const [name, setName] = useState(defaults.name);
  const [email, setEmail] = useState(defaults.email);
  const [phone, setPhone] = useState(defaults.phone);
  const [from, setFrom] = useState(today);
  const [to, setTo] = useState(today);
  const [times, setTimes] = useState<WaitlistTime[]>([]);
  const [website, setWebsite] = useState('');
  const [botToken, setBotToken] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [joined, setJoined] = useState(false);

  if (joined) {
    return (
      <p className="rounded-card border border-border bg-success-tint px-4 py-3 text-success-text" role="status" data-testid="waitlist-joined">
        {t('waitlist.joined', 'You’re on the waitlist. We’ll email you as soon as a time that suits you frees up; the first to take it gets it.')}
      </p>
    );
  }
  if (!open) {
    return (
      <Button variant="outline" onClick={() => setOpen(true)} data-testid="waitlist-open">
        {t('waitlist.open', 'No time that suits you? Join the waitlist')}
      </Button>
    );
  }

  const join = async () => {
    setSaving(true);
    setProblem(null);
    try {
      const opened = loadedAt.current ?? nowMs();
      await publicBookingApi.joinWaitlist(workspace, {
        service_id: serviceId,
        ...(profileId !== null ? { profile_id: profileId } : {}),
        name: name.trim(),
        email: email.trim(),
        ...(phone.trim() !== '' ? { phone: phone.trim() } : {}),
        date_from: from,
        date_to: to,
        times,
        language: currentLanguage === 'ar' ? 'ar' : 'en',
        elapsed_ms: Math.max(0, Math.round(nowMs() - opened)),
        website,
        ...(botToken ? { bot_token: botToken } : {}),
      });
      setJoined(true);
    } catch (error) {
      setProblem(
        error instanceof PublicBookingError && error.status === 429
          ? t('error.tooMany', 'Too many tries from this connection. Wait a few minutes and try again.')
          : words.errorText(error, t('waitlist.error', 'We couldn’t add you. Check your details and try again.'))
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-4 rounded-card border border-border px-4 py-4" data-testid="waitlist-form">
      <div className="space-y-1">
        <p className="m-0 font-medium text-foreground">{t('waitlist.title', 'Join the waitlist')}</p>
        <p className="m-0 text-sm text-muted-foreground">{t('waitlist.lead', 'Tell us which days and times suit you. When one frees up, we email everyone who wants it; the first to take it gets it.')}</p>
      </div>
      <Input id="waitlist-name" label={t('field.name', 'Your name')} value={name} autoComplete="name" onChange={(e) => setName(e.target.value)} />
      <Input id="waitlist-email" type="email" dir="ltr" label={t('field.email', 'Email')} value={email} autoComplete="email" onChange={(e) => setEmail(e.target.value)} />
      <Input id="waitlist-phone" type="tel" dir="ltr" label={t('waitlist.phone', 'Phone (optional, for WhatsApp or SMS)')} value={phone} autoComplete="tel" onChange={(e) => setPhone(e.target.value)} />
      <div className="grid gap-4 sm:grid-cols-2">
        <Input id="waitlist-from" type="date" dir="ltr" label={t('waitlist.from', 'From')} value={from} min={today} onChange={(e) => setFrom(e.target.value)} />
        <Input id="waitlist-to" type="date" dir="ltr" label={t('waitlist.to', 'Until')} value={to} min={from} onChange={(e) => setTo(e.target.value)} />
      </div>
      <fieldset className="space-y-2">
        <legend className="text-sm font-medium text-foreground">{t('waitlist.times', 'Times of day')}</legend>
        <div className="flex flex-wrap gap-4">
          {TIMES.map((time) => (
            <label key={time} className="flex min-h-12 items-center gap-2">
              <Checkbox checked={times.includes(time)} onCheckedChange={(on) => setTimes((current) => (on === true ? [...current, time] : current.filter((x) => x !== time)))} />
              {time === 'morning' ? t('waitlist.morning', 'Mornings') : time === 'afternoon' ? t('waitlist.afternoon', 'Afternoons') : t('waitlist.evening', 'Evenings')}
            </label>
          ))}
        </div>
        <p className="m-0 text-sm text-muted-foreground">{t('waitlist.anyTime', 'Leave them empty for any time of day.')}</p>
      </fieldset>
      {/* People never see this field. */}
      <input type="text" name="website" value={website} onChange={(e) => setWebsite(e.target.value)} tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />
      {page.bot_check?.site_key && (
        <TurnstileWidget siteKey={page.bot_check.site_key} language={currentLanguage ?? 'en'} theme={theme === 'dark' ? 'dark' : 'light'} resetKey={0} onToken={setBotToken} onError={() => setProblem(t('bot.failed', 'The check didn’t load. Refresh the page and try again.'))} />
      )}
      {problem && <p className="m-0 text-destructive" role="alert">{problem}</p>}
      <Button variant="outline" onClick={() => void join()} loading={saving} data-testid="waitlist-join">
        {t('waitlist.join', 'Join the waitlist')}
      </Button>
    </div>
  );
}
