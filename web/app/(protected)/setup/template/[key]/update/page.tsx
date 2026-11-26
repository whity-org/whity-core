'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useHomePath } from '@/lib/branding-context';
import { NoAccess } from '@/components/no-access';
import { TemplatePlan, itemName, skipReason, type TemplateItem } from '@/components/workspace-templates/template-plan';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@whity/ui/card';
import { PageHeader } from '@whity/ui/page-header';
import { AreaError, LoadingRows } from '@whity/ui/page-states';

/** The parts "Use the template's version" works on, by the item's part. */
const ADOPTABLE = ['services', 'roles', 'forms', 'documents', 'pipelines'] as const;
type AdoptablePart = (typeof ADOPTABLE)[number];

/**
 * See what's new in a template this workspace uses (WHIT-1429; decisions
 * WT.2 and WT.3). Opened from "Your {template} template has new pieces" on
 * the dashboard and on Settings › General.
 *
 * It shows what the new version says it added, what applying the update
 * would add (from the dry run, examples and the look left out), and each
 * item the template has changed since the workspace got it. Those are left
 * exactly as the workspace has them; "Use the template's version" takes the
 * template's version of that one item, as the person, deleting nothing.
 * Apply update is the one filled button and adds only the new pieces.
 *
 * No card yet: brand ask brand-ask-workspace-templates.md §5. Built from the
 * record page header and the plan list the picker uses.
 */
export default function TemplateUpdatePage() {
  const t = useTranslation('admin');
  const router = useRouter();
  const homePath = useHomePath();
  const { addToast } = useToast();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const params = useParams<{ key: string }>();
  const key = String(params.key ?? '');
  const [version, setVersion] = useState(0);
  const [busy, setBusy] = useState<string | null>(null);

  const { data, loading, error, refetch } = useFetch(async () => {
    const [list, plan] = await Promise.all([
      api.GET('/api/v1/workspace/templates'),
      api.POST('/api/v1/workspace/templates/{key}/preview', {
        params: { path: { key } },
        body: { examples: false, theme: false },
      }),
    ]);
    const template = list.data?.data.templates.find((card) => card.key === key);
    if (!list.data || !plan.data || !template) {
      throw new Error(t('templates.update.loadError', 'What’s new could not be loaded.'));
    }
    return { template, items: plan.data.data.items ?? [] };
  }, [key, version]);

  if (capabilitiesLoading) {
    return <LoadingRows label={t('templates.update.loading', 'Loading what’s new')} headers={[t('templates.template', 'Template')]} />;
  }
  if (!hasPermission('settings:write')) {
    return <NoAccess area={t('templates.update.area', 'template updates')} permission="settings:write" />;
  }
  if (loading) {
    return <LoadingRows label={t('templates.update.loading', 'Loading what’s new')} headers={[t('templates.template', 'Template')]} />;
  }
  if (error || !data) {
    return <AreaError message={error ?? ''} retryLabel={t('plugins.tryAgain', 'Try again')} onRetry={() => void refetch()} />;
  }

  const { template, items } = data;
  const changed = items.filter((item) => item.action === 'changed');
  const adding = items.filter((item) => item.action === 'add');

  async function adopt(item: TemplateItem) {
    if (!(ADOPTABLE as readonly string[]).includes(item.part)) return;
    setBusy(`${item.part}:${item.key}`);
    try {
      const { data: answer, response } = await api.POST('/api/v1/workspace/templates/{key}/items/{part}/{item}/adopt', {
        params: { path: { key, part: item.part as AdoptablePart, item: item.key } },
      });
      const result = answer?.data;
      if (!response.ok || !result || result.action === 'skip') {
        addToast(skipReason(t, result?.reason), 'error');
        return;
      }
      addToast(
        result.action === 'keep'
          ? t('templates.update.alreadyMatches', '{name} already matches the template.', { name: itemName(t, item) })
          : t('templates.update.adopted', '{name} now uses the template’s version.', { name: itemName(t, item) }),
        'success',
      );
      setVersion((v) => v + 1);
    } finally {
      setBusy(null);
    }
  }

  async function applyUpdate() {
    setBusy('apply');
    try {
      const { response } = await api.POST('/api/v1/workspace/templates/{key}/apply', {
        params: { path: { key } },
        body: { examples: false, theme: false },
      });
      if (!response.ok) {
        addToast(t('templates.update.applyError', 'The update could not be applied. Try again.'), 'error');
        return;
      }
      addToast(t('templates.update.applied', 'Your {template} template is up to date.', { template: template.name }), 'success');
      router.push(homePath);
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 pb-16 md:px-0">
      <PageHeader
        title={t('templates.update.title', 'What’s new in {template}', { template: template.name })}
        description={t(
          'templates.update.description',
          'Nothing changes until you choose. Applying the update adds only the new pieces, and everything you changed stays as you have it.',
        )}
      />

      {template.update && template.update.changes.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold">{t('templates.update.changes', 'What the template says is new')}</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="list-disc space-y-1 ps-5 text-sm text-foreground">
              {template.update.changes.map((change) => (change.summary ? <li key={change.version}>{change.summary}</li> : null))}
            </ul>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle className="text-base font-semibold">{t('templates.update.adds', 'Applying the update adds')}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <TemplatePlan items={items} only={['add', 'skip']} />
          <div className="flex flex-wrap gap-3">
            <Button disabled={busy !== null} onClick={() => void applyUpdate()}>
              {busy === 'apply' ? t('templates.update.applying', 'Applying…') : t('templates.update.apply', 'Apply update')}
            </Button>
            <Button asChild variant="outline">
              <Link href={homePath}>{t('templates.update.notNow', 'Not now')}</Link>
            </Button>
          </div>
          {adding.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t('templates.update.recordOnly', 'Applying records that you have seen this version, so it stops asking.')}
            </p>
          ) : null}
        </CardContent>
      </Card>

      {changed.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold">{t('templates.update.changedTitle', 'Changed in the template')}</CardTitle>
            <p className="text-sm text-muted-foreground">
              {t(
                'templates.update.changedHint',
                'Yours stay as they are. Use the template’s version for one, and only what the template sets on it changes; nothing is deleted.',
              )}
            </p>
          </CardHeader>
          <CardContent>
            <TemplatePlan
              items={changed}
              renderAction={(item) =>
                (ADOPTABLE as readonly string[]).includes(item.part) ? (
                  <span className="flex w-full flex-col items-stretch gap-1 sm:w-auto sm:items-end">
                    <Button variant="outline" size="sm" disabled={busy !== null} onClick={() => void adopt(item)}>
                      {busy === `${item.part}:${item.key}`
                        ? t('templates.update.adopting', 'Updating…')
                        : t('templates.update.adopt', 'Use the template’s version')}
                    </Button>
                    <span className="text-xs text-muted-foreground">{adoptHint(t, item.part)}</span>
                  </span>
                ) : null
              }
            />
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}

/** What "Use the template's version" will do to this kind of item. */
function adoptHint(t: ReturnType<typeof useTranslation>, part: string): string {
  switch (part) {
    case 'services':
      return t('templates.adopt.services', 'Takes its name, length and price');
    case 'roles':
      return t('templates.adopt.roles', 'Adds the permissions it is missing');
    case 'forms':
      return t('templates.adopt.forms', 'Updates its questions’ wording and adds missing ones');
    case 'pipelines':
      return t('templates.adopt.pipelines', 'Makes a new review route beside yours');
    default:
      return t('templates.adopt.documents', 'Makes a new copy beside yours');
  }
}
