'use client';

/**
 * /admin/document-themes (WHIT-1024, WHIT-785): Documents › Document themes
 * (`TplDocThemes`). The themes the workspace can use are listed on the start
 * side, Whity's standard first and the workspace default marked; the chosen
 * one opens beside them with two tabs: Documents (print colour, letterhead,
 * title face, large print, with a quote previewed as it changes) and Form
 * printouts (design 3.4, `TplFormPrintStyle`, FP.1-FP.4). Edited in place
 * with the save bar (section 21, P.3).
 *
 * The workspace default is the theme `documents.theme` points at. While the
 * workspace has no theme of its own, the page offers to make one and points
 * the setting at it.
 */

import { useEffect, useMemo, useState } from 'react';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { PageHeader } from '@whity/ui/page-header';
import { EmptyFirstTime, ReadOnlyNotice } from '@whity/ui/page-states';
import { SaveBar } from '@whity/ui/save-bar';
import { Skeleton } from '@whity/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import {
  createTheme,
  errorOf,
  loadCompanyLines,
  loadPublishedUsage,
  loadTheme,
  loadThemeScreen,
  makeWorkspaceTheme,
  setWorkspaceTheme,
  type CompanyLines,
} from './api';
import { apiFetch } from '@/lib/api/client';
import { DocumentsTab } from './documents-tab';
import { FormPrintoutsTab } from './form-printouts-tab';
import {
  changedKeys,
  contrastOnWhite,
  formKey,
  newThemeKey,
  READABLE_ON_WHITE,
  sameTokens,
  savePayload,
  STANDARD_REF,
  themeName,
  type ThemeRow,
  type ThemeSchema,
  type ThemeTokens,
} from './model';
import { QuotePreview } from './quote-preview';
import { ThemeList } from './theme-list';

const THEMES_PERMISSION = 'documents:themes';
/** Whity Blue, when neither the theme nor the workspace sets a print colour. */
const FALLBACK_ACCENT = 'var(--primary)';
const NO_COMPANY: CompanyLines = { address: '', taxId: '', phone: '', email: '' };
const STANDARD_NAMES = { name: 'Whity standard', name_ar: 'نسق Whity القياسي' };

export default function DocumentThemesPage() {
  const t = useTranslation('documents');
  const { user, memberships } = useAuth();
  const apiClient = apiFetch;
  const { addToast } = useToast();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const ar = useLanguageCode() === 'ar';
  const canWrite = hasPermission(THEMES_PERMISSION);

  const loadFailed = t('themes.loadFailed', 'The document theme could not be loaded.');
  const { data, error, forbidden, refetch } = useFetch(() => loadThemeScreen(apiClient, loadFailed), [apiClient]);

  const [chosenRef, setChosenRef] = useState<string | null>(null);
  const selectedRef = chosenRef ?? data?.workspaceRef ?? null;
  const [version, setVersion] = useState(0);
  const {
    data: loaded,
    error: themeError,
    refetch: refetchTheme,
  } = useFetch(
    () => (selectedRef ? loadTheme(apiClient, selectedRef, loadFailed) : Promise.resolve(null)),
    [apiClient, selectedRef, version]
  );
  const theme: ThemeRow | null = loaded && loaded.ref === selectedRef ? loaded : null;

  const [usage, setUsage] = useState<{ ref: string; n: number } | null>(null);
  const [company, setCompany] = useState<CompanyLines>(NO_COMPANY);
  const [draft, setDraft] = useState<ThemeTokens | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [making, setMaking] = useState(false);
  const [tab, setTab] = useState('documents');

  useEffect(() => {
    if (!selectedRef || selectedRef === STANDARD_REF) return;
    let live = true;
    void loadPublishedUsage(apiClient, selectedRef).then((n) => {
      if (live) setUsage({ ref: selectedRef, n });
    });
    return () => {
      live = false;
    };
  }, [apiClient, selectedRef, version]);
  // Whity's standard is never "used" by a template in a way that changes it.
  const published = usage && usage.ref === selectedRef ? usage.n : 0;

  useEffect(() => {
    let live = true;
    void loadCompanyLines(apiClient, ar).then((lines) => {
      if (live) setCompany(lines);
    });
    return () => {
      live = false;
    };
  }, [apiClient, ar]);

  const membership = memberships.find((m) => m.tenant_id === user?.tenant_id);
  const workspaceName = (ar ? membership?.tenant_names?.ar : undefined) || membership?.tenant_name || '';
  const saved = useMemo(() => theme?.tokens ?? {}, [theme]);
  const current = draft ?? saved;
  const dirty = draft !== null && !sameTokens(draft, saved);
  const editable = canWrite && theme !== null && theme.scope === 'tenant';
  const ownThemes = data?.themes.filter((row) => row.scope === 'tenant') ?? [];

  if (!capabilitiesLoading && !hasPermission('documents:read')) {
    return <NoAccess area={t('themes.area', 'document themes')} permission="documents:read" />;
  }

  const reload = () => {
    setDraft(null);
    setProblem(null);
    setVersion((v) => v + 1);
    refetch();
  };

  const make = async () => {
    setMaking(true);
    const names = { name: workspaceName || 'Workspace', name_ar: membership?.tenant_names?.ar || workspaceName || 'مساحة العمل' };
    const failed = await makeWorkspaceTheme(apiClient, names, t('themes.makeFailed', 'The workspace theme could not be made. Try again.'));
    setMaking(false);
    if (failed) addToast(failed, 'error');
    else reload();
  };

  const makeDefault = async (ref: string) => {
    setMaking(true);
    const failed = await setWorkspaceTheme(apiClient, ref, t('themes.useFailed', 'That theme could not be made the workspace’s. Try again.'));
    setMaking(false);
    if (failed) {
      addToast(failed, 'error');
      return;
    }
    addToast(t('themes.madeDefault', 'Documents without a theme of their own now use this one'), 'success');
    reload();
  };

  const create = async (name: string) => {
    if (!data) return;
    setMaking(true);
    const result = await createTheme(
      apiClient,
      {
        key: newThemeKey(name, ownThemes.map((row) => row.key)),
        name,
        name_ar: name,
        // A copy of the workspace default's settings to start from.
        tokens: data.themes.find((row) => row.ref === data.workspaceRef && row.scope === 'tenant')?.tokens ?? {},
      },
      t('themes.createFailed', 'The theme could not be made. Try again.')
    );
    setMaking(false);
    if ('problem' in result) {
      addToast(result.problem, 'error');
      return;
    }
    setChosenRef(result.ref);
    reload();
  };

  const select = (ref: string) => {
    if (ref === selectedRef) return;
    if (dirty) {
      addToast(t('themes.unsavedSwitch', 'Save or discard your changes before opening another theme.'), 'info');
      return;
    }
    setDraft(null);
    setProblem(null);
    setChosenRef(ref);
  };

  const save = async () => {
    if (!theme || draft === null) return;
    setSaving(true);
    setProblem(null);
    const { url, init } = savePayload(theme, draft);
    try {
      const response = await apiClient(url, init);
      if (response.status === 409) {
        setProblem(
          t('themes.stale', 'Someone changed this theme while you were editing. Discard your changes to see theirs, then make yours again.')
        );
        return;
      }
      if (!response.ok) {
        setProblem(await errorOf(response, t('themes.saveFailed', 'The theme could not be saved. Try again.')));
        return;
      }
      setDraft(null);
      addToast(t('themes.saved', 'Theme saved'), 'success');
      setVersion((v) => v + 1);
      refetch();
    } catch {
      setProblem(t('themes.saveFailed', 'The theme could not be saved. Try again.'));
    } finally {
      setSaving(false);
    }
  };

  // No breadcrumb of its own: document themes are a workspace setting
  // (WHIT-1082), and the Settings frame shows "Settings › Document themes".

  // What the selected theme prints with: its own values over what it resolves to.
  const resolved: ThemeTokens = theme?.resolved?.tokens ?? {};
  const printing: ThemeTokens = {
    ...resolved,
    ...current,
    letterhead: { ...(resolved.letterhead ?? {}), ...(current.letterhead ?? {}) },
  };
  const accent = current.accent ?? resolved.accent ?? FALLBACK_ACCENT;
  const ratio = contrastOnWhite(current.accent ?? resolved.accent);
  const accentOf = (row: ThemeRow) => (row.ref === theme?.ref ? accent : (row.tokens.accent ?? FALLBACK_ACCENT));

  const colourNote =
    ratio === null ? null : (
      <div className="grid gap-1">
        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
          <Icon name={ratio >= READABLE_ON_WHITE ? 'success' : 'alert'} size={14} />
          {ratio >= READABLE_ON_WHITE
            ? t('themes.contrast.ok', 'Reads on white ({ratio}:1)', { ratio: ratio.toFixed(1) })
            : t('themes.contrast.low', 'Too light for paper ({ratio}:1): printed darker', { ratio: ratio.toFixed(1) })}
        </span>
        <span className="text-xs text-muted-foreground">
          {t('themes.contrast.help', 'From your main colour. Too light a colour is darkened until it reads on paper.')}
        </span>
      </div>
    );

  const basedOn = theme ? (data?.themes.find((row) => row.ref === theme.extends) ?? STANDARD_NAMES) : STANDARD_NAMES;

  return (
    <div className="pb-16">
      <PageHeader
        title={t('themes.crumb.themes', 'Document themes')}
        description={t(
          'themes.listDescription',
          'How printed documents look: the print colour, the letterhead and a few options. Templates keep the theme they were published with.'
        )}
        className="mb-4 border-b-0 pb-0"
      />

      {error ? (
        <LoadProblem forbidden={forbidden} message={error} onRetry={refetch} area={t('themes.area', 'document themes')} />
      ) : data === null ? (
        <div role="status" aria-busy="true" aria-label={t('themes.loading', 'Loading the document theme')} className="grid gap-3">
          <Skeleton className="h-10 w-64" />
          <Skeleton className="h-48 w-full rounded-card" />
          <Skeleton className="h-48 w-full rounded-card" />
        </div>
      ) : ownThemes.length === 0 ? (
        <EmptyFirstTime
          icon="template"
          title={t('themes.none.title', 'Your workspace uses Whity’s standard theme')}
          description={
            canWrite
              ? t(
                  'themes.none.body',
                  'Make your workspace’s own theme to choose its print colour, letterhead and how forms print. Every document and form printout will use it.'
                )
              : t('themes.none.readOnly', 'Someone who manages document themes can make the workspace’s own theme.')
          }
          action={
            canWrite ? (
              <Button loading={making} onClick={() => void make()}>
                {t('themes.none.make', 'Make the workspace theme')}
              </Button>
            ) : undefined
          }
        />
      ) : (
        <div className="grid items-start gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
          <ThemeList
            themes={data.themes}
            selectedRef={selectedRef ?? data.workspaceRef}
            workspaceRef={data.workspaceRef}
            accentOf={accentOf}
            canWrite={canWrite}
            creating={making}
            ar={ar}
            onSelect={select}
            onCreate={(name) => void create(name)}
          />

          {themeError ? (
            <LoadProblem forbidden={false} message={themeError} onRetry={refetchTheme} area={t('themes.area', 'document themes')} />
          ) : theme === null ? (
            <div role="status" aria-busy="true" aria-label={t('themes.loading', 'Loading the document theme')} className="grid gap-3">
              <Skeleton className="h-10 w-64" />
              <Skeleton className="h-48 w-full rounded-card" />
            </div>
          ) : (
            <div className="grid min-w-0 gap-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-0.5">
                  <h2 className="text-lg font-semibold text-foreground">{themeName(theme, ar)}</h2>
                  <p className="text-sm text-muted-foreground">
                    {theme.scope === 'system'
                      ? t('themes.standardBody', 'Whity’s own theme. Make a theme of your own to change how documents print.')
                      : t('themes.basedBody', 'Based on {name}. Everything not set here comes from it.', { name: themeName(basedOn, ar) })}
                  </p>
                </div>
                {canWrite && theme.ref !== data.workspaceRef && (
                  <Button variant="outline" loading={making} disabled={dirty} onClick={() => void makeDefault(theme.ref)}>
                    {t('themes.makeDefault', 'Make it the workspace default')}
                  </Button>
                )}
              </div>

              {published > 0 && (
                <Alert variant="info">
                  <AlertTitle>{t('themes.usedBy', 'Used by {n} published templates', { n: published })}</AlertTitle>
                  <AlertDescription>{t('themes.usedByBody', 'They change at their next publish. Issued documents never change.')}</AlertDescription>
                </Alert>
              )}

              <Tabs value={tab} onValueChange={setTab} className="gap-4">
                <TabsList>
                  <TabsTrigger value="documents">{t('themes.tab.documents', 'Documents')}</TabsTrigger>
                  <TabsTrigger value="form">{t('themes.tab.form', 'Form printouts')}</TabsTrigger>
                </TabsList>

                {theme.scope === 'system' ? (
                  <ReadOnlyNotice
                    title={t('themes.standardReadOnly.title', 'Whity’s standard theme can’t be changed')}
                    description={t('themes.standardReadOnly.body', 'Make a theme of your own, or open one of yours, to change it.')}
                  />
                ) : (
                  !canWrite &&
                  !capabilitiesLoading && (
                    <ReadOnlyNotice
                      title={t('themes.readOnly.title', 'You can see the theme but not change it')}
                      description={t('themes.readOnly.body', 'Someone who manages document themes changes it.')}
                    />
                  )
                )}
                {problem && (
                  <Alert variant="destructive">
                    <AlertDescription className="text-sm text-foreground opacity-100">{problem}</AlertDescription>
                  </Alert>
                )}

                <TabsContent value="documents">
                  <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <DocumentsTab
                      tokens={current}
                      schema={data.schema.tokens}
                      resolved={resolved}
                      readOnly={!editable}
                      ar={ar}
                      colourNote={colourNote}
                      onChange={setDraft}
                    />
                    <QuotePreview
                      tokens={printing}
                      accent={accent}
                      workspaceName={workspaceName || themeName(theme, ar)}
                      company={company}
                      ar={ar}
                    />
                  </div>
                </TabsContent>
                <TabsContent value="form">
                  <FormPrintoutsTab
                    apiClient={apiClient}
                    schema={data.schema.form}
                    tokens={current}
                    readOnly={!editable}
                    trial={data.trial}
                    accent={accent}
                    workspaceName={workspaceName || themeName(theme, ar)}
                    ar={ar}
                    quietGate={dirty}
                    onChange={setDraft}
                  />
                </TabsContent>

                <SaveBar
                  dirty={dirty}
                  summary={
                    <span className="inline-flex items-center gap-2">
                      <Icon name="edit" size={16} />
                      {changeSummary(changedKeys(saved, current, data.schema), data.schema, ar, t)}
                    </span>
                  }
                  discardLabel={t('themes.discard', 'Discard')}
                  saveLabel={t('themes.save', 'Save changes')}
                  saving={saving}
                  onDiscard={() => {
                    setDraft(null);
                    setProblem(null);
                  }}
                  onSave={() => void save()}
                />
              </Tabs>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/** "You changed section headings and the footer." from the changed schema keys. */
function changeSummary(
  keys: string[],
  schema: ThemeSchema,
  ar: boolean,
  t: ReturnType<typeof useTranslation>
): string {
  const names = keys.map((key) => {
    if (key === 'form.footer') return t('themes.change.footer', 'the footer');
    if (key === 'form.marks') return t('themes.change.marks', 'your marks');
    const found = schema.tokens.find((s) => s.key === key) ?? schema.form.options.find((o) => o.key === key);
    if (!found) return formKey(key);
    const label = ar ? found.labelAr : found.label;
    return ar ? label : label.charAt(0).toLowerCase() + label.slice(1);
  });
  if (names.length === 0) return t('themes.change.none', 'You changed the theme.');
  const list = new Intl.ListFormat(ar ? 'ar' : 'en', { style: 'long', type: 'conjunction' }).format(names);
  return t('themes.change.some', 'You changed {what}.', { what: list });
}
