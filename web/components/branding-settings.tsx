'use client';

/**
 * Settings › Branding, built to its card (docs/design/components/TplAdminBranding;
 * 09-white-label): the page header with the one Save, a "Your brand" card
 * (product name, domain, main colour, logos and favicon, Built on Whity) and a
 * live preview beside it that re-themes as the colour is picked (decision 2.8).
 *
 * One form, two layers. A workspace edits its own branding; the system tenant,
 * which has no per-tenant layer, edits the platform-wide defaults every
 * workspace falls back to, and is the only one that can set the web address
 * (`PUT /tenants/{id}/branding-host` is system-tenant only, because the host
 * routes every request).
 *
 * The name and colour wait for Save branding. Logos and the favicon are files:
 * they upload the moment one is picked, and Clear removes one at once.
 *
 * Language moved to General (section 25: General holds the workspace's language).
 */

import { useRef, useState } from 'react';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { api } from '@/lib/api/client';
import {
  uploadBrandingAsset,
  clearBrandingAsset,
  setBrandingHost,
  type Branding,
} from '@/lib/api/branding-upload';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@whity/ui/card';
import { Switch } from '@whity/ui/switch';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { BuiltOnWhity } from '@whity/ui/built-on-whity';
import { Input } from '@/components/ui/input';
import { AdminHeader } from '@/components/admin/admin-header';
import { FirstVisitScene } from '@/components/first-visit-scene';
import { MainColourPicker, WHITY_BLUE } from '@/components/branding-main-colour';
import { useRefreshBranding } from '@/lib/branding-context';
import { useThemeMode } from '@/lib/theme-mode-context';
import { deriveTenantTheme, isHexColour, tenantThemeStyle } from '@/lib/tenant-theme';
import { useTranslation } from '@whity/features/i18n';
import { IconPhoto, IconSend, IconTrash, IconUpload } from '@tabler/icons-react';

type AssetKey = 'logo_wide' | 'logo_square' | 'favicon';
type Scope = 'tenant' | 'global';

interface AssetMeta {
  key: AssetKey;
  labelKey: string;
  label: string;
  accept: string;
  urlField: 'logoWideUrl' | 'logoSquareUrl' | 'faviconUrl';
  descriptionKey: string;
  description: string;
}

/**
 * The asset labels and descriptions reach `t()` through this table rather than
 * as literals at the call site, which no static scanner can read — so they are
 * declared here and the extractor takes the catalogue from this block. The
 * English stays on the record as the runtime fallback.
 *
 * @i18n-keys admin
 *   branding.asset.logoWide.label = Wide logo
 *   branding.asset.logoWide.description = Shown in the expanded sidebar. PNG, WebP or SVG, max 2 MB.
 *   branding.asset.logoSquare.label = Square logo
 *   branding.asset.logoSquare.description = Shown in the collapsed sidebar. PNG, WebP or SVG, max 2 MB.
 *   branding.asset.favicon.label = Favicon
 *   branding.asset.favicon.description = Browser tab icon. ICO or PNG, max 256 KB.
 */
export const ASSET_META: AssetMeta[] = [
  {
    key: 'logo_wide',
    labelKey: 'branding.asset.logoWide.label',
    label: 'Wide logo',
    accept: 'image/png,image/webp,image/svg+xml',
    urlField: 'logoWideUrl',
    descriptionKey: 'branding.asset.logoWide.description',
    description: 'Shown in the expanded sidebar. PNG, WebP or SVG, max 2 MB.',
  },
  {
    key: 'logo_square',
    labelKey: 'branding.asset.logoSquare.label',
    label: 'Square logo',
    accept: 'image/png,image/webp,image/svg+xml',
    urlField: 'logoSquareUrl',
    descriptionKey: 'branding.asset.logoSquare.description',
    description: 'Shown in the collapsed sidebar. PNG, WebP or SVG, max 2 MB.',
  },
  {
    key: 'favicon',
    labelKey: 'branding.asset.favicon.label',
    label: 'Favicon',
    accept: 'image/x-icon,image/png',
    urlField: 'faviconUrl',
    descriptionKey: 'branding.asset.favicon.description',
    description: 'Browser tab icon. ICO or PNG, max 256 KB.',
  },
];

function toErrorMessage(err: unknown, fallback: string): string {
  if (err instanceof Error && err.message) return err.message;
  return fallback;
}

function apiErrorText(error: unknown, fallback: string): string {
  return typeof error === 'object' && error !== null && 'error' in error && typeof error.error === 'string'
    ? error.error
    : fallback;
}

// ---------------------------------------------------------------------------
// One logo or the favicon: what it is, what is set now, Upload and Clear.
// ---------------------------------------------------------------------------

interface AssetRowProps {
  meta: AssetMeta;
  scope: Scope;
  currentUrl: string | null;
  canWrite: boolean;
  onChanged: (updated: Branding, message: string) => void;
  onError: (message: string) => void;
}

function AssetRow({ meta, scope, currentUrl, canWrite, onChanged, onError }: AssetRowProps) {
  const t = useTranslation('admin');
  const [busy, setBusy] = useState<'upload' | 'clear' | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const label = t(meta.labelKey, meta.label);

  const handleFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setBusy('upload');
    try {
      const updated = await uploadBrandingAsset(scope, meta.key, file);
      onChanged(updated, t('branding.upload.success', '{asset} uploaded successfully.', { asset: label }));
    } catch (err) {
      onError(toErrorMessage(err, t('branding.upload.error', 'Failed to upload {asset}', { asset: label.toLowerCase() })));
    } finally {
      setBusy(null);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  const handleClear = async () => {
    setBusy('clear');
    try {
      const updated = await clearBrandingAsset(scope, meta.key);
      onChanged(updated, t('branding.clear.success', '{asset} cleared.', { asset: label }));
    } catch (err) {
      onError(toErrorMessage(err, t('branding.clear.error', 'Failed to clear {asset}', { asset: label.toLowerCase() })));
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="flex items-start gap-4">
      <div className="min-w-0 flex-1 space-y-2">
        <div>
          <p className="text-xs font-semibold text-foreground">{label}</p>
          <p className="text-xs text-muted-foreground">{t(meta.descriptionKey, meta.description)}</p>
        </div>
        {canWrite && (
          <div className="flex flex-wrap items-center gap-2">
            <input
              ref={fileInputRef}
              type="file"
              accept={meta.accept}
              className="hidden"
              disabled={busy !== null}
              onChange={handleFile}
              data-testid={`branding-file-input-${meta.key}-${scope}`}
              aria-label={t('branding.upload.label', 'Upload {asset}', { asset: label })}
            />
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={busy !== null}
              onClick={() => fileInputRef.current?.click()}
              data-testid={`branding-upload-btn-${meta.key}-${scope}`}
            >
              <IconUpload className="size-4" aria-hidden />
              {busy === 'upload' ? t('branding.upload.pending', 'Uploading…') : t('branding.upload', 'Upload')}
            </Button>
            {currentUrl && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                disabled={busy !== null}
                onClick={handleClear}
                className="text-muted-foreground hover:text-destructive"
                data-testid={`branding-clear-btn-${meta.key}-${scope}`}
                aria-label={t('branding.clear.label', 'Clear {asset}', { asset: label })}
              >
                <IconTrash className="size-4" aria-hidden />
                {busy === 'clear' ? t('branding.clear.pending', 'Clearing…') : t('branding.clear', 'Clear')}
              </Button>
            )}
          </div>
        )}
      </div>
      {currentUrl ? (
        <img
          src={currentUrl}
          alt={t('branding.preview.alt', 'Current {asset}', { asset: label })}
          className="h-10 max-w-30 shrink-0 rounded-sm border border-border bg-muted object-contain p-1"
          data-testid={`branding-preview-${meta.key}-${scope}`}
        />
      ) : (
        <div
          className="flex h-10 w-16 shrink-0 items-center justify-center rounded-sm border border-dashed border-border bg-muted"
          role="img"
          aria-label={t('branding.preview.empty', 'No {asset} set', { asset: label })}
          data-testid={`branding-preview-${meta.key}-${scope}`}
        >
          <IconPhoto className="size-4 text-muted-foreground" aria-hidden />
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// The preview: a small screen in the chosen colour (decision 2.8).
// ---------------------------------------------------------------------------

function BrandPreview({
  name,
  colour,
  squareLogoUrl,
  builtOnWhity,
}: {
  name: string;
  colour: string | null;
  squareLogoUrl: string | null;
  builtOnWhity: boolean;
}) {
  const t = useTranslation('admin');
  const { resolved } = useThemeMode();
  const theme = deriveTenantTheme(colour && isHexColour(colour) ? colour : WHITY_BLUE);
  const style = tenantThemeStyle(resolved === 'dark' ? theme.dark : theme.light) as React.CSSProperties;
  const shownName = name.trim() || 'Whity';

  return (
    <section className="min-w-0 space-y-3 @2xl:sticky @2xl:top-4" aria-labelledby="branding-preview-title">
      <h2 id="branding-preview-title" className="text-base font-semibold text-foreground">
        {t('branding.preview.title', 'Preview')}
      </h2>
      {/* A picture of the result, not controls: nothing in it can be focused or pressed. */}
      <div
        inert
        style={style}
        className="space-y-3 rounded-card border border-border bg-card p-4"
        data-testid="branding-live-preview"
      >
        <div className="flex items-center gap-2">
          {squareLogoUrl ? (
            <img src={squareLogoUrl} alt="" className="size-6 rounded-sm object-contain" />
          ) : (
            <span
              aria-hidden
              className="flex size-6 items-center justify-center rounded-sm bg-primary text-xs font-semibold text-primary-foreground"
            >
              {Array.from(shownName)[0]?.toUpperCase()}
            </span>
          )}
          <span dir="auto" className="min-w-0 truncate font-semibold text-foreground" data-testid="branding-preview-name">
            {shownName}
          </span>
        </div>
        <Tabs defaultValue="quotes">
          <TabsList variant="line" className="w-full">
            <TabsTrigger value="quotes">{t('branding.preview.quotes', 'Quotes')}</TabsTrigger>
            <TabsTrigger value="invoices">{t('branding.preview.invoices', 'Invoices')}</TabsTrigger>
          </TabsList>
        </Tabs>
        <div className="flex flex-wrap items-center gap-3">
          <Button size="sm" tabIndex={-1}>
            <IconSend className="size-4" aria-hidden />
            {t('branding.preview.sendInvoice', 'Send invoice')}
          </Button>
          <Button size="sm" variant="link" tabIndex={-1}>
            {t('branding.preview.viewHistory', 'View history')}
          </Button>
        </div>
        {builtOnWhity && <BuiltOnWhity label={t('branding.builtOnWhity.label', 'Built on Whity')} />}
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// The page body
// ---------------------------------------------------------------------------

export interface BrandingSettingsProps {
  /**
   * 'tenant' for a workspace (its own branding); 'global' for the system
   * tenant, which edits the platform-wide defaults and the web address.
   */
  scope: Scope;
  canWrite: boolean;
}

export function BrandingSettings({ scope, canWrite }: BrandingSettingsProps) {
  const t = useTranslation('admin');
  const { addToast } = useToast();
  const refreshAppBranding = useRefreshBranding();

  const { data: fetched, loading, refetch } = useFetch(async () => {
    const { data, error } = await api.GET('/api/v1/branding');
    if (!data) throw new Error(apiErrorText(error, t('branding.loadFailed', 'Failed to load branding')));
    return data.data;
  }, []);

  // An upload or clear answers with the new branding: show it at once.
  const [afterAsset, setAfterAsset] = useState<Branding | null>(null);
  const branding = afterAsset ?? fetched;

  // Drafts: undefined means untouched, so the saved value shows.
  const [nameDraft, setNameDraft] = useState<string | undefined>(undefined);
  const [colourDraft, setColourDraft] = useState<string | null | undefined>(undefined);
  const [hostDraft, setHostDraft] = useState('');
  const [saving, setSaving] = useState(false);

  const savedName = branding?.siteName ?? '';
  const savedColour = branding?.mainColor?.toLowerCase() ?? null;
  const name = nameDraft ?? savedName;
  const colour = colourDraft === undefined ? savedColour : colourDraft;
  const nameChanged = nameDraft !== undefined && nameDraft.trim() !== savedName;
  const colourChanged = colourDraft !== undefined && colourDraft !== savedColour;
  const hostChanged = scope === 'global' && hostDraft.trim() !== '';
  const dirty = nameChanged || colourChanged || hostChanged;

  const handleSave = async () => {
    if (scope === 'global' && name.trim() === '') {
      addToast(t('branding.name.required', 'The product name cannot be empty.'), 'error');
      return;
    }
    setSaving(true);
    try {
      const settings: Record<string, string> = {};
      if (nameChanged) settings.site_name = name.trim();
      if (colourChanged) settings.branding_main_color = colour ?? '';
      if (Object.keys(settings).length > 0) {
        const { error } =
          scope === 'global'
            ? await api.PATCH('/api/v1/settings/global', { body: { settings } })
            : await api.PATCH('/api/v1/settings', { body: { settings } });
        if (error) throw new Error(apiErrorText(error, t('branding.saveFailed', 'Failed to save branding')));
      }
      if (hostChanged) {
        // The system tenant's own id is 0: the platform's address.
        await setBrandingHost(0, hostDraft.trim());
      }
      setNameDraft(undefined);
      setColourDraft(undefined);
      setHostDraft('');
      setAfterAsset(null);
      refetch();
      // The whole app wears the brand: refresh its copy (a router refresh can't, #1716).
      void refreshAppBranding();
      addToast(t('branding.saved', 'Branding saved.'), 'success');
    } catch (err) {
      addToast(toErrorMessage(err, t('branding.saveFailed', 'Failed to save branding')), 'error');
    } finally {
      setSaving(false);
    }
  };

  const assetChanged = (updated: Branding, message: string) => {
    setAfterAsset(updated);
    refetch();
    void refreshAppBranding();
    addToast(message, 'success');
  };
  const showError = (message: string) => addToast(message, 'error');

  const builtOnWhity = branding?.builtOnWhity !== false;
  const builtOnWhityHelp =
    scope === 'global'
      ? t('branding.builtOnWhity.platform', 'Always shown on the platform’s own pages.')
      : builtOnWhity
        ? t('branding.builtOnWhity.shown', 'Shown on your public pages. Hiding it comes with a paid plan.')
        : t('branding.builtOnWhity.hidden', 'Hidden: your plan includes removing it.');

  return (
    <>
      <AdminHeader
        title={t('settings.branding.title', 'Branding')}
        description={
          scope === 'global'
            ? t(
                'branding.description.platform',
                'The name, logo and colour every workspace starts with, until it sets its own.'
              )
            : t(
                'branding.description',
                'Your name, logo and colour, on every screen, email and document your team and customers see.'
              )
        }
        action={
          canWrite ? (
            <Button
              onClick={handleSave}
              disabled={saving || !dirty}
              loading={saving}
              icon="check"
              data-testid="branding-save"
            >
              {saving ? t('branding.saving', 'Saving…') : t('branding.save', 'Save branding')}
            </Button>
          ) : undefined
        }
      />

      {/* Your brand, the first visit only (LT.4): the workspace's sheet sets down on the platform. */}
      <FirstVisitScene name="brand-sheet-platform" storageKey="branding-first" />

      {/*
        Side by side when the page column has room, not the window: the
        settings sub-nav takes part of a desktop screen. Below that the
        preview follows the card.
      */}
      <div className="@container">
        <div className="grid items-start gap-6 @2xl:grid-cols-[minmax(0,1fr)_minmax(0,18rem)] @5xl:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
        <Card className="min-w-0">
          <CardHeader>
            <CardTitle>
              <h2 className="text-base font-semibold">
                {scope === 'global'
                  ? t('branding.card.platform', 'Platform brand')
                  : t('branding.card.title', 'Your brand')}
              </h2>
            </CardTitle>
            {!canWrite && (
              <p className="text-sm text-muted-foreground">
                {t('branding.readOnly', 'You can see the branding but not change it.')}
              </p>
            )}
          </CardHeader>
          <CardContent>
            {loading && !branding ? (
              <div className="space-y-4" aria-busy="true">
                <div className="h-16 animate-pulse rounded-control bg-muted" />
                <div className="h-16 animate-pulse rounded-control bg-muted" />
              </div>
            ) : (
              <div className="stack-divided-6">
                <div className="space-y-4">
                  <Input
                    id={`branding-name-${scope}`}
                    label={t('branding.name.label', 'Product name')}
                    value={name}
                    disabled={!canWrite}
                    onChange={(e) => setNameDraft(e.target.value)}
                    helperText={
                      scope === 'tenant'
                        ? t('branding.name.help', 'In the browser tab, the sidebar, sign-in and emails. Clear it to use the platform’s name.')
                        : undefined
                    }
                    data-testid={`branding-name-input-${scope}`}
                  />
                  {scope === 'global' ? (
                    <Input
                      id="branding-domain"
                      label={t('branding.host.label', 'Domain')}
                      placeholder="app.example.com"
                      dir="ltr"
                      value={hostDraft}
                      disabled={!canWrite}
                      onChange={(e) => setHostDraft(e.target.value)}
                      helperText={t('branding.host.help', 'Point a CNAME record at your Whity host.')}
                      data-testid="branding-custom-host-input"
                    />
                  ) : (
                    <p className="text-xs text-muted-foreground" data-testid="branding-domain-note">
                      {t(
                        'branding.host.tenantNote',
                        'Your own web address is set up by the person who runs this Whity.'
                      )}
                    </p>
                  )}
                </div>

                <MainColourPicker
                  scope={scope}
                  value={colour}
                  disabled={!canWrite || saving}
                  onChange={(value) => setColourDraft(value)}
                />

                {ASSET_META.map((meta) => (
                  <AssetRow
                    key={meta.key}
                    meta={meta}
                    scope={scope}
                    currentUrl={branding?.[meta.urlField] ?? null}
                    canWrite={canWrite}
                    onChanged={assetChanged}
                    onError={showError}
                  />
                ))}

                <div className="flex items-start gap-3">
                  <Switch
                    id={`branding-built-on-whity-${scope}`}
                    checked={builtOnWhity}
                    disabled
                    aria-describedby={`branding-built-on-whity-help-${scope}`}
                    data-testid="branding-built-on-whity"
                  />
                  <div className="min-w-0">
                    <label htmlFor={`branding-built-on-whity-${scope}`} className="text-sm text-foreground">
                      {t('branding.builtOnWhity.switch', 'Show “Built on Whity” in the footer')}
                    </label>
                    <p id={`branding-built-on-whity-help-${scope}`} className="text-xs text-muted-foreground">
                      {builtOnWhityHelp}
                    </p>
                  </div>
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        <BrandPreview
          name={name}
          colour={colour}
          squareLogoUrl={branding?.logoSquareUrl ?? null}
          builtOnWhity={builtOnWhity}
        />
        </div>
      </div>
    </>
  );
}
