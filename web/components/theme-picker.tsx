'use client';

/**
 * Settings › Branding › Theme (WHIT-1225, TH.13, TH.14): pick one of the
 * installed themes. Applying a theme is free, and since TH.25 so is making one (the editor is core).
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
 *
 * Free since TH.25: turn a theme made with shadcn, Tailwind or Material Theme
 * Builder into a theme file (POST /themes/convert), see it and what was kept
 * in words, then add it through the same checked import.
 *
 * Free since TH.25: export the theme in use for another tool, as CSS
 * variables, design tokens JSON, Tailwind CSS or a Flutter file.
 */

import { useRef, useState } from 'react';
import Link from 'next/link';
import { IconCheck, IconChevronDown, IconDownload, IconFileImport, IconPalette, IconTrash, IconUpload } from '@tabler/icons-react';
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
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { Input } from '@whity/ui/input';
import { Textarea } from '@whity/ui/textarea';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useLanguageCode, useTranslation, type TranslateFn } from '@whity/features/i18n';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { useRefreshBranding } from '@/lib/branding-context';
import { useThemeMode } from '@/lib/theme-mode-context';

type Swatch = Record<string, string>;

/** The workspace's own themes: imported from a file or made in the editor (TH.25). */
const OWN = ['import', 'editor'];

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

export interface FontOption {
  id: string;
  name: string;
  /** The pair's CSS stacks (TH.20), checked before they reach a style attribute. */
  stack?: string;
  heading_stack?: string;
}

const FONT_STACK = /^var\(--font-[a-z0-9-]+\)(?:, var\(--font-[a-z0-9-]+\))*, ui-sans-serif, system-ui, sans-serif$/;

/** A font stack for a style attribute, only when it has the one shape the engine writes. */
export function fontStack(value: string | undefined): string | undefined {
  return value !== undefined && FONT_STACK.test(value) ? value : undefined;
}
export type Tweaks = Record<TweakKey, string | null>;

interface ThemeList {
  current: string;
  themes: ThemeListItem[];
  tweaks?: Tweaks;
  options?: { shape: string[]; density: string[]; font: FontOption[] };
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

export const TOKEN_FORMATS = ['css', 'json', 'tailwind', 'flutter'] as const;
export type TokenFormat = (typeof TOKEN_FORMATS)[number];

/** An export format in plain words (TH.25). */
export function tokenFormatName(format: TokenFormat, t: TranslateFn): string {
  return {
    css: t('theme.export.css', 'CSS variables'),
    json: t('theme.export.json', 'Design tokens (JSON)'),
    tailwind: t('theme.export.tailwind', 'Tailwind CSS'),
    flutter: t('theme.export.flutter', 'Flutter'),
  }[format];
}

/** Hands the person a file to keep, made here from text. */
export function saveFile(content: string, filename: string, type: string): void {
  const url = URL.createObjectURL(new Blob([content], { type }));
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

/** A tweak's option in plain words. */
function optionName(key: TweakKey, value: string, t: TranslateFn, fonts: FontOption[]): string {
  if (key === 'font') return fonts.find((f) => f.id === value)?.name ?? value;
  if (key === 'shape') {
    const names: Record<string, string> = {
      square: t('theme.tweak.shape.square', 'Square'),
      crisp: t('theme.tweak.shape.crisp', 'Crisp'),
      whity: t('theme.tweak.shape.whity', 'Whity'),
      soft: t('theme.tweak.shape.soft', 'Soft'),
      round: t('theme.tweak.shape.round', 'Round'),
      pebble: t('theme.tweak.shape.pebble', 'Pebble'),
      cut: t('theme.tweak.shape.cut', 'Cut'),
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
    'shape.components.buttons': t('theme.change.corners.buttons', 'Corners of buttons'),
    'shape.components.fields': t('theme.change.corners.fields', 'Corners of fields'),
    'shape.components.chips': t('theme.change.corners.chips', 'Corners of chips and tags'),
    'shape.components.cards': t('theme.change.corners.cards', 'Corners of cards'),
    'shape.components.dialogs': t('theme.change.corners.dialogs', 'Corners of dialogs and menus'),
    'shape.components.tables': t('theme.change.corners.tables', 'Corners of tables'),
    'shape.components.navigation': t('theme.change.corners.navigation', 'Corners of tabs and the sidebar'),
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
export function roleName(role: string, t: TranslateFn): string {
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

function ThemePreview({ preview, dark }: { preview: ThemeListItem['preview']; dark: boolean }) {
  const s = (dark ? preview.dark : preview.light) ?? {};
  const radius = safe(preview.radius, PX, '10px');
  const cardRadius = safe(preview.card_radius, PX, '12px');
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

type ConvertFormat = 'shadcn' | 'tailwind' | 'material';

interface Converted {
  theme: unknown;
  notes: string[];
  preview: ThemeListItem['preview'];
}

/**
 * TH.25: paste or choose what another tool made, see it as a Whity theme and
 * what was kept, then add it. Nothing is saved until "Add this theme".
 */
function ConvertThemeDialog({
  open,
  onOpenChange,
  onAdd,
  dark,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAdd: (theme: unknown) => Promise<boolean>;
  dark: boolean;
}) {
  const t = useTranslation('admin');
  const [format, setFormat] = useState<ConvertFormat>('shadcn');
  const [name, setName] = useState('');
  const [source, setSource] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [result, setResult] = useState<Converted | null>(null);
  const [working, setWorking] = useState(false);
  const file = useRef<HTMLInputElement>(null);

  const formats: Record<ConvertFormat, { label: string; hint: string; accept: string }> = {
    shadcn: {
      label: t('theme.convert.shadcn', 'shadcn'),
      hint: t('theme.convert.shadcnHint', 'Paste the :root and .dark blocks from your globals.css.'),
      accept: '.css,text/css,text/plain',
    },
    tailwind: {
      label: t('theme.convert.tailwind', 'Tailwind CSS'),
      hint: t('theme.convert.tailwindHint', 'Paste the @theme block with your --color-* variables.'),
      accept: '.css,text/css,text/plain',
    },
    material: {
      label: t('theme.convert.material', 'Material Theme Builder'),
      hint: t('theme.convert.materialHint', 'Export as JSON from Material Theme Builder and paste or choose the file.'),
      accept: '.json,application/json',
    },
  };

  const reset = () => {
    setResult(null);
    setProblem(null);
  };

  const convert = async () => {
    setWorking(true);
    setProblem(null);
    try {
      const { data: body, error: convertError } = await api.POST('/api/v1/themes/convert', {
        body: { format, source, name },
      });
      if (convertError || !body) {
        const reason = (convertError as { errors?: { source?: string } } | undefined)?.errors?.source;
        setProblem(reason ?? t('theme.convert.failed', 'This could not be turned into a theme. Check what you pasted and try again.'));
        return;
      }
      setResult({ theme: body.data.theme, notes: body.data.notes, preview: body.data.preview as ThemeListItem['preview'] });
    } finally {
      setWorking(false);
    }
  };

  const add = async () => {
    if (!result) return;
    setWorking(true);
    try {
      if (await onAdd(result.theme)) {
        onOpenChange(false);
        setSource('');
        setName('');
        reset();
      }
    } finally {
      setWorking(false);
    }
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) reset();
        onOpenChange(next);
      }}
    >
      <DialogContent data-testid="theme-convert" className="sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{t('theme.convert.title', 'Import from another tool')}</DialogTitle>
          <DialogDescription>
            {t('theme.convert.description', 'Bring the colours, corners and font of a theme made with shadcn, Tailwind or Material Theme Builder.')}
          </DialogDescription>
        </DialogHeader>
        {result === null ? (
          <div className="grid gap-4">
            <div className="grid gap-1.5">
              <span className="text-sm font-medium" id="theme-convert-format">
                {t('theme.convert.format', 'Made with')}
              </span>
              <Select value={format} onValueChange={(value) => setFormat(value as ConvertFormat)}>
                <SelectTrigger aria-labelledby="theme-convert-format" data-testid="theme-convert-format">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {(Object.keys(formats) as ConvertFormat[]).map((key) => (
                    <SelectItem key={key} value={key}>
                      {formats[key].label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <Input
              label={t('theme.convert.name', 'Name')}
              value={name}
              maxLength={80}
              placeholder={t('theme.convert.namePlaceholder', 'Imported from {tool}', { tool: formats[format].label })}
              onChange={(event) => setName(event.target.value)}
            />
            <div className="grid gap-1.5">
              <label className="text-sm font-medium" htmlFor="theme-convert-source">
                {format === 'material' ? t('theme.convert.json', 'JSON') : t('theme.convert.css', 'CSS')}
              </label>
              <Textarea
                id="theme-convert-source"
                value={source}
                rows={8}
                dir="ltr"
                spellCheck={false}
                className="font-mono"
                aria-describedby="theme-convert-hint"
                aria-invalid={problem !== null || undefined}
                onChange={(event) => {
                  setSource(event.target.value);
                  setProblem(null);
                }}
              />
              <p id="theme-convert-hint" className="text-xs text-muted-foreground">
                {formats[format].hint}
              </p>
              {problem && (
                <p role="alert" className="text-xs text-destructive">
                  {problem}
                </p>
              )}
            </div>
            <input
              ref={file}
              type="file"
              accept={formats[format].accept}
              className="sr-only"
              aria-label={t('theme.convert.file', 'Choose a file')}
              onChange={(event) => {
                const chosen = event.target.files?.[0];
                if (chosen) void chosen.text().then((text) => setSource(text));
                event.target.value = '';
              }}
            />
          </div>
        ) : (
          <div className="grid gap-3" data-testid="theme-convert-result">
            <ThemePreview preview={result.preview} dark={dark} />
            <ul className="grid list-disc gap-1 ps-5 text-sm">
              {result.notes.map((note) => (
                <li key={note}>{note}</li>
              ))}
            </ul>
          </div>
        )}
        <DialogFooter>
          {result === null ? (
            <>
              <Button variant="outline" disabled={working} onClick={() => file.current?.click()}>
                <IconUpload aria-hidden="true" />
                {t('theme.convert.file', 'Choose a file')}
              </Button>
              <Button disabled={working || source.trim() === ''} onClick={() => void convert()}>
                {t('theme.convert.go', 'Make the theme')}
              </Button>
            </>
          ) : (
            <>
              <Button variant="outline" disabled={working} onClick={reset}>
                {t('theme.convert.back', 'Back')}
              </Button>
              <Button disabled={working} onClick={() => void add()}>
                {t('theme.convert.add', 'Add this theme')}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
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

  /** TH.25: the theme in use as resolved tokens for another tool. */
  const exportTokens = async (format: TokenFormat) => {
    setBusy('export');
    try {
      const { data: body, error: exportError } = await api.GET('/api/v1/themes/export/tokens', { params: { query: { format } } });
      if (exportError || !body) throw new Error('export');
      saveFile(body.data.content, body.data.filename, body.data.media_type);
    } catch {
      addToast(t('theme.export.failed', 'The theme could not be exported. Try again.'), 'error');
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
      saveFile(`${JSON.stringify(theme, null, 2)}\n`, filename, 'application/json');
    } catch {
      addToast(t('theme.export.failed', 'The theme could not be exported. Try again.'), 'error');
    } finally {
      setBusy(null);
    }
  };

  const saveImport = async (theme: unknown): Promise<boolean> => {
    const { error: importError } = await api.POST('/api/v1/themes', { body: theme as never });
    if (importError) {
      const reason = (importError as { errors?: { theme?: string } }).errors?.theme;
      addToast(reason ?? t('theme.import.failed', 'This theme file can’t be used.'), 'error');
      return false;
    }
    refetch();
    void refreshAppBranding();
    addToast(t('theme.import.done', 'Theme added. Choose it below to use it.'), 'success');
    return true;
  };

  /** Checks first, and says what a newer version changes before it replaces the old one (TH.16). */
  const importTheme = async (theme: unknown): Promise<boolean> => {
    const { data: checked, error: checkError } = await api.POST('/api/v1/themes', {
      params: { query: { check: '1' } },
      body: theme as never,
    });
    if (checkError || !checked) {
      const reason = (checkError as { errors?: { theme?: string } } | undefined)?.errors?.theme;
      addToast(reason ?? t('theme.import.failed', 'This theme file can’t be used.'), 'error');
      return false;
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
      return true;
    }
    return saveImport(theme);
  };
  const [converting, setConverting] = useState(false);

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
      await importTheme(theme);
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
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" disabled={busy !== null || !data}>
                <IconDownload aria-hidden="true" />
                {t('theme.export', 'Export theme')}
                <IconChevronDown aria-hidden="true" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={() => void exportTheme()}>{t('theme.export.file', 'Theme file, for Whity')}</DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuLabel>{t('theme.export.forOther', 'For another tool')}</DropdownMenuLabel>
              {TOKEN_FORMATS.map((format) => (
                <DropdownMenuItem key={format} onSelect={() => void exportTokens(format)}>
                  {tokenFormatName(format, t)}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
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
            <Button variant="outline" disabled={busy !== null} onClick={() => setConverting(true)}>
              <IconFileImport aria-hidden="true" />
              {t('theme.convert.open', 'Import from another tool')}
            </Button>
            <ConvertThemeDialog open={converting} onOpenChange={setConverting} onAdd={importTheme} dark={resolved === 'dark'} />
            <Button variant="outline" asChild>
              <Link href="/admin/settings/branding/theme">
                <IconPalette aria-hidden="true" />
                {t('theme.make', 'Make a theme')}
              </Link>
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
                <ThemePreview preview={item.preview} dark={resolved === 'dark'} />
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
                  {item.source === 'editor' && (
                    <p className="text-xs text-muted-foreground">{t('theme.made', 'Made in this workspace')}</p>
                  )}
                </div>
                {canWrite && (
                  <div className="flex flex-wrap gap-2">
                    {!item.current && (
                      <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => void apply(item.id)}>
                        {t('theme.use', 'Use this theme')}
                      </Button>
                    )}
                    {scope === 'tenant' && (
                      <Button size="sm" variant="outline" asChild>
                        <Link href={`/admin/settings/branding/theme?from=${encodeURIComponent(item.id)}`}>
                          {OWN.includes(item.source)
                            ? t('theme.edit', 'Edit')
                            : t('theme.copy', 'Make a copy')}
                        </Link>
                      </Button>
                    )}
                    {OWN.includes(item.source) && scope === 'tenant' && (
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
                            <span style={key === 'font' ? { fontFamily: fontStack(options.font.find((f) => f.id === value)?.stack) } : undefined}>
                              {optionName(key, value, t, options.font)}
                            </span>
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                );
              })}
            </div>
            {(() => {
              // TH.20: the chosen pair, one English and one Arabic sentence at title and body size.
              const font = options.font.find((f) => f.id === tweaks.font);
              const body = fontStack(font?.stack);
              if (!font || !body) return null;
              const heading = fontStack(font.heading_stack) ?? body;
              return (
                <div className="grid gap-2 rounded-control bg-muted p-3" data-testid="theme-font-preview">
                  <p className="text-lg font-semibold" style={{ fontFamily: heading }} lang="en" dir="ltr">
                    Example Bakery opens at 8:00 on Sunday.
                  </p>
                  <p className="text-sm" style={{ fontFamily: body }} lang="en" dir="ltr">
                    Book a table, order a cake, or ask Ahmad Khalil at the front desk.
                  </p>
                  <p className="text-lg font-semibold" style={{ fontFamily: heading }} lang="ar" dir="rtl">
                    يفتح مخبز المثال الساعة 8:00 يوم الأحد.
                  </p>
                  <p className="text-sm" style={{ fontFamily: body }} lang="ar" dir="rtl">
                    احجز طاولة، أو اطلب كعكة، أو اسأل أحمد خليل في مكتب الاستقبال.
                  </p>
                </div>
              );
            })()}
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
          {t('theme.personal', 'Everyone can still choose Larger text, Higher contrast and Calm animations for themselves.')}
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
