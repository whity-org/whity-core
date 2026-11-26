'use client';

import Link from 'next/link';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { templateUpdatePath } from '@/lib/workspace-template';
import type { components } from '@whity/api-client';
import { useTranslation } from '@whity/features/i18n';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';

type Template = components['schemas']['WorkspaceTemplateCard'];

/** The templates this workspace chose that have a newer version on offer. */
export function useTemplateUpdates(): Template[] {
  const { data } = useFetch(async () => {
    const { data: body } = await api.GET('/api/v1/workspace/templates');
    const list = body?.data;
    if (!list) return [];
    return list.templates.filter((template) => list.chosen.includes(template.key) && template.update);
  }, []);

  return data ?? [];
}

/**
 * "Your {template} template has new pieces" (WHIT-1429, decision WT.2): on
 * the dashboard and on Settings › General, for people who may set the
 * workspace up, while a template the workspace uses has a newer version.
 * It only says so: "See what's new" opens the update, which is never applied
 * on its own.
 *
 * No card yet: brand ask brand-ask-workspace-templates.md §5.
 */
export function TemplateUpdateNotices() {
  const t = useTranslation('admin');
  const updates = useTemplateUpdates();

  if (updates.length === 0) {
    return null;
  }

  return (
    <div className="space-y-2">
      {updates.map((template) => (
        <Alert key={template.key}>
          <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
            <span>{t('templates.update.notice', 'Your {template} template has new pieces.', { template: template.name })}</span>
            <Button asChild variant="outline" size="sm">
              <Link href={templateUpdatePath(template.key)}>{t('templates.update.see', 'See what’s new')}</Link>
            </Button>
          </AlertDescription>
        </Alert>
      ))}
    </div>
  );
}
