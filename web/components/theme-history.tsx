'use client';

/**
 * A theme's versions (TH.25, 30-themes 11): its history newest first, who
 * saved each and when, any version beside the one in use, and Restore,
 * which brings it back as the next version so nothing is lost. Only the
 * workspace's own themes have a history; Whity's and plugins' come with
 * Whity or the plugin.
 */
import { useState } from 'react';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { ThemeDiff, type ThemeChangeLine } from '@/components/theme-diff';
import type { TranslateFn } from '@whity/features/i18n';

interface ThemeVersion {
  version: string;
  source: string;
  saved_at: string;
  saved_by?: string | null;
  current: boolean;
}

interface ThemeHistoryProps {
  themeId: string;
  name: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  canWrite: boolean;
  /** After a restore, so the picker and the app show the theme as it now is. */
  onRestored: () => void;
  /** A change in words; the picker's changeText. */
  describe: (change: ThemeChangeLine, t: TranslateFn) => string;
}

export function ThemeHistory({ themeId, name, open, onOpenChange, canWrite, onRestored, describe }: ThemeHistoryProps) {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const { addToast } = useToast();
  const [busy, setBusy] = useState<string | null>(null);
  const [comparing, setComparing] = useState<{ version: string; before: unknown; after: unknown } | null>(null);

  const { data, loading, error, refetch } = useFetch(async () => {
    if (!open) return null;
    const { data: body } = await api.GET('/api/v1/themes/{themeId}/versions', { params: { path: { themeId } } });
    if (!body) throw new Error('versions');
    return body.data;
  }, [themeId, open]);

  const sourceText = (source: string): string =>
    ({
      import: t('theme.history.source.import', 'Added from a file'),
      editor: t('theme.history.source.editor', 'Saved in the editor'),
      restore: t('theme.history.source.restore', 'Restored'),
    })[source] ?? source;

  const compare = async (version: string) => {
    setBusy(version);
    try {
      const [{ data: older }, { data: current }] = await Promise.all([
        api.GET('/api/v1/themes/{themeId}/versions/{version}', { params: { path: { themeId, version } } }),
        api.GET('/api/v1/themes/{themeId}', { params: { path: { themeId } } }),
      ]);
      if (!older || !current) throw new Error('compare');
      setComparing({ version, before: older.data.theme, after: current.data.theme });
    } catch {
      addToast(t('theme.history.compareFailed', 'This version could not be opened. Try again.'), 'error');
    } finally {
      setBusy(null);
    }
  };

  const restore = async (version: string) => {
    setBusy(version);
    try {
      const { data: body, error: restoreError } = await api.POST('/api/v1/themes/{themeId}/versions/{version}/restore', {
        params: { path: { themeId, version } },
      });
      if (restoreError || !body) throw new Error('restore');
      addToast(
        t('theme.history.restored', 'Version {from} is back as version {version}.', { from: version, version: body.data.version }),
        'success',
      );
      setComparing(null);
      refetch();
      onRestored();
    } catch {
      addToast(t('theme.history.restoreFailed', 'The version could not be restored. Try again.'), 'error');
    } finally {
      setBusy(null);
    }
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) setComparing(null);
        onOpenChange(next);
      }}
    >
      <DialogContent className="sm:max-w-3xl" data-testid="theme-history">
        <DialogHeader>
          <DialogTitle>{t('theme.history.title', 'Versions of {name}', { name })}</DialogTitle>
          <DialogDescription>
            {t('theme.history.description', 'Every saved version. Restoring one brings it back as a new version, so nothing is lost.')}
          </DialogDescription>
        </DialogHeader>
        {loading && !data && <p className="text-sm text-muted-foreground">{t('theme.history.loading', 'Loading versions…')}</p>}
        {error && !data && <p className="text-sm text-muted-foreground">{t('theme.history.loadFailed', 'The versions could not be loaded. Try again later.')}</p>}
        {data && comparing === null && (
          <ul className="grid divide-y divide-border rounded-card border border-border" data-testid="theme-history-list">
            {data.versions.map((v: ThemeVersion) => (
              <li key={v.version} className="flex flex-wrap items-center gap-3 p-3">
                <div className="grid min-w-0 flex-1 gap-0.5">
                  <div className="flex items-center gap-2">
                    <span className="font-mono text-sm" dir="ltr">
                      {v.version}
                    </span>
                    {v.current && <Badge variant="success">{t('theme.inUse', 'In use')}</Badge>}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {[sourceText(v.source), v.saved_by, dates.dateTime(v.saved_at)].filter(Boolean).join(' · ')}
                  </p>
                </div>
                {!v.current && (
                  <div className="flex gap-2">
                    <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => void compare(v.version)}>
                      {t('theme.history.compare', 'Compare with the one in use')}
                    </Button>
                    {canWrite && (
                      <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => void restore(v.version)}>
                        {t('theme.history.restore', 'Restore')}
                      </Button>
                    )}
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
        {comparing && (
          <div className="grid gap-3">
            <ThemeDiff
              before={comparing.before}
              after={comparing.after}
              beforeLabel={t('theme.history.version', 'Version {version}', { version: comparing.version })}
              afterLabel={t('theme.history.inUse', 'In use ({version})', { version: data?.current ?? '' })}
              describe={describe}
            />
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="outline" onClick={() => setComparing(null)}>
                {t('theme.history.back', 'Back to the versions')}
              </Button>
              {canWrite && (
                <Button disabled={busy !== null} onClick={() => void restore(comparing.version)}>
                  {t('theme.history.restoreThis', 'Restore version {version}', { version: comparing.version })}
                </Button>
              )}
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
