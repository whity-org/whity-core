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
 *
 * WHIT-920: `?edit={submission}` changes a submission that was sent back to
 * this person and sends it again (`PUT /api/v1/me/form-submissions/{id}`), and
 * `?from={submission}` starts a new one with the answers of an earlier one (a
 * submission that was not approved). Both seed the answers from the person's
 * own submission and keep no draft.
 */

import { use, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Icon } from '@whity/ui/icon';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { FormHeader, type FormBannerValue } from '@whity/features/forms';
import { Button } from '@whity/ui/button';
import { useDirection } from '@/lib/direction-context';
import { apiClient } from '@/lib/api-client';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import {
  localized,
  type Answer,
  type LocalizedText,
  type ReferenceOption,
  type UploadedFileRef,
} from '@/components/forms/form-fields';
import { FormFill, type FillableField, type FormSectionSpec } from '@/components/forms/form-fill';
import { useRefusalMessage, type RefusalBody } from '@/components/forms/form-refusals';
import { useFormDraft } from '@/components/forms/use-form-draft';

interface RenderedForm {
  form: {
    id: number;
    name: LocalizedText | string | null;
    description: string | null;
    description_i18n?: LocalizedText | [] | null;
    submit_label?: LocalizedText | null;
    /** The heading and picture written for the person filling it in (WHIT-944). */
    title?: LocalizedText | null;
    banner?: FormBannerValue | null;
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

/** WHIT-920: the parts of the person's own submission that seed the answers. */
interface OwnSubmission {
  data: Record<string, unknown> | [];
  progress?: { can_resubmit: boolean; notes: Array<{ kind: string; note: string }> } | null;
}

function positiveId(raw: string | null): number | null {
  return raw !== null && /^\d+$/.test(raw) && Number(raw) > 0 ? Number(raw) : null;
}

/** Earlier answers as the fill page holds them; anything it cannot hold is left out. */
function answersFrom(data: Record<string, unknown> | [], fileKeys: Set<string>): Record<string, Answer> {
  const out: Record<string, Answer> = {};
  if (Array.isArray(data)) return out;
  for (const [key, value] of Object.entries(data)) {
    if (fileKeys.has(key)) continue;
    if (typeof value === 'string' || typeof value === 'boolean') out[key] = value;
    else if (typeof value === 'number') out[key] = String(value);
    else if (Array.isArray(value) && value.every((item) => typeof item === 'string')) out[key] = value as string[];
  }
  return out;
}

export default function FillFormPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { dir } = useDirection();
  const preferArabic = dir === 'rtl';
  const t = useTranslation('forms');
  const refusalMessage = useRefusalMessage();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const { time } = useDateDisplay();
  const draft = useFormDraft(id);
  // WHIT-920: which submission seeds the answers, and whether this sends it again.
  const searchParams = useSearchParams();
  // Null outside the app router (a test rendering the page on its own).
  const editId = positiveId(searchParams?.get('edit') ?? null);
  const fromId = editId === null ? positiveId(searchParams?.get('from') ?? null) : null;
  const seedId = editId ?? fromId;
  const [changesAsked, setChangesAsked] = useState<string | null>(null);
  // Fail-closed while loading, so nothing is asked until this is known. Latched, because
  // a later refresh of the permissions mustn't refetch the form and reseed the answers.
  const [capabilitiesKnown, setCapabilitiesKnown] = useState(false);
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- a latch on outside state
    if (!capabilitiesLoading) setCapabilitiesKnown(true);
  }, [capabilitiesLoading]);
  const mayListUnits = hasPermission('ous:read');
  // What the workspace already knows, which Start over goes back to.
  const prefilled = useRef<Record<string, Answer>>({});
  const stepRef = useRef(0);

  const [rendered, setRendered] = useState<RenderedForm | null>(null);
  // Which failure, not its wording: the words are chosen when they are drawn, so a
  // language loading late can't refetch the form and reseed the answers below.
  const [loadError, setLoadError] = useState<'notOpened' | 'loadFailed' | 'notSentBack' | null>(null);
  const [loading, setLoading] = useState(true);
  const [answers, setAnswers] = useState<Record<string, Answer>>({});
  const [submitted, setSubmitted] = useState(false);
  const [reference, setReference] = useState<string | null>(null);
  const [units, setUnits] = useState<ReferenceOption[]>([]);
  const [restored, setRestored] = useState(false);
  const [startStep, setStartStep] = useState(0);
  // Bumped by Start over, so the steps begin again too.
  const [attempt, setAttempt] = useState(0);
  // Where this person fills it in: `/forms` with `forms:submit`, or `/me/forms` when
  // the form was opened to members and they hold nothing else.
  const [apiBase, setApiBase] = useState('/api/v1/forms');

  // The unit list for any `ou_ref` field. Fetched by the PAGE rather than by the
  // field, because whether this reader may see a list of the tenant's units is a
  // question about the reader, not about the field — and the public page
  // deliberately never asks it.
  const asksForUnit = rendered?.fields.some((field) => field.field_type === 'ou_ref') ?? false;
  useEffect(() => {
    if (!asksForUnit || !mayListUnits) return;
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
  }, [asksForUnit, mayListUnits]);

  useEffect(() => {
    if (!capabilitiesKnown) return;
    let cancelled = false;
    const mayFillAnyForm = hasPermission('forms:submit');

    const renderAt = (base: string) =>
      apiClient(`${base}/${encodeURIComponent(id)}/render`, { headers: { Accept: 'application/json' } });

    // Without `forms:submit` the first address would only refuse them. The fallback
    // stays for somebody whose permission changed after this page learned it.
    renderAt(mayFillAnyForm ? '/api/v1/forms' : '/api/v1/me/forms')
      .then(async (first) => {
        let response = first;
        if (mayFillAnyForm && first.status === 403) {
          response = await renderAt('/api/v1/me/forms');
        }
        if (!cancelled && response.ok && (!mayFillAnyForm || first.status === 403)) {
          setApiBase('/api/v1/me/forms');
        }
        const body = (await response.json().catch(() => ({}))) as { data?: RenderedForm; error?: string };
        if (cancelled) return;
        if (!response.ok || !body.data) {
          // Only a 404 or a permission refusal reaches here, and neither says
          // more than this to the person looking at it.
          setLoadError('notOpened');

          return;
        }

        // Seed from the server's resolved prefill. Only strings are seeded: a
        // prefill that arrived as some other shape is a mismatch between the
        // source and the field, and guessing a coercion would put a value in
        // front of somebody as though they had entered it.
        const seed: Record<string, Answer> = {};
        for (const [key, value] of Object.entries(body.data.prefill ?? {})) {
          if (typeof value === 'string' && value !== '') seed[key] = value;
        }

        // WHIT-920: the person's own earlier answers, over the prefill. A file is
        // not carried: a new submission attaches its own, and an edit keeps the
        // one already attached unless a new one is given.
        if (seedId !== null) {
          const own = await apiClient(`/api/v1/me/form-submissions/${seedId}`, { headers: { Accept: 'application/json' } });
          const ownBody = (await own.json().catch(() => ({}))) as { data?: OwnSubmission };
          if (cancelled) return;
          if (!own.ok || ownBody.data === undefined || (editId !== null && ownBody.data.progress?.can_resubmit !== true)) {
            setLoadError(editId !== null ? 'notSentBack' : 'notOpened');
            return;
          }
          const fileKeys = new Set(body.data.fields.filter((f) => f.field_type === 'file').map((f) => f.field_key));
          Object.assign(seed, answersFrom(ownBody.data.data, fileKeys));
          const asked = (ownBody.data.progress?.notes ?? []).filter((note) => note.kind === 'sent_back');
          setChangesAsked(asked.length > 0 ? asked[asked.length - 1].note : null);
        }

        prefilled.current = seed;
        // Prefill first, then whatever was already typed — a draft is a
        // later statement of intent than a default, so it wins.
        const saved = body.data.accepts_submissions && seedId === null ? await draft.load() : null;
        if (cancelled) return;
        setAnswers({ ...seed, ...(saved?.answers ?? {}) });
        setRestored(saved !== null);
        stepRef.current = saved?.step ?? 0;
        setStartStep(stepRef.current);
        setRendered(body.data);
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
    // `draft` and `hasPermission` change identity freely. Only the form, and learning the
    // permissions, are reasons to load it again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, capabilitiesKnown]);

  // Keep the draft current, but only once they have changed something: opening a form
  // isn't starting one. File answers are deliberately EXCLUDED: an upload reference is
  // single-use, so restoring one would hand the server a reference it has already spent
  // and refuse the submission for a reason nobody can see.
  function keepDraft(next: Record<string, Answer>, step: number) {
    // Seeded from a submission (WHIT-920): the answers already live on it.
    if (seedId !== null) return;
    const fileKeys = new Set(
      (rendered?.fields ?? []).filter((f) => f.field_type === 'file').map((f) => f.field_key)
    );
    draft.schedule(Object.fromEntries(Object.entries(next).filter(([key]) => !fileKeys.has(key))), step);
  }

  function changeAnswers(next: Record<string, Answer>) {
    setAnswers(next);
    keepDraft(next, stepRef.current);
  }

  function changeStep(step: number) {
    stepRef.current = step;
    keepDraft(answers, step);
  }

  function startOver() {
    void draft.discard();
    setAnswers(prefilled.current);
    setRestored(false);
    stepRef.current = 0;
    setStartStep(0);
    setAttempt((current) => current + 1);
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

    const response = await apiClient(`${apiBase}/${encodeURIComponent(id)}/uploads`, {
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
    // No save may land after this and bring the draft back. The server drops the draft
    // as it takes the answers.
    await draft.pause();
    // WHIT-920: an edit sends the sent-back submission again rather than a new one.
    const address = editId !== null ? `/api/v1/me/form-submissions/${editId}` : `${apiBase}/${encodeURIComponent(id)}/submissions`;
    const response = await apiClient(address, {
      method: editId !== null ? 'PUT' : 'POST',
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
    if (!response.ok) {
      draft.resume();

      return body;
    }

    setReference(body.data?.reference ?? null);
    draft.forget();
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
              : loadError === 'notSentBack'
                ? t('fill.notSentBack', 'This submission is not waiting for your changes, so it cannot be edited now.')
                : t('fill.notOpened', 'This form could not be opened.')}
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  const title =
    localized(rendered.form.title ?? null, preferArabic) || localized(rendered.form.name, preferArabic) || t('fill.untitled', 'Form');
  const translatedDescription = Array.isArray(rendered.form.description_i18n) ? null : rendered.form.description_i18n;
  const description = localized(translatedDescription, preferArabic) || rendered.form.description;
  const submitLabel = localized(rendered.form.submit_label, preferArabic) || t('fill.send', 'Send');

  if (submitted) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{editId !== null ? t('fill.resentTitle', 'Sent again') : t('fill.sentMemberTitle', 'Submitted')}</CardTitle>
          <CardDescription>
            {editId !== null
              ? t('fill.resent', 'Your changes to {title} were sent. It appears under your own submissions.', { title })
              : t('fill.sentMember', '{title} was recorded. It appears under your own submissions.', { title })}
          </CardDescription>
          {reference !== null && (
            <p className="text-sm font-medium">{t('fill.reference', 'Your reference: {reference}', { reference })}</p>
          )}
          <div className="pt-2">
            <Button asChild variant="outline">
              <Link href="/admin/x/my-form-submissions">{t('fill.viewSubmissions', 'View your submissions')}</Link>
            </Button>
          </div>
        </CardHeader>
      </Card>
    );
  }

  return (
    <div className="mx-auto w-full max-w-2xl space-y-4">
      <Card>
        <FormHeader title={title} description={description} banner={rendered.form.banner} />

        <CardContent>
          {!rendered.accepts_submissions ? (
            <p className="py-6 text-sm text-muted-foreground">
              {t('fill.notAcceptingMember', 'This form is not accepting responses. Only a published form can be filled in.')}
            </p>
          ) : (
            <div className="space-y-6">
              {seedId !== null && (
                <div className="space-y-1 rounded-xl border border-border/60 bg-card p-3 text-sm shadow-2xs">
                  <p>
                    {editId !== null
                      ? t('fill.editing', 'You are changing a submission that was sent back to you. Files you attached stay unless you attach new ones.')
                      : t('fill.startingFrom', 'Your earlier answers are filled in. Change what you need and send it as a new submission.')}
                  </p>
                  {changesAsked !== null && (
                    <p className="text-muted-foreground">{t('fill.changesAsked', 'Changes asked for: {note}', { note: changesAsked })}</p>
                  )}
                </div>
              )}
              {restored && (
                <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-card p-3 text-sm shadow-2xs">
                  <span className="text-muted-foreground">
                    {t('fill.draftRestored', 'You started this earlier. Your answers are back, so you can carry on.')}
                  </span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={startOver}
                  >
                    {t('fill.startOver', 'Start over')}
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
                key={attempt}
                fields={rendered.fields}
                sections={rendered.sections ?? []}
                answers={answers}
                onAnswersChange={changeAnswers}
                preferArabic={preferArabic}
                references={{ ou_ref: units }}
                upload={uploadAttachment}
                submitLabel={submitLabel}
                submit={submitAnswers}
                initialStep={startStep}
                onStepChange={changeStep}
                footer={
                  draft.failed ? (
                    t('fill.draftFailed', 'Your draft could not be saved. You can still send the form.')
                  ) : draft.savedAt !== null ? (
                    <span className="inline-flex items-center gap-1.5">
                      <Icon name="check" size={16} />
                      {t('fill.draftSaved', 'Draft saved at {time}', { time: time(draft.savedAt) ?? '' })}
                    </span>
                  ) : undefined
                }
              />
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
