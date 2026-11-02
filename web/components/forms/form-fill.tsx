'use client';

/**
 * Asking a form's questions, shared by the two pages it can be filled on (F.2).
 *
 * A form with one section is one page. A form with several shows them as steps, with
 * Back and Continue at the foot and Review as the last step, so a long form is never
 * one long page. Which questions are asked comes from {@link fieldVisibility}: a
 * hidden question is not drawn, not checked and not sent.
 *
 * The server decides what is acceptable. Continue only checks that required questions
 * on this step are answered, so somebody is told on the step they are on rather than
 * after the last one. Anything else comes back from the server, and a refusal about
 * one question takes the reader to it.
 */

import { useMemo, useRef, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Stepper } from '@whity/ui/stepper';
import { useTranslation } from '@whity/features/i18n';
import {
  FormField,
  localized,
  type Answer,
  type FileUploader,
  type FormFieldSpec,
  type LocalizedText,
  type ReferenceOption,
} from '@/components/forms/form-fields';
import { fieldVisibility, type FieldConditionRule } from '@/components/forms/form-conditions';
import { useRefusalMessage, type RefusalBody } from '@/components/forms/form-refusals';

export interface FormSectionSpec {
  key: string | null;
  field_keys: string[];
  title?: LocalizedText | null;
  description?: LocalizedText | null;
}

export type FillableField = FormFieldSpec & { visible_when?: FieldConditionRule | null };

/** The Stepper is drawn across the page for up to this many steps, and down it beyond. */
const HORIZONTAL_STEPS_MAX = 6;

function isUnanswered(field: FillableField, value: Answer | undefined): boolean {
  if (field.field_type === 'checkbox') return value !== true;
  if (value === undefined) return true;
  if (typeof value === 'string') return value.trim() === '';

  return Array.isArray(value) && value.length === 0;
}

export function FormFill({
  fields,
  sections,
  answers,
  onAnswersChange,
  preferArabic,
  references,
  upload,
  submitLabel,
  submit,
}: {
  fields: FillableField[];
  sections: FormSectionSpec[];
  answers: Record<string, Answer>;
  onAnswersChange: (answers: Record<string, Answer>) => void;
  preferArabic: boolean;
  references?: Partial<Record<string, ReferenceOption[]>>;
  upload?: FileUploader;
  submitLabel: string;
  /** Sends the answers. Resolves with a refusal, or null once the server has them. */
  submit: (answers: Record<string, Answer>) => Promise<RefusalBody | null>;
}) {
  const t = useTranslation('forms');
  const refusalMessage = useRefusalMessage();
  const top = useRef<HTMLDivElement>(null);

  const [stepIndex, setStepIndex] = useState(0);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const ordered = useMemo(() => fields.slice().sort((a, b) => a.position - b.position), [fields]);
  const asked = useMemo(() => {
    const visibility = fieldVisibility(ordered, answers);

    return ordered.filter((field) => visibility[field.field_key] !== false);
  }, [ordered, answers]);
  const byKey = useMemo(() => new Map(asked.map((field) => [field.field_key, field])), [asked]);

  /** Sections with nothing to ask right now are left out, so no step is empty. */
  const steps = useMemo(() => {
    const groups = sections
      .map((section) => ({
        section,
        fields: section.field_keys.flatMap((key) => byKey.get(key) ?? []),
      }))
      .filter((group) => group.fields.length > 0);

    // A question no section lists still has to be asked, so it can't fall off the form.
    const listed = new Set(groups.flatMap((group) => group.fields.map((field) => field.field_key)));
    const unlisted = asked.filter((field) => !listed.has(field.field_key));

    return unlisted.length > 0
      ? [...groups, { section: { key: null, field_keys: [] } as FormSectionSpec, fields: unlisted }]
      : groups;
  }, [sections, byKey, asked]);

  const stepped = steps.length > 1;
  const reviewIndex = steps.length;
  // An answer can hide the step somebody was on, so this can't trust the raw index.
  const current = Math.min(stepIndex, stepped ? reviewIndex : 0);
  const onReview = stepped && current === reviewIndex;

  function labelOf(fieldKey: string): string {
    const field = ordered.find((candidate) => candidate.field_key === fieldKey);

    return (field !== undefined ? localized(field.label, preferArabic) : '') || fieldKey;
  }

  function stepTitle(section: FormSectionSpec, index: number): string {
    return localized(section.title, preferArabic) || t('step.untitled', 'Part {number}', { number: index + 1 });
  }

  function goTo(index: number) {
    setStepIndex(index);
    setFormError(null);
    top.current?.scrollIntoView?.({ block: 'start' });
  }

  function requiredErrors(toCheck: FillableField[]): Record<string, string> {
    const errors: Record<string, string> = {};
    for (const field of toCheck) {
      if (field.is_required && isUnanswered(field, answers[field.field_key])) {
        errors[field.field_key] =
          field.field_type === 'checkbox'
            ? t('refusal.mustBeTicked', '{label} must be ticked.', { label: labelOf(field.field_key) })
            : t('refusal.required', '{label} is required.', { label: labelOf(field.field_key) });
      }
    }

    return errors;
  }

  function handleContinue() {
    const errors = requiredErrors(steps[current]?.fields ?? []);
    setFieldErrors(errors);
    if (Object.keys(errors).length === 0) goTo(current + 1);
  }

  async function handleSubmit() {
    if (submitting) return;

    const errors = requiredErrors(asked);
    setFieldErrors(errors);
    const firstMissing = steps.findIndex((step) => step.fields.some((field) => errors[field.field_key] !== undefined));
    if (firstMissing >= 0) {
      goTo(firstMissing);

      return;
    }

    setSubmitting(true);
    setFormError(null);
    try {
      // Only what was asked: the server would drop the rest, but an answer somebody
      // typed before changing their mind should not leave this browser.
      const refusal = await submit(
        Object.fromEntries(
          asked.flatMap((field) =>
            answers[field.field_key] === undefined ? [] : [[field.field_key, answers[field.field_key]]]
          )
        )
      );
      if (refusal === null) return;

      const message = refusalMessage(
        refusal,
        labelOf,
        t('fill.submitFailed', 'This could not be submitted. Please check your answers and try again.')
      );
      const refusedKey = refusal.details?.field_key;
      const refusedStep = refusedKey === undefined ? -1 : steps.findIndex((step) => step.fields.some((field) => field.field_key === refusedKey));
      if (refusedKey !== undefined && refusedStep >= 0) {
        setFieldErrors({ [refusedKey]: message });
        goTo(refusedStep);
      } else {
        setFormError(message);
      }
    } catch {
      setFormError(t('fill.submitFailedConnection', 'This could not be submitted. Check your connection and try again.'));
    } finally {
      setSubmitting(false);
    }
  }

  const visibleFields = stepped ? (steps[current]?.fields ?? []) : asked;
  const section = stepped ? steps[current]?.section : undefined;
  const sectionDescription = localized(section?.description, preferArabic);

  return (
    <form
      noValidate
      className="space-y-6"
      onSubmit={(event) => {
        event.preventDefault();
        if (stepped && !onReview) {
          handleContinue();
        } else {
          void handleSubmit();
        }
      }}
    >
      <div ref={top} />

      {stepped && (
        <Stepper
          label={t('step.label', 'Steps of this form')}
          stepOfLabel={(number, total) => t('step.of', 'Step {number} of {total}', { number, total })}
          orientation={steps.length + 1 > HORIZONTAL_STEPS_MAX ? 'vertical' : 'horizontal'}
          current={current}
          steps={[
            ...steps.map((step, index) => ({ label: stepTitle(step.section, index) })),
            { label: t('step.review', 'Review') },
          ]}
        />
      )}

      {onReview ? (
        <div className="space-y-6">
          <p className="text-sm text-muted-foreground">
            {t('review.intro', 'Check your answers, then send them. You can go back and change anything.')}
          </p>
          {steps.map((step, index) => (
            <section key={step.section.key ?? `step-${index}`} className="space-y-2">
              <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold">{stepTitle(step.section, index)}</h3>
                <Button type="button" variant="ghost" size="sm" onClick={() => goTo(index)}>
                  {t('review.change', 'Change')}
                </Button>
              </div>
              <dl className="space-y-2">
                {step.fields.map((field) => (
                  <div key={field.field_key}>
                    <dt className="text-sm text-muted-foreground">{labelOf(field.field_key)}</dt>
                    <dd className="text-sm">
                      <ReviewAnswer field={field} value={answers[field.field_key]} preferArabic={preferArabic} references={references} />
                    </dd>
                  </div>
                ))}
              </dl>
            </section>
          ))}
        </div>
      ) : (
        <>
          {stepped && sectionDescription !== '' && <p className="text-sm text-muted-foreground">{sectionDescription}</p>}
          {visibleFields.map((field) => (
            <div key={field.field_key} className="space-y-1.5">
              <FormField
                field={field}
                value={answers[field.field_key]}
                preferArabic={preferArabic}
                references={references}
                upload={upload}
                onChange={(value) => {
                  onAnswersChange({ ...answers, [field.field_key]: value });
                  if (fieldErrors[field.field_key] !== undefined) {
                    setFieldErrors((errors) => {
                      const rest = { ...errors };
                      delete rest[field.field_key];

                      return rest;
                    });
                  }
                }}
              />
              {fieldErrors[field.field_key] !== undefined && (
                <p className="text-sm text-destructive" role="alert">
                  {fieldErrors[field.field_key]}
                </p>
              )}
            </div>
          ))}
        </>
      )}

      {formError !== null && (
        <p className="text-sm text-destructive" role="alert">
          {formError}
        </p>
      )}

      <div className="flex items-center gap-3">
        {stepped && current > 0 && (
          <Button type="button" variant="outline" onClick={() => goTo(current - 1)}>
            {t('step.back', 'Back')}
          </Button>
        )}
        {stepped && !onReview ? (
          <Button type="submit">{t('step.continue', 'Continue')}</Button>
        ) : (
          <Button type="submit" disabled={submitting}>
            {submitting ? t('fill.submitting', 'Submitting…') : submitLabel}
          </Button>
        )}
      </div>
    </form>
  );
}

function ReviewAnswer({
  field,
  value,
  preferArabic,
  references,
}: {
  field: FillableField;
  value: Answer | undefined;
  preferArabic: boolean;
  references?: Partial<Record<string, ReferenceOption[]>>;
}) {
  const t = useTranslation('forms');
  const nothing = <span className="text-muted-foreground">{t('review.noAnswer', 'No answer')}</span>;

  if (field.field_type === 'checkbox') {
    return <>{value === true ? t('field.yes', 'Yes') : t('review.no', 'No')}</>;
  }
  if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) return nothing;
  // The answer is an opaque reference, and the question itself shows the file's name.
  if (field.field_type === 'file') return <>{t('review.attached', 'Attached')}</>;

  const optionText = (one: string): string => {
    for (const option of field.options) {
      if (typeof option === 'string') {
        if (option === one) return option;
      } else if (option.value === one) {
        return localized(option.label, preferArabic) || one;
      }
    }

    return references?.[field.field_type]?.find((option) => option.value === one)?.label ?? one;
  };

  if (Array.isArray(value)) return <>{value.map(optionText).join(', ')}</>;

  return <>{typeof value === 'string' ? optionText(value) : String(value)}</>;
}
