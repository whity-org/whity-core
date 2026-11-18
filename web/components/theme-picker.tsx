'use client';

/**
 * Settings › Branding › Theme (WHIT-1225, TH.13, TH.14): pick one of the
 * installed themes. Applying a theme is free; making one is Whity Studio's.
 * Each card previews the theme in the workspace's main colour, which replaces
 * the theme's own colour (30-themes.md section 5), and says in words what the
 * guard rails changed for the theme in use.
 *
 * A workspace can also import a finished theme file and remove one it
 * imported. The installation (system tenant) chooses among Whity's own.
 */

import { useRef, useState } from 'react';
import { IconCheck, IconTrash, IconUpload } from '@tabler/icons-react';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { useLanguageCode, useTranslation, type TranslateFn } from '@whity/features/i18n';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { useRefreshBranding } from '@/lib/branding-context';
import { useThemeMode } from '@/lib/theme-mode-context';

type Swatch = Record<string, string>;

export interface ThemeListItem {
  id: string;
  version: string;
  name: Record<string, string>;
  description?: Record<string, string> | null;
  source: string;
  current: boolean;
  main_color_replaces_seed?: boolean;
  fonts?: Record<string, string>;
  preview: { light?: Swatch; dark?: Swatch; radius?: string; card_radius?: string };
  adjustments?: { mode: string; role: string; change: string }[];
}

interface ThemePickerProps {
  scope: 'tenant' | 'global';
  canWrite: boolean;
}

const HEX = /^#[0-9a-f]{6}$/;
const PX = /^\d+px$/;

/** A preview value only when it is a plain colour or length: it goes into a style attribute. */
function safe(value: string | undefined, pattern: RegExp, fallback: string): string {
  return value !== undefined && pattern.test(value) ? value : fallback;
}

function pick(text: Record<string, string> | null | undefined, lang: string | null): string {
  if (!text) return '';
  return (lang === 'ar' ? text.ar : undefined) ?? text.en ?? '';
}

/** A colour role in plain words: token names are not for people (A.3). */
function roleName(role: string, t: TranslateFn): string {
  if (role === 'primary' || role === 'brand') return t('theme.role.primary', 'The main colour');
  if (role.endsWith('-foreground') && (role.startsWith('primary') || role.startsWith('brand'))) return t('theme.role.buttonText', 'Button text');
  if (role === 'ring') return t('theme.role.ring', 'The focus outline');
  if (role === 'muted-foreground') return t('theme.role.muted', 'Secondary text');
  if (role === 'foreground' || role.endsWith('-foreground')) return t('theme.role.text', 'Text');
  if (role === 'background') return t('theme.role.page', 'The page');
  if (role === 'card' || role === 'popover') return t('theme.role.card', 'Cards');
  if (role === 'input') return t('theme.role.input', 'Field borders');
  if (role.startsWith('tertiary')) return t('theme.role.tertiary', 'The accent colour');
  if (role.startsWith('sidebar')) return t('theme.role.sidebar', 'The sidebar');
  return t('theme.role.other', 'A colour');
}

/** What the guard rails changed, in words (section 6.1). */
export function adjustmentText(change: string, rawRole: string, t: TranslateFn): string {
  const role = roleName(rawRole, t);
  switch (change) {
    case 'override-dropped':
      return t('theme.adjust.overrideDropped', 'Your colour for “{role}” was too hard to read against its neighbour, so the theme’s own is used.', { role });
    case 'label':
      return t('theme.adjust.label', 'The text on {role} was switched to white or black so it can be read.', { role });
    case 'darkened':
      return t('theme.adjust.darkened', '{role} was darkened a little so it can be read.', { role });
    case 'lightened':
      return t('theme.adjust.lightened', '{role} was lightened a little so it can be read.', { role });
    case 'chart-colours-kept':
      return t('theme.adjust.charts', 'Charts keep Whity’s colours, which stay apart for colour-blind readers.');
    case 'theme-missing':
      return t('theme.adjust.missing', 'Your theme was removed, so the installation’s theme is in use.');
    default:
      return change;
  }
}

function ThemePreview({ item, dark }: { item: ThemeListItem; dark: boolean }) {
  const s = (dark ? item.preview.dark : item.preview.light) ?? {};
  const radius = safe(item.preview.radius, PX, '10px');
  const cardRadius = safe(item.preview.card_radius, PX, '12px');
  return (
    <div
      aria-hidden="true"
      className="grid gap-2 p-3"
      style={{ background: safe(s.background, HEX, '#eeeeee'), borderRadius: cardRadius }}
    >
      <div
        className="grid gap-1.5 border p-3"
        style={{
          background: safe(s.card, HEX, '#ffffff'),
          borderColor: safe(s.border, HEX, '#e5e5e5'),
          borderRadius: cardRadius,
        }}
      >
        <div className="h-2.5 w-2/3 rounded-full" style={{ background: safe(s.foreground, HEX, '#252525') }} />
        <div className="h-2 w-1/2 rounded-full" style={{ background: safe(s['muted-foreground'], HEX, '#696969') }} />
        <div className="mt-1 flex items-center gap-2">
          <span
            className="inline-flex h-6 w-16 items-center justify-center"
            style={{ background: safe(s.primary, HEX, '#0961bb'), borderRadius: radius }}
          >
            <span className="h-1.5 w-8 rounded-full" style={{ background: safe(s['primary-foreground'], HEX, '#ffffff') }} />
          </span>
          <span className="size-4 rounded-full" style={{ background: safe(s.tertiary, HEX, '#7a5a9e') }} />
        </div>
      </div>
    </div>
  );
}

export function ThemePicker({ scope, canWrite }: ThemePickerProps) {
  const t = useTranslation('admin');
  const lang = useLanguageCode();
  const { addToast } = useToast();
  const refreshAppBranding = useRefreshBranding();
  const { resolved } = useThemeMode();
  const fileInput = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body } = await api.GET('/api/v1/themes');
    if (!body) throw new Error('themes');
    return body.data as { current: string; themes: ThemeListItem[] };
  }, []);

  const apply = async (id: string) => {
    setBusy(id);
    try {
      const settings = { branding_theme: id === 'whity.default' ? '' : id };
      const { error: saveError } =
        scope === 'global'
          ? await api.PATCH('/api/v1/settings/global', { body: { settings } })
          : await api.PATCH('/api/v1/settings', { body: { settings } });
      if (saveError) throw new Error('save');
      refetch();
      void refreshAppBranding();
      addToast(t('theme.applied', 'Theme applied.'), 'success');
    } catch {
      addToast(t('theme.applyFailed', 'The theme could not be applied. Try again.'), 'error');
    } finally {
      setBusy(null);
    }
  };

  const importFile = async (file: File) => {
    setBusy('import');
    try {
      const text = await file.text();
      let theme: unknown;
      try {
        theme = JSON.parse(text);
      } catch {
        addToast(t('theme.import.notJson', 'This file isn’t a theme file.'), 'error');
        return;
      }
      const { error: importError } = await api.POST('/api/v1/themes', { body: theme as never });
      if (importError) {
        const reason = (importError as { errors?: { theme?: string } }).errors?.theme;
        addToast(reason ?? t('theme.import.failed', 'This theme file can’t be used.'), 'error');
        return;
      }
      refetch();
      addToast(t('theme.import.done', 'Theme added. Choose it below to use it.'), 'success');
    } finally {
      setBusy(null);
      if (fileInput.current) fileInput.current.value = '';
    }
  };

  const remove = async (id: string) => {
    setBusy(id);
    try {
      const { error: deleteError } = await api.DELETE('/api/v1/themes/{themeId}', { params: { path: { themeId: id } } });
      if (deleteError) throw new Error('delete');
      refetch();
      void refreshAppBranding();
      addToast(t('theme.removed', 'Theme removed.'), 'success');
    } catch {
      addToast(t('theme.removeFailed', 'The theme could not be removed. Try again.'), 'error');
    } finally {
      setBusy(null);
    }
  };

  const themes = data?.themes ?? [];
  const current = themes.find((item) => item.current);

  return (
    <Card data-testid="theme-picker">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="grid gap-1">
          <CardTitle>{t('theme.title', 'Theme')}</CardTitle>
          <CardDescription>
            {scope === 'global'
              ? t('theme.description.platform', 'The look every workspace starts with: colours, fonts, corners and motion. Free to change.')
              : t('theme.description', 'Colours, fonts, corners and motion for everyone in this workspace. Applying a theme is free.')}
          </CardDescription>
        </div>
        {canWrite && scope === 'tenant' && (
          <>
            <input
              ref={fileInput}
              type="file"
              accept="application/json,.json"
              className="sr-only"
              aria-label={t('theme.import', 'Add a theme file')}
              onChange={(event) => {
                const file = event.target.files?.[0];
                if (file) void importFile(file);
              }}
            />
            <Button variant="outline" disabled={busy !== null} onClick={() => fileInput.current?.click()}>
              <IconUpload aria-hidden="true" />
              {t('theme.import', 'Add a theme file')}
            </Button>
          </>
        )}
      </CardHeader>
      <CardContent className="grid gap-4">
        {loading && !data && <p className="text-sm text-muted-foreground">{t('theme.loading', 'Loading themes…')}</p>}
        {error && !data && (
          <p className="text-sm text-muted-foreground">{t('theme.loadFailed', 'The themes could not be loaded. Try again later.')}</p>
        )}
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {themes.map((item) => {
            const name = pick(item.name, lang) || item.id;
            return (
              <li key={item.id} className="grid gap-3 rounded-card border bg-card p-3" data-theme-id={item.id}>
                <ThemePreview item={item} dark={resolved === 'dark'} />
                <div className="grid gap-1">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-semibold">{name}</span>
                    {item.current && (
                      <Badge variant="success">
                        <IconCheck aria-hidden="true" className="size-3.5" />
                        {t('theme.inUse', 'In use')}
                      </Badge>
                    )}
                  </div>
                  {item.description && <p className="text-xs text-muted-foreground">{pick(item.description, lang)}</p>}
                  {item.fonts?.body && (
                    <p className="text-xs text-muted-foreground">
                      {item.fonts.heading && item.fonts.heading !== item.fonts.body
                        ? t('theme.fonts.two', 'Text in {body}, headings in {heading}', { body: item.fonts.body, heading: item.fonts.heading })
                        : t('theme.fonts.one', 'Text in {body}', { body: item.fonts.body })}
                    </p>
                  )}
                  {item.main_color_replaces_seed && (
                    <p className="text-xs text-muted-foreground">
                      {t('theme.mainColourReplaces', 'Shown in your main colour, which replaces the theme’s own.')}
                    </p>
                  )}
                  {item.source === 'import' && (
                    <p className="text-xs text-muted-foreground">{t('theme.imported', 'Added by this workspace')}</p>
                  )}
                </div>
                {canWrite && (
                  <div className="flex flex-wrap gap-2">
                    {!item.current && (
                      <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => void apply(item.id)}>
                        {t('theme.use', 'Use this theme')}
                      </Button>
                    )}
                    {item.source === 'import' && scope === 'tenant' && (
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={busy !== null}
                        onClick={() => void remove(item.id)}
                        aria-label={t('theme.remove.label', 'Remove {name}', { name })}
                      >
                        <IconTrash aria-hidden="true" />
                        {t('theme.remove', 'Remove')}
                      </Button>
                    )}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
        {current && (current.adjustments ?? []).length > 0 && (
          <div className="grid gap-1 rounded-control bg-muted p-3" data-testid="theme-adjustments">
            <span className="text-sm font-medium">{t('theme.adjustments', 'Changed so everyone can read it')}</span>
            <ul className="grid gap-0.5 text-xs text-muted-foreground">
              {Array.from(new Set((current.adjustments ?? []).map((a) => adjustmentText(a.change, a.role, t)))).map((text) => (
                <li key={text}>{text}</li>
              ))}
            </ul>
          </div>
        )}
        <p className="text-xs text-muted-foreground">
          {t('theme.studio', 'To make your own theme, use Whity Studio. Everyone can still choose Larger text, Higher contrast and Calm animations for themselves.')}
        </p>
      </CardContent>
    </Card>
  );
}
