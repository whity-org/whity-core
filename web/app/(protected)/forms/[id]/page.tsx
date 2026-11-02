'use client';

/**
 * Filling a PUBLISHED form as a signed-in member.
 *
 * WHY THERE ARE TWO FILL PAGES. A published form is not necessarily a public
 * one. Opening a form to the world is a deliberate, separate act — so a form
 * that is merely published needs an address its own people can use, and that
 * address is this one. `/f/{slug}` is the other case: a link deliberately
 * handed to somebody with no account.
 *
 * WHAT DIFFERS BESIDES WHO MAY OPEN IT. Prefill. A signed-in member's saved
 * details resolve here and seed the answers, which is exactly what must NOT
 * happen anonymously — so the two pages call different endpoints rather than
 * one endpoint behaving differently depending on who asked. Any field the
 * server could not resolve a prefill for is reported rather than silently left
 * blank, because "we tried to fill this and could not" and "this was never
 * meant to be filled" look identical once the box is empty.
 */

import { use, useEffect, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Button } from '@whity/ui/button';
import { useDirection } from '@/lib/direction-context';
import { apiClient } from '@/lib/api-client';
import { useTranslation } from '@whity/features/i18n';
import {
  localized,
  type Answer,
  type LocalizedText,
  type ReferenceOption,
  type UploadedFileRef,
} from '@/components/forms/form-fields';
import { FormFill, type FillableField, type FormSectionSpec } from '@/components/forms/form-fill';
import { useRefusalMessage, type RefusalBody } from '@/components/forms/form-refusals';

interface RenderedForm {
  form: {
    id: number;
    name: LocalizedText | string | null;
    description: string | null;
    description_i18n?: LocalizedText | [] | null;
    submit_label?: LocalizedText | null;
    status: string;
    public_enabled?: boolean;
    public_url?: string | null;
  };
  fields: FillableField[];
  sections: FormSectionSpec[];
  prefill: Record<string, unknown>;
  unresolved_prefill: Array<{ field_key: string }>;
  accepts_submissions: boolean;
}

/** Per-form, per-browser. Never sent anywhere. */
function draftKey(id: string): string {
  return `whity:form-draft:${id}`;
}

function readDraft(id: string): Record<string, Answer> {
  try {
    const raw = window.localStorage.getItem(draftKey(id));
    if (raw === null) return {};
    const parsed: unknown = JSON.parse(raw);

    return parsed !== null && typeof parsed === 'object' ? (parsed as Record<string, Answer>) : {};
  } catch {
    // Private windows, cleared site data and browsers set to block storage all
    // land here. A draft is a convenience; losing it must never cost the form.
    return {};
  }
}

export default function FillFormPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { dir } = useDirection();
  const preferArabic = dir === 'rtl';
  const t = useTranslation('forms');
  const refusalMessage = useRefusalMessage();

  const [rendered, setRendered] = useState<RenderedForm | null>(null);
  // Which failure, not its wording: the words are chosen when they are drawn, so a
  // language loading late can't refetch the form and reseed the answers below.
  const [loadError, setLoadError] = useState<'notOpened' | 'loadFailed' | null>(null);
  const [loading, setLoading] = useState(true);
  const [answers, setAnswers] = useState<Record<string, Answer>>({});
  const [submitted, setSubmitted] = useState(false);
  const [reference, setReference] = useState<string | null>(null);
  const [units, setUnits] = useState<ReferenceOption[]>([]);
  const [restored, setRestored] = useState(false);

  // The unit list for any `ou_ref` field. Fetched by the PAGE rather than by the
  // field, because whether this reader may see a list of the tenant's units is a
  // question about the reader, not about the field — and the public page
  // deliberately never asks it.
  useEffect(() => {
    let cancelled = false;

    apiClient('/api/v1/ous', { headers: { Accept: 'application/json' } })
      .then(async (response) => {
        if (!response.ok) return;
        const body = (await response.json().catch(() => ({}))) as {
          data?: Array<{ id?: number; name?: string; ou_type_key?: string | null }>;
        };
        if (cancelled) return;
        setUnits(
          (body.data ?? [])
            .filter((ou): ou is { id: number; name: string; ou_type_key?: string | null } =>
              typeof ou.id === 'number' && typeof ou.name === 'string')
            // The kind travels with the option so a field constrained to
            // `ou_type` can narrow this one list rather than refetching.
            .map((ou) => ({ value: String(ou.id), label: ou.name, type: ou.ou_type_key ?? null }))
        );
      })
      .catch(() => {
        // A missing list leaves the picker empty and the field refusable by the
        // server; it must not take the whole form down with it.
      });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    let cancelled = false;

    apiClient(`/api/v1/forms/${encodeURIComponent(id)}/render`, { headers: { Accept: 'application/json' } })
      .then(async (response) => {
        const body = (await response.json().catch(() => ({}))) as { data?: RenderedForm; error?: string };
        if (cancelled) return;
        if (!response.ok || !body.data) {
          // Only a 404 or a permission refusal reaches here, and neither says
          // more than this to the person looking at it.
          setLoadError('notOpened');

          return;
        }
        setRendered(body.data);

        // Seed from the server's resolved prefill. Only strings are seeded: a
        // prefill that arrived as some other shape is a mismatch between the
        // source and the field, and guessing a coercion would put a value in
        // front of somebody as though they had entered it.
        const seed: Record<string, Answer> = {};
        for (const [key, value] of Object.entries(body.data.prefill ?? {})) {
          if (typeof value === 'string' && value !== '') seed[key] = value;
        }
        // Prefill first, then whatever was already typed — a draft is a
        // later statement of intent than a default, so it wins.
        const draft = readDraft(id);
        setAnswers({ ...seed, ...draft });
        setRestored(Object.keys(draft).length > 0);
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
  }, [id]);

  // Keep the draft current. File answers are deliberately EXCLUDED: an upload
  // reference is single-use, so restoring one would hand the server a reference
  // it has already spent and refuse the submission for a reason nobody can see.
  useEffect(() => {
    if (rendered === null || submitted) return;

    const fileKeys = new Set(
      rendered.fields.filter((f) => f.field_type === 'file').map((f) => f.field_key)
    );
    const keep = Object.fromEntries(Object.entries(answers).filter(([key]) => !fileKeys.has(key)));

    try {
      window.localStorage.setItem(draftKey(id), JSON.stringify(keep));
    } catch {
      // Storage unavailable or full — the form still works.
    }
  }, [answers, id, rendered, submitted]);

  function discardDraft() {
    try {
      window.localStorage.removeItem(draftKey(id));
    } catch {
      // nothing to do
    }
  }

  function labelOf(fieldKey: string): string {
    const field = rendered?.fields.find((candidate) => candidate.field_key === fieldKey);

    return (field !== undefined ? localized(field.label, preferArabic) : '') || fieldKey;
  }

  /**
   * Upload one file and hand back the reference the `file` answer will carry.
   *
   * MULTIPART via FormData, and the `Content-Type` header is deliberately NOT
   * set: the browser has to write it itself so it can include the boundary it
   * generated. Setting it by hand produces a body no parser can read, with a
   * header that says it should be readable.
   *
   * `X-Requested-With` because this call carries the session cookie, and
   * CsrfGuard requires the custom header on any state-changing request with an
   * ambient credential. A cross-site page cannot set it without a preflight the
   * origin allowlist refuses.
   *
   * THROWS the refusal in the reader's language. The server is the only party that
   * knows the size ceiling and the accepted kinds, and it sends them as a reason
   * and numbers for {@link useRefusalMessage} to word.
   */
  async function uploadAttachment(file: File): Promise<UploadedFileRef> {
    const body = new FormData();
    body.append('file', file);

    const response = await apiClient(`/api/v1/forms/${encodeURIComponent(id)}/uploads`, {
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
    const response = await apiClient(`/api/v1/forms/${encodeURIComponent(id)}/submissions`, {
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
    // Only once the server has it. Clearing on the attempt would lose the
    // answers precisely when the attempt failed.
    discardDraft();
    setSubmitted(true);

    return null;
  }

  if (loading) {
    return (
      <Card>
        <CardContent className="py-10 text-center text-sm text-muted-foreground">{t('fill.loading', 'Loading…')}</CardContent>
      </Card>
    );
  }

  if (loadError !== null || rendered === null) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('fill.notAvailable', 'This form is not available')}</CardTitle>
          <CardDescription>
            {loadError === 'loadFailed'
              ? t('fill.loadFailed', 'This form could not be loaded. Check your connection and try again.')
              : t('fill.notOpened', 'This form could not be opened.')}
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  const title = localized(rendered.form.name, preferArabic) || t('fill.untitled', 'Form');
  const translatedDescription = Array.isArray(rendered.form.description_i18n) ? null : rendered.form.description_i18n;
  const description = localized(translatedDescription, preferArabic) || rendered.form.description;
  const submitLabel = localized(rendered.form.submit_label, preferArabic) || t('fill.submit', 'Submit');

  if (submitted) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('fill.sentMemberTitle', 'Submitted')}</CardTitle>
          <CardDescription>
            {t('fill.sentMember', '{title} was recorded. It appears under your own submissions.', { title })}
          </CardDescription>
          {reference !== null && (
            <p className="text-sm font-medium">{t('fill.reference', 'Your reference: {reference}', { reference })}</p>
          )}
        </CardHeader>
      </Card>
    );
  }

  return (
    <div className="mx-auto w-full max-w-2xl space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>{title}</CardTitle>
          {description !== null && description !== '' && <CardDescription>{description}</CardDescription>}
        </CardHeader>

        <CardContent>
          {!rendered.accepts_submissions ? (
            <p className="py-6 text-sm text-muted-foreground">
              {t('fill.notAcceptingMember', 'This form is not accepting responses. Only a published form can be filled in.')}
            </p>
          ) : (
            <div className="space-y-6">
              {restored && (
                <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-card p-3 text-sm shadow-2xs">
                  <span className="text-muted-foreground">
                    {t('fill.draftRestored', 'Your unsent answers were restored from this browser.')}
                  </span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      discardDraft();
                      setAnswers({});
                      setRestored(false);
                    }}
                  >
                    {t('fill.startAgain', 'Start again')}
                  </Button>
                </div>
              )}

              {rendered.unresolved_prefill.length > 0 && (
                <p className="text-xs text-muted-foreground">
                  {t(
                    'fill.unresolvedPrefill',
                    'Some questions could not be filled in from your saved details ({fields}). Please complete them yourself.',
                    { fields: rendered.unresolved_prefill.map((entry) => labelOf(entry.field_key)).join(', ') }
                  )}
                </p>
              )}

              <FormFill
                fields={rendered.fields}
                sections={rendered.sections ?? []}
                answers={answers}
                onAnswersChange={setAnswers}
                preferArabic={preferArabic}
                references={{ ou_ref: units }}
                upload={uploadAttachment}
                submitLabel={submitLabel}
                submit={submitAnswers}
              />
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
