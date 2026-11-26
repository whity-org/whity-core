'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { templateSetupPath } from '@/lib/workspace-template';
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { PageHeader } from '@whity/ui/page-header';
import { Switch } from '@whity/ui/switch';
import { Icon } from '@whity/ui/icon';
import { iconNames, type IconName } from '@whity/ui/icon-map';
import { AreaError, LoadingRows } from '@whity/ui/page-states';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { useHomePath } from '@/lib/branding-context';
import { TemplatePlan } from '@/components/workspace-templates/template-plan';

type Template = components['schemas']['WorkspaceTemplateCard'];

/**
 * Start from a template (WHIT-1195, SDK 1.116): offered right after a
 * workspace is created, and from Settings › Plugins any time after (owner,
 * 2026-11-17: adopt mode, it only adds what is missing). Each card says what the template sets up and, when it
 * comes with a paid plugin, that the plugin can be tried free. Choosing one
 * asks once (examples on or off, and its theme when it has one: SDK 1.131,
 * TH.23), shows what it adds from a dry run (WHIT-1429, decision WT.1:
 * each part with Will be added, Already there, or Can't add and why),
 * applies it as the person, and opens its guided setup. Start blank is
 * always last.
 *
 * No card for this screen yet: brand ask brand-ask-workspace-templates.md §1.
 * Built from TplWelcomeWorkspace and PluginCard.
 */
export default function TemplatePickerPage() {
  const t = useTranslation('admin');
  const router = useRouter();
  const homePath = useHomePath();
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: failed } = await api.GET('/api/v1/workspace/templates');
    if (failed || !body) throw new Error(t('templates.loadError', 'The templates could not be loaded.'));
    return body.data;
  }, []);
  const [chosen, setChosen] = useState<Template | null>(null);
  const [examples, setExamples] = useState(true);
  const [useTheme, setUseTheme] = useState(true);
  const [applying, setApplying] = useState(false);
  const [applyError, setApplyError] = useState<string | null>(null);

  async function apply(template: Template) {
    setApplying(true);
    setApplyError(null);
    try {
      const { response } = await api.POST('/api/v1/workspace/templates/{key}/apply', {
        params: { path: { key: template.key } },
        body: { examples: template.hasExamples ? examples : false, theme: template.theme ? useTheme : false },
      });
      if (!response.ok) {
        setApplyError(t('templates.applyError', 'The template could not be set up. Try again, or start blank.'));
        return;
      }
      router.push(template.hasSetup || template.offer ? templateSetupPath(template.key) : homePath);
      // The theme is drawn by the layout: fetch it again so the new look shows.
      if (template.theme && useTheme) router.refresh();
    } finally {
      setApplying(false);
    }
  }

  if (loading) {
    return <LoadingRows label={t('templates.loading', 'Loading the templates')} headers={[t('templates.template', 'Template')]} />;
  }
  if (error || !data) {
    return <AreaError message={error ?? ''} retryLabel={t('plugins.tryAgain', 'Try again')} onRetry={() => void refetch()} />;
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6 px-4 pb-16 md:px-0">
      <PageHeader
        title={t('templates.title', 'How will you use Whity?')}
        description={t(
          'templates.description',
          'Pick the one closest to your business. It sets up the basics in a minute, free. It only adds what is missing, and you can change everything later.',
        )}
      />
      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {data.templates.map((template) => (
          <li key={template.key}>
            <TemplateCard template={template} onChoose={() => setChosen(template)} />
          </li>
        ))}
      </ul>

      <Dialog open={chosen !== null} onOpenChange={(open) => !open && !applying && setChosen(null)}>
        {chosen ? (
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{chosen.name}</DialogTitle>
              <DialogDescription>{chosen.summary}</DialogDescription>
            </DialogHeader>
            <div className="max-h-[60vh] space-y-4 overflow-y-auto">
              {chosen.hasExamples ? (
                <label className="flex items-start gap-3 text-sm">
                  <Switch checked={examples} onCheckedChange={setExamples} />
                  <span>
                    <span className="block text-foreground">{t('templates.examples', 'Add example customers and bookings')}</span>
                    <span className="block text-muted-foreground">
                      {t('templates.examplesHint', 'To see how it works. Remove them in one click when you are ready.')}
                    </span>
                  </span>
                </label>
              ) : null}
              {chosen.theme ? (
                <label className="flex items-start gap-3 text-sm">
                  <Switch checked={useTheme} onCheckedChange={setUseTheme} />
                  <span>
                    <span className="block text-foreground">
                      {t('templates.theme', 'Use its look: {name}', { name: chosen.theme.name ?? chosen.theme.id })}
                    </span>
                    <span className="block text-muted-foreground">
                      {t('templates.themeHint', 'Colours, shapes and fonts made for this kind of business. Change it any time in Branding.')}
                    </span>
                  </span>
                </label>
              ) : null}
              {chosen.key !== 'blank' ? (
                <WhatThisAdds
                  templateKey={chosen.key}
                  examples={chosen.hasExamples ? examples : false}
                  theme={chosen.theme ? useTheme : false}
                />
              ) : (
                <SetsUp lines={chosen.setsUp} />
              )}
              {applyError ? (
                <Alert variant="destructive">
                  <AlertDescription>{applyError}</AlertDescription>
                </Alert>
              ) : null}
            </div>
            <DialogFooter>
              <Button variant="outline" disabled={applying} onClick={() => setChosen(null)}>
                {t('templates.back', 'Back')}
              </Button>
              <Button disabled={applying} onClick={() => void apply(chosen)}>
                {applying ? t('templates.applying', 'Setting it up…') : t('templates.apply', 'Set it up')}
              </Button>
            </DialogFooter>
          </DialogContent>
        ) : null}
      </Dialog>
    </div>
  );
}

function TemplateCard({ template, onChoose }: { template: Template; onChoose: () => void }) {
  const t = useTranslation('admin');
  const icon: IconName = template.icon && (iconNames as readonly string[]).includes(template.icon) ? (template.icon as IconName) : 'template';

  return (
    <Card interactive className="h-full">
      <button
        type="button"
        onClick={onChoose}
        className="flex h-full w-full flex-col gap-3 p-5 text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <span className="flex items-center gap-3">
          <span className="flex size-10 items-center justify-center rounded-md bg-primary-tint text-primary">
            <Icon name={icon} size={20} />
          </span>
          <span className="text-base font-semibold text-foreground">{template.name}</span>
        </span>
        <span className="text-sm text-muted-foreground">{template.summary}</span>
        <SetsUp lines={template.setsUp.slice(0, 5)} />
        {template.offer && template.offer.trialDays > 0 ? (
          <span className="mt-auto text-xs text-muted-foreground">
            {t('templates.offers', 'Offers {plugin} · {days}-day free trial', { plugin: template.offer.pluginName, days: template.offer.trialDays })}
          </span>
        ) : null}
      </button>
    </Card>
  );
}

/**
 * What choosing it would add here, from the dry run: nothing is saved until
 * Set it up (WT.1). Asked again when the switches change.
 */
function WhatThisAdds({ templateKey, examples, theme }: { templateKey: string; examples: boolean; theme: boolean }) {
  const t = useTranslation('admin');
  const { data, loading, error } = useFetch(async () => {
    const { data: body, error: failed } = await api.POST('/api/v1/workspace/templates/{key}/preview', {
      params: { path: { key: templateKey } },
      body: { examples, theme },
    });
    if (failed || !body) throw new Error(t('templates.plan.loadError', 'What it adds could not be worked out. You can still set it up.'));
    return body.data.items ?? [];
  }, [templateKey, examples, theme]);

  return (
    <section className="space-y-2" aria-live="polite">
      <h3 className="text-sm font-semibold text-foreground">{t('templates.plan.title', 'What this adds')}</h3>
      {loading ? (
        <p className="text-sm text-muted-foreground">{t('templates.plan.loading', 'Working out what it adds…')}</p>
      ) : error || !data ? (
        <p className="text-sm text-muted-foreground">{error}</p>
      ) : (
        <TemplatePlan items={data} only={['add', 'keep', 'skip']} />
      )}
    </section>
  );
}

function SetsUp({ lines }: { lines: string[] }) {
  const t = useTranslation('admin');

  return (
    <div className="space-y-1">
      <span className="block text-xs text-muted-foreground">{t('templates.setsUp', 'Sets up:')}</span>
      <ul className="space-y-1 text-sm text-foreground">
        {lines.map((line) => (
          <li key={line} className="flex items-start gap-2">
            <Icon name="check" className="mt-0.5 text-success" />
            <span>{line}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
