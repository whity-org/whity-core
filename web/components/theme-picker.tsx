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
 *
 * Free since TH.15 and TH.16: pick the corners, spacing and fonts on top of
 * the theme (saved in `branding_theme_overrides` next to the colour
 * overrides), export the theme in use as a file, and see in words what a
 * newer version of an imported theme changes before it replaces the old one.
 */

import { useRef, useState } from 'react';
import { IconCheck, IconDownload, IconTrash, IconUpload } from '@tabler/icons-react';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@whity/ui/alert-dialog';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
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
  /** The plugin that ships it, for a plugin's theme (SDK 1.125). */
  plugin?: string | null;
  current: boolean;
  main_color_replaces_seed?: boolean;
  fonts?: Record<string, string>;
  preview: { light?: Swatch; dark?: Swatch; radius?: string; card_radius?: string };
  adjustments?: { mode: string; role: string; change: string }[];
}

export type TweakKey = 'shape' | 'density' | 'font';
export type Tweaks = Record<TweakKey, string | null>;

interface ThemeList {
  current: string;
  themes: ThemeListItem[];
  tweaks?: Tweaks;
  options?: { shape: string[]; density: string[]; font: { id: string; name: string }[] };
  colour_overrides?: { light?: Record<string, string>; dark?: Record<string, string> };
}

export interface ThemeChange {
  field: string;
  from: string | null;
  to: string | null;
}

interface PendingImport {
  theme: unknown;
  name: string;
  from: string;
  to: string;
  changes: ThemeChange[];
}

/** The `branding_theme_overrides` value: the colour overrides kept, the tweaks set (TH.15). */
export function overridesValue(colour: ThemeList['colour_overrides'], tweaks: Tweaks): string {
  const out: Record<string, unknown> = {};
  for (const mode of ['light', 'dark'] as const) {
    const map = colour?.[mode];
    if (map && Object.keys(map).length > 0) out[mode] = map;
  }
  for (const key of ['shape', 'density', 'font'] as const) {
    if (tweaks[key]) out[key] = tweaks[key];
  }
  return Object.keys(out).length === 0 ? '' : JSON.stringify(out);
}

/** A tweak's option in plain words. */
function optionName(key: TweakKey, value: string, t: TranslateFn, fonts: { id: string; name: string }[]): string {
  if (key === 'font') return fonts.find((f) => f.id === value)?.name ?? value;
  if (key === 'shape') {
    const names: Record<string, string> = {
      square: t('theme.tweak.shape.square', 'Square'),
      crisp: t('theme.tweak.shape.crisp', 'Crisp'),
      whity: t('theme.tweak.shape.whity', 'Whity'),
      soft: t('theme.tweak.shape.soft', 'Soft'),
      round: t('theme.tweak.shape.round', 'Round'),
    };
    return names[value] ?? value;
  }
  const names: Record<string, string> = {
    compact: t('theme.tweak.density.compact', 'Compact'),
    standard: t('theme.tweak.density.standard', 'Standard'),
    comfortable: t('theme.tweak.density.comfortable', 'Comfortable'),
  };
  return names[value] ?? value;
}

/** What a newer version of a theme changes, in words (TH.16). */
export function changeText(change: ThemeChange, t: TranslateFn): string {
  const none = t('theme.change.none', 'none');
  const labels: Record<string, string> = {
    name: t('theme.change.name', 'Name'),
    'colour.primary': t('theme.change.primary', 'Main colour'),
    'colour.secondary': t('theme.change.secondary', 'Second colour'),
    'colour.tertiary': t('theme.change.tertiary', 'Accent colour'),
    'colour.scheme': t('theme.change.scheme', 'Colour style'),
    'colour.neutral': t('theme.change.neutral', 'Greys'),
    'colour.contrast': t('theme.change.contrast', 'Contrast'),
    'colour.overrides': t('theme.change.overrides', 'Colours set by hand'),
    'type.family': t('theme.change.font', 'Text font'),
    'type.headingFamily': t('theme.change.headingFont', 'Heading font'),
    'type.scale': t('theme.change.scale', 'Text size'),
    'type.titleWeight': t('theme.change.titleWeight', 'Title weight'),
    'shape.preset': t('theme.change.shape', 'Corners'),
    'shape.morph': t('theme.change.morph', 'Shape change on press'),
    density: t('theme.change.density', 'Spacing'),
    'elevation.style': t('theme.change.elevation', 'Shadows and borders'),
    'elevation.float': t('theme.change.float', 'Shadow strength'),
    'motion.preset': t('theme.change.motion', 'Animations'),
  };
  return t('theme.change.line', '{what}: {from} → {to}', {
    what: labels[change.field] ?? change.field,
    from: change.from ?? none,
    to: change.to ?? none,
  });
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
    return body.data as ThemeList;
  }, []);
  const [pending, setPending] = useState<PendingImport | null>(null);

  const saveSettings = async (settings: Record<string, string>) => {
    const { error: saveError } =
      scope === 'global'
        ? await api.PATCH('/api/v1/settings/global', { body: { settings } })
        : await api.PATCH('/api/v1/settings', { body: { settings } });
    if (saveError) throw new Error('save');
  };

  const tweak = async (key: TweakKey, value: string) => {
    setBusy(`tweak-${key}`);
    try {
      const tweaks: Tweaks = { shape: null, density: null, font: null, ...(data?.tweaks ?? {}), [key]: value === 'theme' ? null : value };
      await saveSettings({ branding_theme_overrides: overridesValue(data?.colour_overrides, tweaks) });
      refetch();
      void refreshAppBranding();
      addToast(t('theme.tweak.saved', 'Saved.'), 'success');
    } catch {
      addToast(t('theme.applyFailed', 'The theme could not be applied. Try again.'), 'error');
    } finally {
      setBusy(null);
    }
  };

  const exportTheme = async () => {
    setBusy('export');
    try {
      const { data: body, error: exportError } = await api.GET('/api/v1/themes/export');
      if (exportError || !body) throw new Error('export');
      const { theme, filename } = body.data;
      const url = URL.createObjectURL(new Blob([`${JSON.stringify(theme, null, 2)}\n`], { type: 'application/json' }));
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      link.click();
      URL.revokeObjectURL(url);
    } catch {
      addToast(t('theme.export.failed', 'The theme could not be exported. Try again.'), 'error');
    } finally {
      setBusy(null);
    }
  };

  const saveImport = async (theme: unknown) => {
    const { error: importError } = await api.POST('/api/v1/themes', { body: theme as never });
    if (importError) {
      const reason = (importError as { errors?: { theme?: string } }).errors?.theme;
      addToast(reason ?? t('theme.import.failed', 'This theme file can’t be used.'), 'error');
      return;
    }
    refetch();
    void refreshAppBranding();
    addToast(t('theme.import.done', 'Theme added. Choose it below to use it.'), 'success');
  };

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
      // TH.16: check first, and say what a newer version changes before it replaces the old one.
      const { data: checked, error: checkError } = await api.POST('/api/v1/themes', {
        params: { query: { check: '1' } },
        body: theme as never,
      });
      if (checkError || !checked) {
        const reason = (checkError as { errors?: { theme?: string } } | undefined)?.errors?.theme;
        addToast(reason ?? t('theme.import.failed', 'This theme file can’t be used.'), 'error');
        return;
      }
      const info = checked.data;
      const changes = info.changes ?? [];
      if (info.installed_version && changes.length > 0) {
        const existing = (data?.themes ?? []).find((item) => item.id === info.id);
        setPending({
          theme,
          name: (existing && pick(existing.name, lang)) || info.id,
          from: info.installed_version,
          to: info.version,
          changes,
        });
        return;
      }
      await saveImport(theme);
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
  const options = data?.options;
  const tweaks: Tweaks = { shape: null, density: null, font: null, ...(data?.tweaks ?? {}) };
  const tweakLabels: Record<TweakKey, string> = {
    shape: t('theme.tweak.shape', 'Corners'),
    density: t('theme.tweak.density', 'Spacing'),
    font: t('theme.tweak.font', 'Fonts'),
  };

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
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" disabled={busy !== null || !data} onClick={() => void exportTheme()}>
            <IconDownload aria-hidden="true" />
            {t('theme.export', 'Export theme')}
          </Button>
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
        </div>
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
                  {item.source === 'plugin' && item.plugin && (
                    <p className="text-xs text-muted-foreground">{t('theme.fromPlugin', 'From {plugin}', { plugin: item.plugin })}</p>
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
        {canWrite && options && (
          <div className="grid gap-3 rounded-card border p-3" data-testid="theme-tweaks">
            <div className="grid gap-0.5">
              <span className="text-sm font-medium">{t('theme.tweak.title', 'Adjust this theme')}</span>
              <span className="text-xs text-muted-foreground">
                {t('theme.tweak.hint', 'Free, like the main colour. These stay when you choose another theme.')}
              </span>
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
              {(['shape', 'density', 'font'] as const).map((key) => {
                const values = key === 'font' ? options.font.map((f) => f.id) : options[key];
                return (
                  <div key={key} className="grid gap-1.5">
                    <label htmlFor={`theme-tweak-${key}`} className="text-sm font-medium">
                      {tweakLabels[key]}
                    </label>
                    <Select value={tweaks[key] ?? 'theme'} onValueChange={(value) => void tweak(key, value)} disabled={busy !== null}>
                      <SelectTrigger id={`theme-tweak-${key}`} data-testid={`theme-tweak-${key}`}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent position="popper">
                        <SelectItem value="theme">{t('theme.tweak.fromTheme', 'As the theme sets it')}</SelectItem>
                        {values.map((value) => (
                          <SelectItem key={value} value={value}>
                            {optionName(key, value, t, options.font)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                );
              })}
            </div>
          </div>
        )}
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
      <AlertDialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
        <AlertDialogContent data-testid="theme-import-changes">
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('theme.update.title', 'Update {name} from {from} to {to}?', {
                name: pending?.name ?? '',
                from: pending?.from ?? '',
                to: pending?.to ?? '',
              })}
            </AlertDialogTitle>
            <AlertDialogDescription>{t('theme.update.description', 'The new version changes:')}</AlertDialogDescription>
          </AlertDialogHeader>
          <ul className="grid gap-1 text-sm">
            {(pending?.changes ?? []).map((change) => (
              <li key={change.field}>{changeText(change, t)}</li>
            ))}
          </ul>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('theme.update.keep', 'Keep the old version')}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                const next = pending;
                setPending(null);
                if (next) void saveImport(next.theme);
              }}
            >
              {t('theme.update.confirm', 'Update')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  );
}
