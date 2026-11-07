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

import { use, useEffect, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { FormHeader, type FormBannerValue } from '@whity/features/forms';
import { BuiltOnWhity } from '@whity/ui/built-on-whity';
import { TenantLogo } from '@whity/ui/tenant-logo';
import { useBranding } from '@/lib/branding-context';
import { useDirection } from '@/lib/direction-context';
import { useDateDisplay } from '@whity/features/datetime';
import { LanguageSwitcher, useTranslation } from '@whity/features/i18n';
import {
  localized,
  type Answer,
  type LocalizedText,
  type UploadedFileRef,
} from '@/components/forms/form-fields';
import { FormFill, type FillableField, type FormSectionSpec } from '@/components/forms/form-fill';
import { useRefusalMessage, type RefusalBody } from '@/components/forms/form-refusals';

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

  useEffect(() => {
    let cancelled = false;

    fetch(`/api/v1/public/forms/${encodeURIComponent(slug)}`, { headers: { Accept: 'application/json' } })
      .then(async (response) => {
        const body = (await response.json().catch(() => ({}))) as { data?: PublicForm; error?: string };
        if (cancelled) return;
        if (!response.ok || !body.data) {
          // A wrong slug, a withdrawn link and an unpublished form are one
          // refusal by design, so this page's wording can stand in for the server's.
          setLoadError('notValid');

          return;
        }
        setForm(body.data);
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

    return (field !== undefined ? localized(field.label, preferArabic) : '') || fieldKey;
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

    const response = await fetch(`/api/v1/public/forms/${encodeURIComponent(slug)}/uploads`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body,
    });
    const payload = (await response.json().catch(() => ({}))) as RefusalBody & { data?: UploadedFileRef };
    if (!response.ok || payload.data === undefined) {
      throw new Error(
        refusalMessage(payload, labelOf, t('field.uploadFailed', 'That file could not be uploaded. Please try again.'))
      );
    }

    return payload.data;
  }

  /** Resolves with a refusal, or null once the server has the answers. */
  async function submitAnswers(toSend: Record<string, Answer>): Promise<RefusalBody | null> {
    const response = await fetch(`/api/v1/public/forms/${encodeURIComponent(slug)}/submissions`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        // The CSRF guard requires this on a cookie-authenticated write. It is
        // NOT optional here: a bearer token is exempt from the guard, so every
        // curl check of this endpoint passed while the browser — which has a
        // session cookie and no token — was refused with "cross-site request
        // rejected". The uploader already sent it; the submit did not.
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ data: toSend }),
    });
    const body = (await response.json().catch(() => ({}))) as RefusalBody & { data?: { reference?: string | null } };
    if (!response.ok) return body;

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
        <BuiltOnWhity label={t('fill.builtOn', 'Built on Whity')} />
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
    localized(form.title ?? null, preferArabic) || localized(form.name, preferArabic) || t('fill.untitled', 'Form');
  const closedOn = dateTime(form.closes_at);
  const translatedDescription = Array.isArray(form.description_i18n) ? null : form.description_i18n;
  const description = localized(translatedDescription, preferArabic) || form.description;
  const submitLabel = localized(form.submit_label, preferArabic) || t('fill.send', 'Send');

  if (submitted) {
    return shell(
      <Card className="border-t-4 border-t-primary">
        <CardHeader>
          <CardTitle>{t('fill.sentPublicTitle', 'Thank you, your response has been recorded')}</CardTitle>
          <CardDescription>
            {t('fill.sentPublic', '{title} was submitted successfully. You may close this page.', { title })}
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
      <FormHeader title={title} description={description} banner={form.banner} />

      <CardContent>
        {!form.accepts_submissions ? (
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
          <FormFill
            fields={form.fields}
            sections={form.sections ?? []}
            answers={answers}
            onAnswersChange={setAnswers}
            preferArabic={preferArabic}
            upload={uploadAttachment}
            submitLabel={submitLabel}
            submit={submitAnswers}
          />
        )}
      </CardContent>
    </Card>
  );
}
