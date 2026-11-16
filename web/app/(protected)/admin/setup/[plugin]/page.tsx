'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { api, apiFetch } from '@/lib/api/client';
import type { components } from '@whity/api-client';
import { useFetch } from '@/hooks/useFetch';
import { useTranslation } from '@whity/features/i18n';
import { AdminHeader } from '@/components/admin/admin-header';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import { Stepper } from '@whity/ui/stepper';
import { AreaError, LoadingRows } from '@whity/ui/page-states';

type Onboarding = components['schemas']['WorkspaceOnboarding'];

/**
 * A plugin's guided setup (SDK 1.109, WHIT-1148): its steps as a stepper, one
 * block screen at a time. The plugin's status route says which steps are
 * done; the page opens the first that isn't. People may go back, skip ahead
 * or leave and come back: nothing forces the order.
 */
export default function PluginSetupPage() {
  const params = useParams<{ plugin: string | string[] }>();
  const plugin = Array.isArray(params.plugin) ? params.plugin[0] : params.plugin;
  const t = useTranslation('admin');

  const { data: setup, loading, error, refetch } = useFetch(async () => {
    const { data } = await api.GET('/api/v1/workspace/onboarding');
    return (data?.data ?? []).find((entry) => entry.plugin === plugin) ?? null;
  }, [plugin]);

  const [doneVersion, setDoneVersion] = useState(0);
  const { data: done } = useFetch(async () => {
    if (!setup) return {} as Record<string, boolean>;
    const response = await apiFetch(setup.status);
    if (!response.ok) return {} as Record<string, boolean>;
    const body = (await response.json()) as { data?: { steps?: Record<string, boolean> } };
    return body.data?.steps ?? {};
  }, [setup?.status, doneVersion]);

  const [chosen, setChosen] = useState<number | null>(null);
  const firstOpen = useMemo(() => {
    if (!setup) return 0;
    const index = setup.steps.findIndex((step) => !(done ?? {})[step.id]);
    return index === -1 ? setup.steps.length - 1 : index;
  }, [setup, done]);
  const current = chosen ?? firstOpen;

  if (loading) {
    return <LoadingRows label={t('setup.loading', 'Loading the setup')} headers={[t('setup.step', 'Step')]} />;
  }
  if (error) {
    return <AreaError message={error} retryLabel={t('plugins.tryAgain', 'Try again')} onRetry={() => void refetch()} />;
  }
  if (!setup) {
    return (
      <div className="mx-auto max-w-3xl space-y-4 px-4 md:px-0">
        <AdminHeader title={t('setup.missing.title', 'Nothing to set up')} />
        <p className="text-sm text-muted-foreground">
          {t('setup.missing.body', 'This plugin is off in this workspace, or you can’t run its setup. Turn it on in Settings › Plugins.')}
        </p>
        <Button asChild variant="outline">
          <Link href="/admin/plugins">{t('setup.missing.action', 'Go to Settings › Plugins')}</Link>
        </Button>
      </div>
    );
  }

  return <SetupSteps setup={setup} done={done ?? {}} current={current} onChoose={setChosen} onRecheck={() => setDoneVersion((v) => v + 1)} />;
}

function SetupSteps({
  setup,
  done,
  current,
  onChoose,
  onRecheck,
}: {
  setup: Onboarding;
  done: Record<string, boolean>;
  current: number;
  onChoose: (index: number) => void;
  onRecheck: () => void;
}) {
  const t = useTranslation('admin');
  const step = setup.steps[current];
  const total = setup.steps.length;
  const finished = setup.steps.filter((s) => done[s.id]).length;

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 pb-16 md:px-0">
      <AdminHeader
        title={setup.title ?? setup.plugin}
        description={setup.description ?? t('setup.progress', '{done} of {total} steps done', { done: finished, total })}
      />
      <Stepper
        label={setup.title ?? setup.plugin}
        steps={setup.steps.map((s) => ({ label: s.title ?? s.id, hint: done[s.id] ? t('setup.done', 'Done') : undefined }))}
        current={current}
        stepOfLabel={(n, all) => t('setup.stepOf', 'Step {n} of {total}', { n, total: all })}
      />
      {step ? (
        <section className="space-y-4" aria-labelledby="setup-step-title">
          <div className="flex flex-wrap items-center gap-2">
            <h2 id="setup-step-title" className="text-base font-semibold text-foreground">
              {step.title ?? step.id}
            </h2>
            {done[step.id] ? <Badge variant="success">{t('setup.done', 'Done')}</Badge> : null}
          </div>
          {step.description ? <p className="text-sm text-muted-foreground">{step.description}</p> : null}
          <BlockRenderer key={step.id} blocks={step.blocks as unknown as Block[]} />
        </section>
      ) : null}
      <div className="flex flex-wrap justify-between gap-3 border-t border-border pt-4">
        <Button variant="outline" disabled={current === 0} onClick={() => onChoose(current - 1)}>
          {t('setup.back', 'Back')}
        </Button>
        {current < total - 1 ? (
          <Button
            variant="outline"
            onClick={() => {
              onRecheck();
              onChoose(current + 1);
            }}
          >
            {t('setup.next', 'Next')}
          </Button>
        ) : (
          <Button asChild variant="outline">
            <Link href="/">{t('setup.finish', 'Finish')}</Link>
          </Button>
        )}
      </div>
    </div>
  );
}
