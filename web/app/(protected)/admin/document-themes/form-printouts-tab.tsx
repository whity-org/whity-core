'use client';

import type * as React from 'react';
import Link from 'next/link';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@whity/ui/card';
import { Checkbox } from '@whity/ui/checkbox';
import { Icon } from '@whity/ui/icon';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useTranslation } from '@whity/features/i18n';
import type { ApiClient, Trial } from './api';
import { MarksUploader } from './marks-uploader';
import { PreviewPanel } from './preview-panel';
import { SectionHeadingPicker } from './section-headings';
import {
  footerValue,
  formKey,
  formValue,
  trialDaysLeft,
  withFooterPart,
  withFormOption,
  withMarks,
  type FormOptionSchema,
  type FormSchema,
  type ThemeTokens,
} from './model';

/** The option cards, in the card's order (`TplFormPrintStyle`). */
const GROUPS: { id: string; options: string[] }[] = [
  { id: 'page', options: ['header', 'officeDetails', 'decision'] },
  { id: 'sections', options: ['sectionStyle', 'questions', 'longAnswers'] },
  { id: 'answers', options: ['choices', 'yesNo', 'empty'] },
  { id: 'people', options: ['signatures', 'approvals'] },
];

/**
 * The "Form printouts" tab of the workspace document theme (design 3.4,
 * FP.1-FP.4, `TplFormPrintStyle`): every part of how form responses print,
 * with a live preview beside it.
 *
 * Paid plans and trials choose every part (a trial says so in an info alert).
 * A free workspace sees what it prints: the plan gate, then the standard
 * layout with B headings in its colour and every option locked under a
 * "Paid plans" badge. Its marks still print, so the uploader stays open.
 */
export function FormPrintoutsTab({
  apiClient,
  schema,
  tokens,
  readOnly,
  trial,
  accent,
  workspaceName,
  ar,
  quietGate,
  onChange,
}: {
  apiClient: ApiClient;
  schema: FormSchema;
  tokens: ThemeTokens;
  readOnly: boolean;
  trial: Trial | null;
  /** The resolved print colour, for the previews. */
  accent: string;
  workspaceName: string;
  ar: boolean;
  /** The save bar is showing: the gate's button steps down to outline, so one main button stays. */
  quietGate: boolean;
  onChange: (tokens: ThemeTokens) => void;
}) {
  const t = useTranslation('documents');
  const locked = schema.allowed === false;
  const disabled = readOnly || locked;
  const byKey = new Map(schema.options.map((o) => [formKey(o.key), o]));
  const label = (o: { label: string; labelAr: string }) => (ar ? o.labelAr : o.label);
  const hints: Record<string, string> = {
    questions: t('themes.form.questionsHint', 'A template can change this per section.'),
    choices: t('themes.form.choicesHint', 'Lists longer than 8 always print the chosen only.'),
  };
  const titles: Record<string, string> = {
    page: t('themes.form.page', 'Page and header'),
    sections: t('themes.form.sections', 'Sections and questions'),
    answers: t('themes.form.answers', 'Answers'),
    people: t('themes.form.people', 'People and approvals'),
  };
  const paid = locked ? (
    <CardAction>
      <Badge variant="outline" icon="lock">
        {t('themes.form.paid', 'Paid plans')}
      </Badge>
    </CardAction>
  ) : null;

  const field = (option: FormOptionSchema) => {
    const key = formKey(option.key);
    const value = formValue(tokens, option, locked);
    if (key === 'sectionStyle') {
      return (
        <SectionHeadingPicker
          key={key}
          label={label(option)}
          hint={
            locked
              ? t('themes.form.headingsFree', 'Free workspaces use B, the Whity mark, in your colour.')
              : t('themes.form.headingsHint', 'In your print colour. F needs a tint behind the heading.')
          }
          options={option.values}
          value={value}
          locked={locked}
          disabled={readOnly}
          ar={ar}
          accent={accent}
          onChange={(next) => onChange(withFormOption(tokens, key, next))}
        />
      );
    }
    return (
      <div key={key} className="grid gap-2">
        <span className="text-sm font-medium text-foreground">{label(option)}</span>
        <ToggleGroup
          label={label(option)}
          type="single"
          required
          value={value}
          options={option.values.map((v) => ({ value: v.value, label: label(v), disabled }))}
          onChange={(next) => {
            if (typeof next === 'string') onChange(withFormOption(tokens, key, next));
          }}
          className="max-w-full flex-wrap"
        />
        {hints[key] && <p className="text-xs text-muted-foreground">{hints[key]}</p>}
      </div>
    );
  };

  const daysLeft = trial ? trialDaysLeft(trial.endsAt) : null;

  return (
    <div className="grid gap-4">
      {trial && !locked && (
        <Alert variant="info">
          <Icon name="info" size={16} />
          <AlertTitle>
            {daysLeft === null
              ? t('themes.trial.title', 'Free trial')
              : t('themes.trial.days', 'Free trial · {days} days left', { days: String(daysLeft) })}
          </AlertTitle>
          <AlertDescription className="text-sm text-foreground opacity-100">
            {t(
              'themes.trial.body',
              'Everything here is yours while the trial runs. If you stay on the free plan, new printouts go back to the standard layout; printouts already made never change.'
            )}
          </AlertDescription>
        </Alert>
      )}
      {locked && <PlanGate quiet={quietGate} />}

      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_330px]">
        <div className="grid gap-4">
          {GROUPS.map((group) => {
            const options = group.options.map((k) => byKey.get(k)).filter((o): o is FormOptionSchema => o !== undefined);
            if (options.length === 0) return null;
            return (
              <OptionCard key={group.id} title={titles[group.id]} action={paid}>
                {options.map(field)}
              </OptionCard>
            );
          })}
          <OptionCard title={t('themes.form.footer', 'Footer')} action={paid}>
            <div className="flex flex-wrap gap-x-5 gap-y-3">
              {schema.footer.map((part) => (
                <label key={part.key} className="inline-flex items-center gap-2 text-sm text-foreground">
                  <Checkbox
                    checked={footerValue(tokens, part, locked)}
                    disabled={disabled || part.fixed}
                    onCheckedChange={(on) => onChange(withFooterPart(tokens, part.key, on === true))}
                  />
                  {label(part)}
                </label>
              ))}
            </div>
            <MarksUploader
              marks={tokens.form?.marks ?? []}
              max={schema.marks.max}
              disabled={readOnly}
              onChange={(marks) => onChange(withMarks(tokens, marks))}
            />
          </OptionCard>
        </div>
        <PreviewPanel
          apiClient={apiClient}
          tokens={tokens}
          form={schema}
          locked={locked}
          accent={accent}
          workspaceName={workspaceName}
          lang={ar ? 'ar' : 'en'}
        />
      </div>
    </div>
  );
}

function OptionCard({ title, action, children }: { title: string; action: React.ReactNode; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2 className="text-base font-semibold">{title}</h2>
        </CardTitle>
        {action}
      </CardHeader>
      <CardContent className="grid gap-4">{children}</CardContent>
    </Card>
  );
}

/**
 * What a free workspace is told (FP.2): it prints the standard layout with its
 * logo and colour, and what paid plans and trials add.
 */
function PlanGate({ quiet }: { quiet: boolean }) {
  const t = useTranslation('documents');
  const includes = [
    t('themes.gate.headings', 'Six section heading styles'),
    t('themes.gate.layout', 'Header, decision and footer layout'),
    t('themes.gate.answers', 'How answers, signatures and approvals print'),
    t('themes.gate.templates', 'Per-template changes'),
  ];
  return (
    <section
      aria-labelledby="form-printouts-gate"
      className="mx-auto my-4 grid w-full max-w-md gap-3 rounded-card border border-border bg-card p-6 shadow-sm"
    >
      <span className="grid size-9 place-items-center rounded-control bg-primary-tint text-primary-tint-foreground" aria-hidden="true">
        <Icon name="lock" size={16} />
      </span>
      <h2 id="form-printouts-gate" className="text-base font-semibold text-foreground">
        {t('themes.gate.title', 'Your own form printouts')}
      </h2>
      <p className="text-sm text-muted-foreground">
        {t(
          'themes.gate.body',
          'Free workspaces print forms in Whity’s standard layout, with your logo and colour. Paid plans and trials choose every part.'
        )}
      </p>
      <ul className="grid gap-1.5 text-sm text-foreground">
        {includes.map((item) => (
          <li key={item} className="flex items-center gap-2">
            <span className="text-primary" aria-hidden="true">
              <Icon name="check" size={14} />
            </span>
            {item}
          </li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2 pt-1">
        <Button asChild variant={quiet ? 'outline' : 'default'}>
          <Link href="/billing">{t('themes.gate.plans', 'See paid plans')}</Link>
        </Button>
      </div>
    </section>
  );
}
