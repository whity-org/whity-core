'use client';

import { Suspense } from 'react';
import { useSearchParams } from 'next/navigation';
import { NoAccess } from '@/components/no-access';
import { ThemeEditor, type ThemeFile } from '@/components/theme-editor';
import type { FontOption } from '@/components/theme-picker';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { api } from '@/lib/api/client';
import { useTranslation } from '@whity/features/i18n';
import { SETTINGS_WRITE, SYSTEM_TENANT_ID } from '../../settings-shared';

const THEME_ID = /^[a-z0-9]+(-[a-z0-9]+)*\.[a-z0-9]+(-[a-z0-9]+)*$/;

/**
 * Settings › Branding › Theme editor (TH.25, 30-themes 11): free for every
 * workspace. `?from=<theme id>` starts from that theme: the workspace's own is
 * edited, any other is copied. Without it, the editor starts from Whity's.
 */
function ThemeEditorPage() {
  const t = useTranslation('admin');
  const params = useSearchParams();
  const requested = params.get('from') ?? '';
  const from = THEME_ID.test(requested) ? requested : 'whity.default';

  const { data, loading, error } = useFetch(async () => {
    const [{ data: one }, { data: list }] = await Promise.all([
      api.GET('/api/v1/themes/{themeId}', { params: { path: { themeId: from } } }),
      api.GET('/api/v1/themes'),
    ]);
    if (!one) throw new Error('theme');
    return {
      theme: one.data.theme as ThemeFile,
      editable: one.data.editable,
      fonts: (list?.data.options?.font ?? []) as FontOption[],
    };
  }, [from]);

  if (loading && !data) {
    return <p className="p-6 text-sm text-muted-foreground">{t('theme.loading', 'Loading themes…')}</p>;
  }
  if (error || !data) {
    return <p className="p-6 text-sm text-muted-foreground">{t('theme.editor.loadFailed', 'This theme could not be opened. Go back to Branding and try again.')}</p>;
  }

  return <ThemeEditor key={from} start={data.theme} editable={data.editable} fonts={data.fonts} />;
}

export default function ThemeEditorRoute() {
  const { user } = useAuth();
  const { hasPermission, loading } = useCapabilities();
  const t = useTranslation('admin');

  if (loading) {
    return (
      <div className="flex min-h-96 items-center justify-center" aria-busy="true">
        <div className="size-8 animate-spin rounded-full border-b-2 border-primary motion-reduce:animate-none" />
      </div>
    );
  }
  // Themes are made in a workspace; the installation chooses among Whity's own.
  if (!hasPermission(SETTINGS_WRITE) || user?.tenant_id === SYSTEM_TENANT_ID) {
    return <NoAccess area={t('access.area.settings.branding.accessDenied', 'branding')} permission="settings:write" />;
  }

  return (
    <div className="mx-auto max-w-7xl px-4 pb-16 md:px-0">
      <Suspense fallback={null}>
        <ThemeEditorPage />
      </Suspense>
    </div>
  );
}
