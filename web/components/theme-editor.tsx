'use client';

/**
 * The live theme editor (TH.25, 30-themes 11): every choice of section 3 with
 * a preview that re-themes as you go, light and dark side by side. Free for
 * every workspace since the owner put the theme system "All into core".
 *
 * It edits a theme file, never CSS. Each change is compiled by the server
 * (POST /themes/preview, the same compiler and guard rails a theme gets when
 * it's used) and the preview is two elements carrying the draft's values
 * (`previewThemeCss`), so the page around it keeps the workspace's own theme.
 * Saving goes through the theme import (`{theme, source: "editor"}`): the
 * workspace's own theme gets a new version, anything else is saved as a copy.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { IconArrowLeft, IconX } from '@tabler/icons-react';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Checkbox } from '@whity/ui/checkbox';
import { Input } from '@whity/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { Switch } from '@whity/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@whity/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useTranslation, type TranslateFn } from '@whity/features/i18n';
import { api } from '@/lib/api/client';
import { previewThemeCss } from '@/lib/brand-theme-css';
import { useRefreshBranding } from '@/lib/branding-context';
import { useToast } from '@/lib/toast-context';
import { adjustmentText, roleName, type FontOption } from '@/components/theme-picker';

/** A theme file as JSON (schemas/theme/v1.json); the server checks it. */
export type ThemeFile = Record<string, unknown>;

export interface ThemePreviewData {
  valid: boolean;
  problems: string[];
  light?: Record<string, string>;
  dark?: Record<string, string>;
  vars?: Record<string, string>;
  fonts?: Record<string, string>;
  adjustments?: { mode: string; role: string; change: string }[];
  roles?: string[];
}

const HEX = /^#[0-9a-f]{6}$/i;
const FAMILIES = ['buttons', 'fields', 'chips', 'cards', 'dialogs', 'tables', 'navigation'] as const;

/** The value at a path, or undefined. */
export function valueAt(theme: ThemeFile, path: string[]): unknown {
  let value: unknown = theme;
  for (const key of path) {
    if (typeof value !== 'object' || value === null) return undefined;
    value = (value as Record<string, unknown>)[key];
  }
  return value;
}

/** A copy with the value at a path set, or removed when undefined; empty groups go too. */
export function withValue(theme: ThemeFile, path: string[], value: unknown): ThemeFile {
  const [key, ...rest] = path;
  const out: ThemeFile = { ...theme };
  if (rest.length === 0) {
    if (value === undefined) delete out[key];
    else out[key] = value;
    return out;
  }
  const child = typeof theme[key] === 'object' && theme[key] !== null ? (theme[key] as ThemeFile) : {};
  const next = withValue(child, rest, value);
  if (Object.keys(next).length === 0) delete out[key];
  else out[key] = next;
  return out;
}

/** 1.4.2 → 1.4.3; anything else starts at 1.0.0. */
export function nextVersion(version: unknown): string {
  const m = typeof version === 'string' ? /^(\d+)\.(\d+)\.(\d+)$/.exec(version) : null;
  return m ? `${m[1]}.${m[2]}.${Number(m[3]) + 1}` : '1.0.0';
}

/**
 * What the editor saves: the workspace's own theme as its next version, any
 * other as a copy under `workspace.<name>`, never a `whity.` id.
 */
export function themeToSave(theme: ThemeFile, editable: boolean): ThemeFile {
  const name = (valueAt(theme, ['name', 'en']) as string | undefined) ?? '';
  if (editable && typeof theme.id === 'string' && !theme.id.startsWith('whity.')) {
    return { ...theme, version: nextVersion(theme.version) };
  }
  const slug =
    name
      .normalize('NFKD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 60) || 'theme';
  const copy: ThemeFile = { ...theme, id: `workspace.${slug}`, version: '1.0.0' };
  delete copy.author;
  return copy;
}

interface Option {
  value: string;
  label: string;
}

/** One closed choice; "Default" removes the key so the theme follows Whity's default. */
function Choice({
  id,
  label,
  value,
  options,
  fallback,
  onChange,
  t,
}: {
  id: string;
  label: string;
  value: unknown;
  options: Option[];
  fallback?: string;
  onChange: (value: string | undefined) => void;
  t: TranslateFn;
}) {
  const current = value === undefined || value === null ? '' : String(value);
  const fallbackLabel = options.find((o) => o.value === fallback)?.label;
  return (
    <div className="grid gap-1.5">
      <span className="text-sm font-medium" id={`${id}-label`}>
        {label}
      </span>
      <Select value={current === '' ? '__default' : current} onValueChange={(next) => onChange(next === '__default' ? undefined : next)}>
        <SelectTrigger aria-labelledby={`${id}-label`} data-testid={`theme-editor-${id}`}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="__default">
            {fallbackLabel ? t('theme.editor.default', 'Default ({value})', { value: fallbackLabel }) : t('theme.editor.defaultPlain', 'Default')}
          </SelectItem>
          {options.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

/** A seed colour: picker and hex, or none. */
function ColourField({
  id,
  label,
  hint,
  value,
  onChange,
  t,
}: {
  id: string;
  label: string;
  hint?: string;
  value: unknown;
  onChange: (value: string | undefined) => void;
  t: TranslateFn;
}) {
  const hex = typeof value === 'string' && HEX.test(value) ? value.toLowerCase() : '';
  const [text, setText] = useState(hex);
  // When the colour changes from outside (the picker, Clear), the text follows;
  // adjusting state while rendering, as React recommends, not in an effect.
  const [shown, setShown] = useState(hex);
  if (hex !== shown) {
    setShown(hex);
    setText(hex);
  }
  return (
    <div className="grid gap-1.5">
      <label className="text-sm font-medium" htmlFor={`${id}-hex`}>
        {label}
      </label>
      <div className="flex items-center gap-2">
        <input
          type="color"
          aria-label={label}
          value={hex || '#0961bb'}
          onChange={(event) => onChange(event.target.value.toLowerCase())}
          className="h-10 w-12 shrink-0 cursor-pointer rounded-control border border-input bg-card p-1"
        />
        <Input
          id={`${id}-hex`}
          value={text}
          dir="ltr"
          placeholder={t('theme.editor.colourNone', 'None')}
          className="font-mono"
          onChange={(event) => {
            const next = event.target.value.trim();
            setText(next);
            if (HEX.test(next)) onChange(next.toLowerCase());
          }}
        />
        {hex !== '' && (
          <Button variant="ghost" size="icon" aria-label={t('theme.editor.clear', 'Clear {what}', { what: label })} onClick={() => onChange(undefined)}>
            <IconX aria-hidden="true" />
          </Button>
        )}
      </div>
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

/** What a screen looks like in the draft: the real components, so every shape family shows. */
function ThemeSample({ mode, t }: { mode: 'light' | 'dark'; t: TranslateFn }) {
  const people = ['Amro Saleh', 'Awss Haddad', 'Ahmad Khalil'];
  return (
    <div
      data-theme-preview={mode}
      data-testid={`theme-editor-preview-${mode}`}
      // A picture of the theme, not part of the page: nothing in it can be used.
      inert
      className={`${mode === 'dark' ? 'dark ' : ''}grid gap-3 rounded-card border border-border bg-background p-3 font-sans text-foreground`}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="text-base font-semibold">Example Bakery</span>
        <Badge variant="success">{t('theme.editor.sample.paid', 'Paid')}</Badge>
      </div>
      <Tabs defaultValue="orders">
        <TabsList>
          <TabsTrigger value="orders">{t('theme.editor.sample.orders', 'Orders')}</TabsTrigger>
          <TabsTrigger value="customers">{t('theme.editor.sample.customers', 'Customers')}</TabsTrigger>
        </TabsList>
      </Tabs>
      <Card>
        <CardHeader>
          <CardTitle>{t('theme.editor.sample.order', 'New order')}</CardTitle>
          <CardDescription>{t('theme.editor.sample.orderHint', 'Fill in the order and save it.')}</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3">
          <Input label={t('theme.editor.sample.customer', 'Customer')} defaultValue="Example Bakery" />
          <div className="flex items-center gap-2 text-sm">
            <Checkbox defaultChecked aria-label={t('theme.editor.sample.deliver', 'Deliver')} />
            {t('theme.editor.sample.deliver', 'Deliver')}
            <Switch defaultChecked className="ms-auto" aria-label={t('theme.editor.sample.remind', 'Remind me')} />
          </div>
          <div className="flex flex-wrap gap-2">
            <Button>{t('theme.editor.sample.save', 'Save order')}</Button>
            <Button variant="outline">{t('theme.editor.sample.cancel', 'Cancel')}</Button>
          </div>
        </CardContent>
      </Card>
      <div data-slot="table-container" className="overflow-hidden rounded-card border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('theme.editor.sample.name', 'Name')}</TableHead>
              <TableHead>{t('theme.editor.sample.status', 'Status')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {people.map((person, i) => (
              <TableRow key={person}>
                <TableCell>{person}</TableCell>
                <TableCell>
                  <Badge variant={i === 0 ? 'default' : 'outline'}>
                    {i === 0 ? t('theme.editor.sample.new', 'New') : t('theme.editor.sample.done', 'Done')}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <div className="flex h-10 items-end gap-1.5" aria-hidden="true">
        {[1, 2, 3, 4, 5].map((n) => (
          <span key={n} className="flex-1 rounded-sm" style={{ height: `${30 + n * 12}%`, background: `var(--chart-${n})` }} />
        ))}
      </div>
    </div>
  );
}

interface ThemeEditorProps {
  /** The theme to start from: a file as installed. */
  start: ThemeFile;
  /** The workspace's own theme (saved as its next version), or another one (saved as a copy). */
  editable: boolean;
  fonts: FontOption[];
}

export function ThemeEditor({ start, editable, fonts }: ThemeEditorProps) {
  const t = useTranslation('admin');
  const router = useRouter();
  const { addToast } = useToast();
  const refreshAppBranding = useRefreshBranding();
  const [theme, setTheme] = useState<ThemeFile>(() => {
    if (editable) return start;
    // A copy is the workspace's own: it gets its own name to start with.
    const name = (valueAt(start, ['name', 'en']) as string | undefined) ?? 'Theme';
    return withValue(withValue(start, ['name', 'en'], t('theme.editor.copyOf', '{name} (copy)', { name })), ['name', 'ar'], undefined);
  });
  const [preview, setPreview] = useState<ThemePreviewData | null>(null);
  const [saving, setSaving] = useState(false);
  const [newRole, setNewRole] = useState({ role: '', mode: 'light', colour: '#0961bb' });
  const sequence = useRef(0);

  const set = (path: string[], value: unknown) => setTheme((current) => withValue(current, path, value));

  // Compile the draft a moment after the last change; an older answer never replaces a newer one.
  useEffect(() => {
    const ticket = ++sequence.current;
    const timer = setTimeout(async () => {
      const { data: body } = await api.POST('/api/v1/themes/preview', { body: theme as never });
      if (body && ticket === sequence.current) setPreview(body.data as ThemePreviewData);
    }, 250);
    return () => clearTimeout(timer);
  }, [theme]);

  const css = useMemo(() => (preview?.valid ? previewThemeCss(preview) : ''), [preview]);
  const problems = preview && !preview.valid ? preview.problems : [];
  const nameEn = (valueAt(theme, ['name', 'en']) as string | undefined) ?? '';

  const options = {
    scheme: [
      { value: 'calm', label: t('theme.editor.scheme.calm', 'Calm') },
      { value: 'tonal', label: t('theme.editor.scheme.tonal', 'Tonal') },
      { value: 'vibrant', label: t('theme.editor.scheme.vibrant', 'Vibrant') },
      { value: 'expressive', label: t('theme.editor.scheme.expressive', 'Expressive') },
      { value: 'monochrome', label: t('theme.editor.scheme.monochrome', 'Monochrome') },
    ],
    neutral: [
      { value: 'pure', label: t('theme.editor.neutral.pure', 'Pure grey') },
      { value: 'warm', label: t('theme.editor.neutral.warm', 'Warm') },
      { value: 'cool', label: t('theme.editor.neutral.cool', 'Cool') },
      { value: 'tinted', label: t('theme.editor.neutral.tinted', 'Tinted with the main colour') },
    ],
    contrast: [
      { value: 'standard', label: t('theme.editor.contrast.standard', 'Standard') },
      { value: 'high', label: t('theme.editor.contrast.high', 'Higher') },
    ],
    font: fonts.map((f) => ({ value: f.id, label: f.name })),
    scale: [
      { value: 'standard', label: t('theme.editor.scale.standard', 'Standard') },
      { value: 'roomy', label: t('theme.editor.scale.roomy', 'Roomy') },
      { value: 'large', label: t('theme.editor.scale.large', 'Large') },
    ],
    titleWeight: [
      { value: '500', label: t('theme.editor.titleWeight.medium', 'Medium') },
      { value: '600', label: t('theme.editor.titleWeight.semibold', 'Semibold') },
    ],
    emphasis: [
      { value: 'quiet', label: t('theme.editor.emphasis.quiet', 'Quiet') },
      { value: 'expressive', label: t('theme.editor.emphasis.expressive', 'Expressive') },
    ],
    shape: [
      { value: 'square', label: t('theme.tweak.shape.square', 'Square') },
      { value: 'crisp', label: t('theme.tweak.shape.crisp', 'Crisp') },
      { value: 'whity', label: t('theme.tweak.shape.whity', 'Whity') },
      { value: 'soft', label: t('theme.tweak.shape.soft', 'Soft') },
      { value: 'round', label: t('theme.tweak.shape.round', 'Round') },
      { value: 'pebble', label: t('theme.tweak.shape.pebble', 'Pebble') },
      { value: 'cut', label: t('theme.tweak.shape.cut', 'Cut') },
    ],
    morph: [
      { value: 'on', label: t('theme.editor.morph.on', 'On') },
      { value: 'off', label: t('theme.editor.morph.off', 'Off') },
    ],
    density: [
      { value: 'compact', label: t('theme.tweak.density.compact', 'Compact') },
      { value: 'standard', label: t('theme.tweak.density.standard', 'Standard') },
      { value: 'comfortable', label: t('theme.tweak.density.comfortable', 'Comfortable') },
    ],
    elevation: [
      { value: 'plane', label: t('theme.editor.elevation.plane', 'Whity’s plane') },
      { value: 'soft', label: t('theme.editor.elevation.soft', 'Soft shadows') },
      { value: 'flat', label: t('theme.editor.elevation.flat', 'Flat') },
      { value: 'outline', label: t('theme.editor.elevation.outline', 'Outlines') },
    ],
    float: [
      { value: 'light', label: t('theme.editor.float.light', 'Light') },
      { value: 'standard', label: t('theme.editor.float.standard', 'Standard') },
      { value: 'strong', label: t('theme.editor.float.strong', 'Strong') },
    ],
    motion: [
      { value: 'calm', label: t('theme.editor.motion.calm', 'Calm') },
      { value: 'standard', label: t('theme.editor.motion.standard', 'Standard') },
      { value: 'expressive', label: t('theme.editor.motion.expressive', 'Expressive') },
      { value: 'brisk', label: t('theme.editor.motion.brisk', 'Brisk') },
    ],
  };
  const families: Record<(typeof FAMILIES)[number], string> = {
    buttons: t('theme.change.corners.buttons', 'Corners of buttons'),
    fields: t('theme.change.corners.fields', 'Corners of fields'),
    chips: t('theme.change.corners.chips', 'Corners of chips and tags'),
    cards: t('theme.change.corners.cards', 'Corners of cards'),
    dialogs: t('theme.change.corners.dialogs', 'Corners of dialogs and menus'),
    tables: t('theme.change.corners.tables', 'Corners of tables'),
    navigation: t('theme.change.corners.navigation', 'Corners of tabs and the sidebar'),
  };

  const overrides = (mode: 'light' | 'dark') => (valueAt(theme, ['colour', 'overrides', mode]) as Record<string, string> | undefined) ?? {};

  const save = async (use: boolean) => {
    setSaving(true);
    try {
      const file = themeToSave(theme, editable);
      const { error: saveError } = await api.POST('/api/v1/themes', { body: { theme: file, source: 'editor' } as never });
      if (saveError) {
        const reason = (saveError as { errors?: { theme?: string } }).errors?.theme;
        addToast(reason ?? t('theme.editor.saveFailed', 'The theme could not be saved. Try again.'), 'error');
        return;
      }
      if (use) {
        const { error: useError } = await api.PATCH('/api/v1/settings', { body: { settings: { branding_theme: file.id as string } } });
        if (useError) {
          addToast(t('theme.applyFailed', 'The theme could not be applied. Try again.'), 'error');
          return;
        }
        void refreshAppBranding();
      }
      addToast(use ? t('theme.editor.savedUsed', 'Theme saved and in use.') : t('theme.editor.saved', 'Theme saved. Choose it in Branding to use it.'), 'success');
      router.push('/admin/settings/branding');
    } finally {
      setSaving(false);
    }
  };

  const section = (title: string, children: React.ReactNode) => (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-4">{children}</CardContent>
    </Card>
  );

  return (
    <div className="grid gap-6" data-testid="theme-editor">
      {css !== '' && <style>{css}</style>}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="grid gap-1">
          <Link href="/admin/settings/branding" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <IconArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
            {t('theme.editor.back', 'Branding')}
          </Link>
          <h1 className="text-2xl font-semibold">{editable ? t('theme.editor.titleEdit', 'Edit theme') : t('theme.editor.title', 'Make a theme')}</h1>
          <p className="text-sm text-muted-foreground">
            {editable
              ? t('theme.editor.descriptionEdit', 'Saving makes a new version. Workspaces using it see the change.')
              : t('theme.editor.description', 'Start from this theme and make it your own. Saving keeps it as a new theme in this workspace.')}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" disabled={saving || !preview?.valid || nameEn.trim() === ''} onClick={() => void save(false)}>
            {t('theme.editor.save', 'Save')}
          </Button>
          <Button disabled={saving || !preview?.valid || nameEn.trim() === ''} onClick={() => void save(true)}>
            {t('theme.editor.saveUse', 'Save and use')}
          </Button>
        </div>
      </div>

      {problems.length > 0 && (
        <div role="alert" className="rounded-card border border-destructive/40 bg-error-tint p-3 text-sm text-error-text" data-testid="theme-editor-problems">
          <p className="font-medium">{t('theme.editor.problems', 'Fix these before saving:')}</p>
          <ul className="mt-1 list-disc ps-5">
            {problems.map((problem) => (
              <li key={problem} dir="ltr" className="text-start">
                {problem}
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <div className="grid gap-4">
          {section(
            t('theme.editor.section.name', 'Name'),
            <>
              <Input
                label={t('theme.editor.nameEn', 'Name in English')}
                required
                maxLength={200}
                value={nameEn}
                onChange={(event) => set(['name', 'en'], event.target.value)}
              />
              <Input
                label={t('theme.editor.nameAr', 'Name in Arabic')}
                dir="rtl"
                maxLength={200}
                value={(valueAt(theme, ['name', 'ar']) as string | undefined) ?? ''}
                onChange={(event) => set(['name', 'ar'], event.target.value === '' ? undefined : event.target.value)}
              />
            </>,
          )}
          {section(
            t('theme.editor.section.colour', 'Colour'),
            <>
              <ColourField
                id="primary"
                label={t('theme.change.primary', 'Main colour')}
                hint={t('theme.editor.primaryHint', 'Buttons, links and the focus outline are made from it.')}
                value={valueAt(theme, ['colour', 'seeds', 'primary'])}
                onChange={(v) => set(['colour', 'seeds', 'primary'], v)}
                t={t}
              />
              <ColourField id="secondary" label={t('theme.change.secondary', 'Second colour')} value={valueAt(theme, ['colour', 'seeds', 'secondary'])} onChange={(v) => set(['colour', 'seeds', 'secondary'], v)} t={t} />
              <ColourField id="tertiary" label={t('theme.change.tertiary', 'Accent colour')} value={valueAt(theme, ['colour', 'seeds', 'tertiary'])} onChange={(v) => set(['colour', 'seeds', 'tertiary'], v)} t={t} />
              <Choice id="scheme" label={t('theme.change.scheme', 'Colour style')} value={valueAt(theme, ['colour', 'scheme'])} options={options.scheme} fallback="calm" onChange={(v) => set(['colour', 'scheme'], v)} t={t} />
              <Choice id="neutral" label={t('theme.change.neutral', 'Greys')} value={valueAt(theme, ['colour', 'neutral'])} options={options.neutral} onChange={(v) => set(['colour', 'neutral'], v)} t={t} />
              <Choice id="contrast" label={t('theme.change.contrast', 'Contrast')} value={valueAt(theme, ['colour', 'contrast'])} options={options.contrast} fallback="standard" onChange={(v) => set(['colour', 'contrast'], v)} t={t} />
              <div className="grid gap-2" data-testid="theme-editor-overrides">
                <span className="text-sm font-medium">{t('theme.change.overrides', 'Colours set by hand')}</span>
                {(['light', 'dark'] as const).flatMap((mode) =>
                  Object.entries(overrides(mode)).map(([role, hex]) => (
                    <div key={`${mode}-${role}`} className="flex items-center gap-2 text-sm">
                      <span className="size-5 shrink-0 rounded-sm border border-border" style={{ background: HEX.test(hex) ? hex : undefined }} aria-hidden="true" />
                      <span className="min-w-0 flex-1 truncate">
                        {roleName(role, t)} · {mode === 'light' ? t('theme.editor.light', 'Light') : t('theme.editor.dark', 'Dark')}
                      </span>
                      <span className="font-mono text-xs text-muted-foreground" dir="ltr">
                        {hex}
                      </span>
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('theme.editor.removeOverride', 'Remove {role}', { role: roleName(role, t) })}
                        onClick={() => set(['colour', 'overrides', mode, role], undefined)}
                      >
                        <IconX aria-hidden="true" />
                      </Button>
                    </div>
                  )),
                )}
                {(preview?.roles ?? []).length > 0 && (
                  <div className="grid grid-cols-[minmax(0,1fr)_auto_auto] items-end gap-2">
                    <Select value={newRole.role} onValueChange={(role) => setNewRole((r) => ({ ...r, role }))}>
                      <SelectTrigger aria-label={t('theme.editor.role', 'Which colour')}>
                        <SelectValue placeholder={t('theme.editor.role', 'Which colour')} />
                      </SelectTrigger>
                      <SelectContent>
                        {(preview?.roles ?? []).map((role) => (
                          <SelectItem key={role} value={role}>
                            {roleName(role, t)} ({role})
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <Select value={newRole.mode} onValueChange={(mode) => setNewRole((r) => ({ ...r, mode }))}>
                      <SelectTrigger aria-label={t('theme.editor.mode', 'Light or dark')}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="light">{t('theme.editor.light', 'Light')}</SelectItem>
                        <SelectItem value="dark">{t('theme.editor.dark', 'Dark')}</SelectItem>
                      </SelectContent>
                    </Select>
                    <input
                      type="color"
                      aria-label={t('theme.editor.overrideColour', 'Its colour')}
                      value={newRole.colour}
                      onChange={(event) => setNewRole((r) => ({ ...r, colour: event.target.value.toLowerCase() }))}
                      className="h-10 w-12 cursor-pointer rounded-control border border-input bg-card p-1"
                    />
                    <Button
                      variant="outline"
                      className="col-span-3"
                      disabled={newRole.role === ''}
                      onClick={() => set(['colour', 'overrides', newRole.mode, newRole.role], newRole.colour)}
                    >
                      {t('theme.editor.addOverride', 'Set this colour by hand')}
                    </Button>
                  </div>
                )}
              </div>
            </>,
          )}
          {section(
            t('theme.editor.section.type', 'Type'),
            <>
              <Choice id="family" label={t('theme.change.font', 'Text font')} value={valueAt(theme, ['type', 'family'])} options={options.font} fallback="noto" onChange={(v) => set(['type', 'family'], v)} t={t} />
              <Choice
                id="headingFamily"
                label={t('theme.change.headingFont', 'Heading font')}
                value={valueAt(theme, ['type', 'headingFamily'])}
                options={[{ value: 'same', label: t('theme.editor.sameFont', 'Same as the text') }, ...options.font]}
                fallback="same"
                onChange={(v) => set(['type', 'headingFamily'], v)}
                t={t}
              />
              <Choice id="scale" label={t('theme.change.scale', 'Text size')} value={valueAt(theme, ['type', 'scale'])} options={options.scale} fallback="standard" onChange={(v) => set(['type', 'scale'], v)} t={t} />
              <Choice
                id="titleWeight"
                label={t('theme.change.titleWeight', 'Title weight')}
                value={valueAt(theme, ['type', 'titleWeight'])}
                options={options.titleWeight}
                fallback="600"
                onChange={(v) => set(['type', 'titleWeight'], v === undefined ? undefined : Number(v))}
                t={t}
              />
              <Choice id="emphasis" label={t('theme.editor.emphasis', 'Emphasis')} value={valueAt(theme, ['type', 'emphasis'])} options={options.emphasis} fallback="quiet" onChange={(v) => set(['type', 'emphasis'], v)} t={t} />
            </>,
          )}
          {section(
            t('theme.editor.section.shape', 'Corners and spacing'),
            <>
              <Choice id="shape" label={t('theme.change.shape', 'Corners')} value={valueAt(theme, ['shape', 'preset'])} options={options.shape} fallback="whity" onChange={(v) => set(['shape', 'preset'], v)} t={t} />
              <Choice id="morph" label={t('theme.change.morph', 'Shape change on press')} value={valueAt(theme, ['shape', 'morph'])} options={options.morph} onChange={(v) => set(['shape', 'morph'], v)} t={t} />
              <details className="grid gap-3">
                <summary className="cursor-pointer text-sm font-medium">{t('theme.editor.families', 'Corners for each kind of component')}</summary>
                <div className="mt-3 grid gap-3">
                  {FAMILIES.map((family) => (
                    <Choice
                      key={family}
                      id={`family-${family}`}
                      label={families[family]}
                      value={valueAt(theme, ['shape', 'components', family])}
                      options={options.shape}
                      onChange={(v) => set(['shape', 'components', family], v)}
                      t={t}
                    />
                  ))}
                </div>
              </details>
              <Choice id="density" label={t('theme.change.density', 'Spacing')} value={valueAt(theme, ['density'])} options={options.density} fallback="standard" onChange={(v) => set(['density'], v)} t={t} />
            </>,
          )}
          {section(
            t('theme.editor.section.depth', 'Shadows and motion'),
            <>
              <Choice id="elevation" label={t('theme.change.elevation', 'Shadows and borders')} value={valueAt(theme, ['elevation', 'style'])} options={options.elevation} fallback="plane" onChange={(v) => set(['elevation', 'style'], v)} t={t} />
              <Choice id="float" label={t('theme.change.float', 'Shadow strength')} value={valueAt(theme, ['elevation', 'float'])} options={options.float} fallback="standard" onChange={(v) => set(['elevation', 'float'], v)} t={t} />
              <Choice id="motion" label={t('theme.change.motion', 'Animations')} value={valueAt(theme, ['motion', 'preset'])} options={options.motion} fallback="calm" onChange={(v) => set(['motion', 'preset'], v)} t={t} />
              <p className="text-xs text-muted-foreground">{t('theme.editor.motionHint', 'Each person’s own Animations setting still wins.')}</p>
            </>,
          )}
        </div>

        <div className="grid gap-3 lg:sticky lg:top-4" aria-live="polite">
          <div className="grid gap-3 xl:grid-cols-2">
            <div className="grid gap-1.5">
              <span className="text-sm font-medium">{t('theme.editor.light', 'Light')}</span>
              <ThemeSample mode="light" t={t} />
            </div>
            <div className="grid gap-1.5">
              <span className="text-sm font-medium">{t('theme.editor.dark', 'Dark')}</span>
              <ThemeSample mode="dark" t={t} />
            </div>
          </div>
          {preview?.valid && preview.fonts?.body && (
            <p className="text-xs text-muted-foreground">
              {preview.fonts.heading && preview.fonts.heading !== preview.fonts.body
                ? t('theme.fonts.two', 'Text in {body}, headings in {heading}', { body: preview.fonts.body, heading: preview.fonts.heading })
                : t('theme.fonts.one', 'Text in {body}', { body: preview.fonts.body })}
            </p>
          )}
          {preview?.valid && (preview.adjustments ?? []).length > 0 && (
            <div className="grid gap-1 text-xs text-muted-foreground" data-testid="theme-editor-adjustments">
              <p className="font-medium text-foreground">{t('theme.editor.adjusted', 'Changed so text stays readable:')}</p>
              <ul className="list-disc ps-5">
                {(preview.adjustments ?? []).map((a) => (
                  <li key={`${a.mode}-${a.role}-${a.change}`}>
                    {adjustmentText(a.change, a.role, t)} ({a.mode === 'dark' ? t('theme.editor.dark', 'Dark') : t('theme.editor.light', 'Light')})
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
