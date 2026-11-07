'use client';

/**
 * A form as the person filling it in would see it, for the author checking their
 * work (`GET /api/v1/forms/{id}/preview`, `forms:manage`). It works for a draft,
 * which the real fill page refuses. Nothing here is sent: the button says so.
 */

import { use, useEffect, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { FormHeader, type FormBannerValue } from '@whity/features/forms';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { useDirection } from '@/lib/direction-context';
import { apiClient } from '@/lib/api-client';
import { useTranslation } from '@whity/features/i18n';
import { localized, type Answer, type LocalizedText, type ReferenceOption } from '@/components/forms/form-fields';
import { FormFill, type FillableField, type FormSectionSpec } from '@/components/forms/form-fill';
import type { RefusalBody } from '@/components/forms/form-refusals';

interface PreviewForm {
  form: {
    name: LocalizedText | string | null;
    description: string | null;
    description_i18n?: LocalizedText | [] | null;
    submit_label?: LocalizedText | null;
    /** The heading and picture written for the person filling it in (WHIT-944). */
    title?: LocalizedText | null;
    banner?: FormBannerValue | null;
  };
  fields: FillableField[];
  sections: FormSectionSpec[];
}

export default function PreviewFormPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { dir } = useDirection();
  const preferArabic = dir === 'rtl';
  const t = useTranslation('forms');

  const [rendered, setRendered] = useState<PreviewForm | null>(null);
  const [failed, setFailed] = useState(false);
  const [answers, setAnswers] = useState<Record<string, Answer>>({});
  const [units, setUnits] = useState<ReferenceOption[]>([]);

  useEffect(() => {
    let cancelled = false;

    apiClient(`/api/v1/forms/${encodeURIComponent(id)}/preview`, { headers: { Accept: 'application/json' } })
      .then(async (response) => {
        const body = (await response.json().catch(() => ({}))) as { data?: PreviewForm };
        if (cancelled) return;
        if (!response.ok || !body.data) setFailed(true);
        else setRendered(body.data);
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });

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
            .map((ou) => ({ value: String(ou.id), label: ou.name, type: ou.ou_type_key ?? null }))
        );
      })
      .catch(() => {});

    return () => {
      cancelled = true;
    };
  }, [id]);

  if (failed) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('preview.failed', 'This preview could not be opened')}</CardTitle>
          <CardDescription>{t('preview.failed.body', 'Check that you may manage forms, then try again.')}</CardDescription>
        </CardHeader>
      </Card>
    );
  }
  if (rendered === null) {
    return (
      <Card>
        <CardContent className="py-10 text-center text-sm text-muted-foreground">{t('fill.loading', 'Loading…')}</CardContent>
      </Card>
    );
  }

  const title =
    localized(rendered.form.title ?? null, preferArabic) || localized(rendered.form.name, preferArabic) || t('fill.untitled', 'Form');
  const translatedDescription = Array.isArray(rendered.form.description_i18n) ? null : rendered.form.description_i18n;
  const description = localized(translatedDescription, preferArabic) || rendered.form.description;
  const submitLabel = localized(rendered.form.submit_label, preferArabic) || t('fill.send', 'Send');

  async function nothingIsSent(): Promise<RefusalBody | null> {
    return { error: t('preview.nothingSent', 'This is a preview. Nothing was sent.') };
  }

  return (
    <div className="mx-auto w-full max-w-2xl space-y-4">
      <Alert>
        <AlertDescription>
          {t('preview.banner', 'Preview: this is how the saved form looks to the person filling it in. Nothing you enter here is sent.')}
        </AlertDescription>
      </Alert>
      <Card>
        <FormHeader title={title} description={description} banner={rendered.form.banner} />
        <CardContent>
          <FormFill
            fields={rendered.fields}
            sections={rendered.sections ?? []}
            answers={answers}
            onAnswersChange={setAnswers}
            preferArabic={preferArabic}
            references={{ ou_ref: units }}
            upload={async () => {
              throw new Error(t('preview.noUploads', 'Files are not uploaded in a preview.'));
            }}
            submitLabel={submitLabel}
            submit={nothingIsSent}
          />
        </CardContent>
      </Card>
    </div>
  );
}
