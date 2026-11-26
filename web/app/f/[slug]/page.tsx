'use client';

/**
 * The page a PUBLIC form link actually opens.
 *
 * WHY THIS EXISTS. The public form API shipped first and `public_url` pointed
 * straight at it, so the link a tenant copied and sent to an applicant returned
 * raw JSON in their browser. An endpoint is not a form. This is the half a
 * person sees.
 *
 * IT IS DELIBERATELY OUTSIDE `(protected)`. The whole point is that the reader
 * has no account, so nothing here may touch the auth context or any chrome that
 * assumes a session. It sits beside `login` and `verify/[token]` — the other
 * pages a stranger can open.
 *
 * IT ASKS FOR NOTHING IT DOES NOT NEED. No name, no email, no "who are you";
 * the form's own fields are the whole ask. Prefill cannot resolve for an
 * anonymous caller and the API sends none, so nothing here could quietly
 * identify the person filling it in.
 *
 * ONE REFUSAL SHAPE. A wrong slug, a withdrawn link, an unpublished form and a
 * malformed slug all return the same 404 by design, so a stranger cannot probe
 * which links exist. This page echoes the server's wording rather than guessing
 * a more specific reason and undoing that.
 */

import { use, useCallback, useEffect, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Button } from '@whity/ui/button';
import { FormHeader, type FormBannerValue } from '@whity/features/forms';
import { BuiltOnWhity } from '@whity/ui/built-on-whity';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { useBranding } from '@/lib/branding-context';
import { useDirection } from '@/lib/direction-context';
import { useDateDisplay } from '@whity/features/datetime';
import { LanguageSwitcher, useCurrentLanguage, useTranslation } from '@whity/features/i18n';
import { TurnstileWidget } from '@/components/auth/turnstile-widget';
import { useThemeMode } from '@/lib/theme-mode-context';
import {
  type Answer, type LocalizedText, type UploadedFileRef
} from '@/components/forms/form-fields';
import { FormFill, formContentLanguage, type FillableField, type FormSectionSpec } from '@/components/forms/form-fill';
import { ContentDirection } from '@whity/ui/content-direction';
import { useRefusalMessage, type RefusalBody } from '@/components/forms/form-refusals';
import { api } from '@/lib/api/client';
import { localizedText } from '@whity/ui/localized-text';

interface PublicForm {
  slug: string;
  name: LocalizedText | string | null;
  description: string | null;
  description_i18n?: LocalizedText | [] | null;
  submit_label?: LocalizedText | null;
  /** The heading and picture written for the person filling it in (WHIT-944). */
  title?: LocalizedText | null;
  banner?: FormBannerValue | null;
  fields: FillableField[];
  sections: FormSectionSpec[];
  accepts_submissions: boolean;
  opens_at: string | null;
  closes_at: string | null;
  /** WHIT-920: the visitor signs in and fills it in at /forms/{form_id}, under their own name. */
  sign_in_required?: boolean;
  form_id?: number | null;
  /** WHIT-932: false when the workspace's plan removes "Built on Whity". */
  built_on_whity?: boolean;
  /** READINESS TM-20: set when the submit asks for a bot-check token. */
  bot_check?: { provider: string; site_key: string } | null;
  /** WHIT-1443 (AP.20): from a booking's form link, what the customer gave when they booked. */
  intake?: { name: string; email: string; phone: string } | null;
}

/**
 * WHIT-1443 (AP.20): a booking's form link fills in what the customer already
 * gave, in the text fields whose key says what they hold: one for an email,
 * one for a phone or mobile, one for a name. Anything they typed stays.
 */
function intakeAnswers(fields: FillableField[], intake: NonNullable<PublicForm['intake']>): Record<string, Answer> {
  const out: Record<string, Answer> = {};
  for (const field of fields) {
    const key = field.field_key;
    if (field.field_type !== 'text') continue;
    if (/e-?mail/i.test(key) && intake.email !== '') out[key] = intake.email;
    else if (/phone|mobile/i.test(key) && intake.phone !== '') out[key] = intake.phone;
    else if (/name/i.test(key) && intake.name !== '') out[key] = intake.name;
  }
  return out;
}

/**
 * What a sign-in started here came back with (#2355): `?sso=` on this page.
 * The server joins a new person to the form's workspace under its sign-up
 * rules, or sends them back here with one of these.
 */
type SsoOutcome = 'pending' | 'domain' | 'refused' | 'closed' | 'email_unverified' | 'link_conflict' | 'provider_unavailable' | 'failed';

const SSO_OUTCOMES: readonly SsoOutcome[] = [
  'pending', 'domain', 'refused', 'closed', 'email_unverified', 'link_conflict', 'provider_unavailable', 'failed',
];

/**
 * Sign in from this form (#2355). The login page hands `form` to the sign-in
 * provider, so a new person joins this form's workspace and comes back to the
 * form, and `next` brings a password sign-in back to it too.
 */
function signInHref(slug: string, formId: number): string {
  return `/login?${new URLSearchParams({ form: slug, next: `/forms/${formId}` }).toString()}`;
}

export default function PublicFormPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = use(params);
  const { dir } = useDirection();
  const preferArabic = dir === 'rtl';
  // Safe with no provider above it — the hook is documented as non-throwing and
  // falls back to "dates are shown", which is what a page a stranger opens has
  // always done. Nothing here reads a session.
  const { dateTime } = useDateDisplay();
  const t = useTranslation('forms');
  const refusalMessage = useRefusalMessage();

  const [form, setForm] = useState<PublicForm | null>(null);
  // Which failure, not its wording: the words are chosen when they are drawn, so a
  // language loading late can't restart the fetch below.
  const [loadError, setLoadError] = useState<'notValid' | 'loadFailed' | null>(null);
  const [loading, setLoading] = useState(true);
  const [answers, setAnswers] = useState<Record<string, Answer>>({});
  const [submitted, setSubmitted] = useState(false);
  const [reference, setReference] = useState<string | null>(null);
  const [ssoOutcome, setSsoOutcome] = useState<SsoOutcome | null>(null);
  // The bot check (TM-20): a token is single use, so a fresh one is asked
  // for after every send. `website` is the trap only a bot fills in.
  const { currentLanguage } = useCurrentLanguage();
  const { resolved: theme } = useThemeMode();
  const [botToken, setBotToken] = useState<string | null>(null);
  const [botFailed, setBotFailed] = useState(false);
  const [resetKey, setResetKey] = useState(0);
  const [website, setWebsite] = useState('');
  const [intake, setIntake] = useState<string | null>(null);
  const [kiosk, setKiosk] = useState(false);
  const onToken = useCallback((token: string | null) => {
    setBotToken(token);
    if (token !== null) setBotFailed(false);
  }, []);
  const onBotError = useCallback(() => setBotFailed(true), []);

  useEffect(() => {
    let cancelled = false;
    const search = new URLSearchParams(window.location.search);
    const returned = search.get('sso');
    const outcome = SSO_OUTCOMES.find((o) => o === returned) ?? null;
    // WHIT-1443 (AP.20): a booking's form link, and the desk's tablet.
    const intakeToken = search.get('intake');
    const token = intakeToken !== null && /^[a-f0-9]{64}$/.test(intakeToken) ? intakeToken : null;
    const atTheDesk = search.get('kiosk') === '1';

    api.GET('/api/v1/public/forms/{slug}', { params: { path: { slug }, ...(token !== null ? { query: { intake: token } } : {}) } })
      .then(({ data, error, response }) => {
        const body = ((data ?? error ?? {}) as unknown) as { data?: PublicForm; error?: string };
        if (cancelled) return;
        if (!response.ok || !body.data) {
          // A wrong slug, a withdrawn link and an unpublished form are one
          // refusal by design, so this page's wording can stand in for the server's.
          setLoadError('notValid');

          return;
        }
        setForm(body.data);
        setIntake(token);
        setKiosk(atTheDesk);
        if (body.data.intake) {
          const filled = intakeAnswers(body.data.fields, body.data.intake);
          setAnswers((current) => ({ ...filled, ...current }));
        }
        setSsoOutcome(outcome);
      })
      .catch(() => {
        if (!cancelled) setLoadError('loadFailed');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [slug]);

  function labelOf(fieldKey: string): string {
    const field = form?.fields.find((candidate) => candidate.field_key === fieldKey);

    return (field !== undefined ? localizedText(field.label, preferArabic ? 'ar' : 'en') : '') || fieldKey;
  }

  /**
   * Upload one file against the PUBLIC form, and hand back the reference the
   * `file` answer will carry.
   *
   * A `file` field used to be stripped from this surface entirely, on the
   * grounds that every upload route was gated and a stranger could not produce
   * a reference — true about the platform, not about the field. The server now
   * serves an anonymous upload route, because unlike the person and unit
   * pickers this page still never shows, a file input asks the organisation
   * NOTHING: no list, no id resolved against their records, and one opaque
   * reference to the visitor's own bytes coming back.
   *
   * Same multipart shape as the authenticated page, and the same reason for not
   * setting `Content-Type` by hand (the boundary is the browser's to write).
   * The limits differ — the server refuses at half the size on this surface and
   * throttles more tightly — and the server's own sentence is what gets shown,
   * so this page needs to know neither number.
   */
  async function uploadAttachment(file: File): Promise<UploadedFileRef> {
    const body = new FormData();
    body.append('file', file);

    const { data: uploaded, error: uploadError, response } = await api.POST('/api/v1/public/forms/{slug}/uploads', {
      params: { path: { slug } },
      body: body as never,
      bodySerializer: (b: unknown) => b as FormData,
    });
    const payload = ((uploaded ?? uploadError ?? {}) as unknown) as RefusalBody & { data?: UploadedFileRef };
    if (!response.ok || payload.data === undefined) {
      throw new Error(
        refusalMessage(payload, labelOf, t('field.uploadFailed', 'That file could not be uploaded. Please try again.'))
      );
    }

    return payload.data;
  }

  /** Resolves with a refusal, or null once the server has the answers. */
  async function submitAnswers(toSend: Record<string, Answer>): Promise<RefusalBody | null> {
    if (form?.bot_check && botToken === null) {
      return { error: t('fill.botPending', 'Wait for the check beside the button to finish, then try again.') };
    }
    // The client sends the CSRF header (`X-Requested-With`) a cookie-authenticated write needs.
    const { data: sentBody, error: sentError, response } = await api.POST('/api/v1/public/forms/{slug}/submissions', {
      params: { path: { slug } },
      body: { data: toSend, website, ...(botToken !== null ? { bot_token: botToken } : {}), ...(intake !== null ? { intake } : {}) } as never,
    });
    const body = ((sentBody ?? sentError ?? {}) as unknown) as RefusalBody & { data?: { reference?: string | null } };
    if (!response.ok) {
      if (form?.bot_check) {
        setBotToken(null);
        setResetKey((k) => k + 1);
      }

      return body;
    }

    setReference(body.data?.reference ?? null);
    setSubmitted(true);

    return null;
  }

  // The workspace's brand leads: its logo above the card, and its colour on the
  // card's edge, the main button and the focus rings (the theme is already the
  // workspace's, set for the whole app from its branding).
  const branding = useBranding();
  const shell = (children: React.ReactNode) => (
    <main className="flex min-h-screen flex-col items-center bg-background px-4 py-10">
      <div className="w-full max-w-2xl flex-1 space-y-6">
        <div className="flex justify-center">
          <TenantLogo
            name={branding.siteName}
            src={branding.logoWideUrl ?? branding.logoSquareUrl}
            shape={branding.logoWideUrl ? 'wide' : 'square'}
            size={40}
          />
        </div>
        {children}
      </div>
      <footer className="mt-10 flex w-full max-w-2xl flex-wrap items-center justify-between gap-3">
        {/* WHIT-932: the workspace's plan may remove the credit. Until the form
            is known nothing is shown, so a workspace that removed it never sees
            it flash in while the page loads. */}
        {form !== null && form.built_on_whity !== false && (
          <BuiltOnWhity label={t('fill.builtOn', 'Built on Whity')} />
        )}
        <LanguageSwitcher
          variant="dropdown"
          className="h-10 cursor-pointer rounded-control border border-input bg-card px-2.5 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
        />
      </footer>
    </main>
  );

  if (loading) {
    return shell(
      <Card>
        <CardContent className="py-10 text-center text-sm text-muted-foreground">{t('fill.loading', 'Loading…')}</CardContent>
      </Card>
    );
  }

  if (loadError !== null || form === null) {
    return shell(
      <Card className="border-t-4 border-t-primary">
        <CardHeader>
          <CardTitle>{t('fill.notAvailable', 'This form is not available')}</CardTitle>
          <CardDescription>
            {loadError === 'loadFailed'
              ? t('fill.loadFailed', 'This form could not be loaded. Check your connection and try again.')
              : t('fill.notValid', 'This form link is not valid, or is no longer open. Check the link you were given.')}
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  const title =
    localizedText(form.title ?? null, preferArabic ? 'ar' : 'en') || localizedText(form.name, preferArabic ? 'ar' : 'en') || t('fill.untitled', 'Form');
  const closedOn = dateTime(form.closes_at);
  const translatedDescription = Array.isArray(form.description_i18n) ? null : form.description_i18n;
  const description = localizedText(translatedDescription, preferArabic ? 'ar' : 'en') || form.description;
  const submitLabel = localizedText(form.submit_label, preferArabic ? 'ar' : 'en') || t('fill.send', 'Send');
  // WHIT-1379: the form reads in its own language's direction; the page around it keeps the interface's.
  const content = formContentLanguage(form, form.fields, form.sections ?? [], preferArabic);

  if (submitted) {
    return shell(
      <Card className="border-t-4 border-t-primary">
        <CardHeader>
          <CardTitle>{t('fill.sentPublicTitle', 'Thank you, your response has been recorded')}</CardTitle>
          <CardDescription>
            {kiosk
              ? t('fill.sentKiosk', 'Thank you. Please hand the tablet back to the desk.')
              : t('fill.sentPublic', '{title} was submitted successfully. You may close this page.', { title })}
          </CardDescription>
          {reference !== null && (
            <p className="text-sm font-medium">{t('fill.reference', 'Your reference: {reference}', { reference })}</p>
          )}
        </CardHeader>
      </Card>
    );
  }

  return shell(
    // A banner leads in place of the brand-coloured top edge.
    <Card className={form.banner ? undefined : 'border-t-4 border-t-primary'}>
      <FormHeader title={title} description={description} banner={form.banner} content={content} />

      <CardContent>
        {form.accepts_submissions && form.sign_in_required === true && typeof form.form_id === 'number' ? (
          <div className="space-y-3 py-6">
            {ssoOutcome !== null && (
              <p role="status" className="text-sm" data-testid="form-sso-outcome">
                {ssoOutcomeText(ssoOutcome, t)}
              </p>
            )}
            <p className="text-sm text-muted-foreground">
              {t('fill.signInRequired', 'Sign in to fill in this form. It is sent under your own name, so you can follow it and make changes if you are asked to.')}
            </p>
            <Button asChild>
              <a href={signInHref(slug, form.form_id)}>{t('fill.signIn', 'Sign in to fill it in')}</a>
            </Button>
          </div>
        ) : !form.accepts_submissions ? (
          <p className="py-6 text-sm text-muted-foreground">
            {/*
              No `?? form.closes_at` fallback: when the formatter declines, the
              date goes, rather than printing the wire timestamp in the browser's
              locale. The form being closed is a complete answer on its own.
            */}
            {closedOn !== null
              ? t('fill.closedOn', 'This form is not accepting responses. It closed on {date}.', { date: closedOn })
              : t('fill.closed', 'This form is not accepting responses.')}
          </p>
        ) : (
          <ContentDirection content={content}>
            <FormFill
              fields={form.fields}
              sections={form.sections ?? []}
              answers={answers}
              onAnswersChange={setAnswers}
              preferArabic={preferArabic}
              upload={uploadAttachment}
              submitLabel={submitLabel}
              submit={submitAnswers}
              footer={
                <>
                  {/* Hidden from people and from screen readers; only a bot fills it. */}
                  <div aria-hidden="true" className="pointer-events-none absolute -start-[9999px] top-0 h-px w-px overflow-hidden opacity-0">
                    <label htmlFor="form-website">Website</label>
                    <input
                      id="form-website"
                      name="website"
                      type="text"
                      tabIndex={-1}
                      autoComplete="off"
                      value={website}
                      onChange={(e) => setWebsite(e.target.value)}
                    />
                  </div>
                  {form.bot_check?.site_key && (
                    <span className="block space-y-1.5" data-testid="form-bot-check">
                      <TurnstileWidget
                        siteKey={form.bot_check.site_key}
                        language={currentLanguage ?? 'en'}
                        theme={theme === 'dark' ? 'dark' : 'light'}
                        resetKey={resetKey}
                        onToken={onToken}
                        onError={onBotError}
                      />
                      {botFailed && (
                        <span className="block text-sm text-destructive">
                          {t('fill.botFailed', 'The check didn’t load. Refresh the page and try again.')}
                        </span>
                      )}
                    </span>
                  )}
                </>
              }
            />
          </ContentDirection>
        )}
      </CardContent>
    </Card>
  );
}

/** What came back from signing in from this form, in the reader's words (#2355). */
function ssoOutcomeText(outcome: SsoOutcome, t: ReturnType<typeof useTranslation>): string {
  switch (outcome) {
    case 'pending':
      return t('fill.sso.pending', 'Your request to join is waiting for the workspace to approve it. You can fill in this form once it is accepted.');
    case 'domain':
      return t('fill.sso.domain', 'This workspace only accepts sign-ups from some email domains, and that account’s email isn’t on one of them. Sign in with an address it accepts.');
    case 'refused':
      return t('fill.sso.refused', 'That account can’t join this workspace by signing up. Contact the workspace if you think this is wrong.');
    case 'closed':
      return t('fill.sso.closed', 'This workspace isn’t taking sign-ups, so only its members can fill in this form. Ask the workspace to invite you.');
    case 'email_unverified':
      return t('fill.sso.unverified', 'That account’s email isn’t verified yet. Verify it, then try again.');
    case 'link_conflict':
      return t('fill.sso.conflict', 'An account with that email already exists but its email isn’t confirmed. Sign in with email and confirm it first.');
    case 'provider_unavailable':
      return t('fill.sso.unavailable', 'The sign-in service couldn’t be reached. Try again later.');
    default:
      return t('fill.sso.failed', 'Signing in didn’t work. Try again.');
  }
}
