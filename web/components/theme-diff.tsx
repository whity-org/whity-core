'use client';

/**
 * The side-by-side diff (TH.25, 30-themes 11 and 14.7): two themes, what the
 * newer one changes in words and both drawn with the real components, light
 * or dark. The server compiles both (POST /themes/compare), so each looks as
 * it would in use; nothing is saved.
 *
 * Used where a theme is about to replace another: a newer version of an
 * imported theme (TH.16), and the editor's draft against where it started.
 */
import { useEffect, useMemo, useState } from 'react';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { api } from '@/lib/api/client';
import { previewThemeCss } from '@/lib/brand-theme-css';
import { ThemeSample } from '@/components/theme-sample';

export interface ThemeChangeLine {
  field: string;
  from: string | null;
  to: string | null;
}

interface Compared {
  changes: ThemeChangeLine[];
  before: Record<string, unknown>;
  after: Record<string, unknown>;
}

interface ThemeDiffProps {
  before: unknown;
  after: unknown;
  beforeLabel: string;
  afterLabel: string;
  /** A change in words; the picker's changeText. */
  describe: (change: ThemeChangeLine, t: TranslateFn) => string;
}

export function ThemeDiff({ before, after, beforeLabel, afterLabel, describe }: ThemeDiffProps) {
  const t = useTranslation('admin');
  const [mode, setMode] = useState<'light' | 'dark'>('light');
  const [compared, setCompared] = useState<Compared | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let live = true;
    void api.POST('/api/v1/themes/compare', { body: { before, after } as never }).then(({ data: body, error }) => {
      if (!live) return;
      setFailed(Boolean(error) || !body);
      if (body && !error) setCompared(body.data as Compared);
    });
    return () => {
      live = false;
    };
  }, [before, after]);

  const css = useMemo(
    () => (compared ? previewThemeCss(compared.before, 'before-') + previewThemeCss(compared.after, 'after-') : ''),
    [compared],
  );

  if (failed) {
    return <p className="text-sm text-muted-foreground">{t('theme.diff.failed', 'The two versions could not be shown side by side.')}</p>;
  }
  if (!compared) {
    return <p className="text-sm text-muted-foreground">{t('theme.diff.loading', 'Putting the two versions side by side…')}</p>;
  }

  return (
    <div className="grid gap-3" data-testid="theme-diff">
      {css !== '' && <style>{css}</style>}
      {compared.changes.length > 0 ? (
        <ul className="grid list-disc gap-1 ps-5 text-sm" data-testid="theme-diff-changes">
          {compared.changes.map((change) => (
            <li key={change.field}>{describe(change, t)}</li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted-foreground">{t('theme.diff.same', 'The two versions look the same.')}</p>
      )}
      <ToggleGroup
        label={t('theme.diff.mode', 'Show light or dark')}
        required
        value={mode}
        onChange={(next) => setMode(next === 'dark' ? 'dark' : 'light')}
        options={[
          { value: 'light', label: t('theme.editor.light', 'Light') },
          { value: 'dark', label: t('theme.editor.dark', 'Dark') },
        ]}
      />
      <div className="grid gap-3 md:grid-cols-2">
        <div className="grid content-start gap-1.5">
          <span className="text-sm font-medium">{beforeLabel}</span>
          <ThemeSample mode={mode} scope="before-" />
        </div>
        <div className="grid content-start gap-1.5">
          <span className="text-sm font-medium">{afterLabel}</span>
          <ThemeSample mode={mode} scope="after-" />
        </div>
      </div>
    </div>
  );
}
