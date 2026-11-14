'use client';

/**
 * /admin/document-themes (WHIT-1024): the workspace's document theme, the one
 * every document and form printout uses unless a template picks another.
 * Two tabs: Documents (print colour, letterhead, title face, large print) and
 * Form printouts (design 3.4, `TplFormPrintStyle`, FP.1-FP.4). Edited in place
 * with the save bar (section 21, P.3).
 *
 * The workspace's theme is the one `documents.theme` points at. While that is
 * Whity's standard theme, which can't be changed, the page offers to make the
 * workspace's own (or to use one it already has) and points the setting at it.
 */

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { PageHeader } from '@whity/ui/page-header';
import { EmptyFirstTime, ReadOnlyNotice } from '@whity/ui/page-states';
import { SaveBar } from '@whity/ui/save-bar';
import { Skeleton } from '@whity/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { errorOf, loadThemeScreen, makeWorkspaceTheme, setWorkspaceTheme } from './api';
import { DocumentsTab } from './documents-tab';
import { FormPrintoutsTab } from './form-printouts-tab';
import { changedKeys, formKey, sameTokens, savePayload, type ThemeSchema, type ThemeTokens } from './model';

const THEMES_PERMISSION = 'documents:themes';
/** Whity Blue, when neither the theme nor the workspace sets a print colour. */
const FALLBACK_ACCENT = 'var(--primary)';

export default function DocumentThemesPage() {
  const t = useTranslation('documents');
  const { apiClient, user, memberships } = useAuth();
  const { addToast } = useToast();
  const { hasPermission, loading: capabilitiesLoading } = useCapabilities();
  const ar = useLanguageCode() === 'ar';
  const canWrite = hasPermission(THEMES_PERMISSION);

  const loadFailed = t('themes.loadFailed', 'The document theme could not be loaded.');
  const { data, error, forbidden, refetch } = useFetch(() => loadThemeScreen(apiClient, loadFailed), [apiClient]);

  const [draft, setDraft] = useState<ThemeTokens | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [making, setMaking] = useState(false);
  const [tab, setTab] = useState('form');

  const membership = memberships.find((m) => m.tenant_id === user?.tenant_id);
  const workspaceName = (ar ? membership?.tenant_names?.ar : undefined) || membership?.tenant_name || '';
  const theme = data?.theme ?? null;
  const saved = useMemo(() => theme?.tokens ?? {}, [theme]);
  const current = draft ?? saved;
  const dirty = draft !== null && !sameTokens(draft, saved);
  const title = theme ? (ar ? theme.name_ar || theme.name : theme.name || theme.name_ar) : t('themes.title', 'Document theme');

  if (!capabilitiesLoading && !hasPermission('documents:read')) {
    return <NoAccess area={t('themes.area', 'document themes')} permission="documents:read" />;
  }

  const make = async () => {
    setMaking(true);
    const names = { name: workspaceName || 'Workspace', name_ar: membership?.tenant_names?.ar || workspaceName || 'مساحة العمل' };
    const failed = await makeWorkspaceTheme(apiClient, names, t('themes.makeFailed', 'The workspace theme could not be made. Try again.'));
    setMaking(false);
    if (failed) addToast(failed, 'error');
    else refetch();
  };

  const adopt = async (ref: string) => {
    setMaking(true);
    const failed = await setWorkspaceTheme(apiClient, ref, t('themes.useFailed', 'That theme could not be made the workspace’s. Try again.'));
    setMaking(false);
    if (failed) addToast(failed, 'error');
    else refetch();
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
      refetch();
    } catch {
      setProblem(t('themes.saveFailed', 'The theme could not be saved. Try again.'));
    } finally {
      setSaving(false);
    }
  };

  const breadcrumb = (
    <nav aria-label={t('themes.breadcrumb', 'Breadcrumb')} className="flex flex-wrap items-center gap-1">
      <Link href="/admin/document-library" className="hover:underline">
        {t('themes.crumb.documents', 'Documents')}
      </Link>
      <Icon name={ar ? 'chevron-left' : 'chevron-right'} size={12} />
      <span>{t('themes.crumb.themes', 'Document themes')}</span>
      {theme && (
        <>
          <Icon name={ar ? 'chevron-left' : 'chevron-right'} size={12} />
          <span aria-current="page" className="text-foreground">
            {title}
          </span>
        </>
      )}
    </nav>
  );

  return (
    <div className="pb-16">
      <PageHeader
        breadcrumb={breadcrumb}
        title={title}
        description={t(
          'themes.description',
          'Your workspace theme. Every document and form printout uses it unless a template picks another.'
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
      ) : theme === null ? (
        <EmptyFirstTime
          icon="template"
          title={t('themes.none.title', 'Your workspace uses Whity’s standard theme')}
          description={
            canWrite
              ? t('themes.none.body', 'Make your workspace’s own theme to choose its print colour, letterhead and how forms print. Every document and form printout will use it.')
              : t('themes.none.readOnly', 'Someone who manages document themes can make the workspace’s own theme.')
          }
          action={
            canWrite ? (
              <div className="flex flex-wrap justify-center gap-2">
                <Button loading={making} onClick={() => void make()}>
                  {t('themes.none.make', 'Make the workspace theme')}
                </Button>
                {data.others.map((other) => (
                  <Button key={other.ref} variant="outline" disabled={making} onClick={() => void adopt(other.ref)}>
                    {t('themes.none.use', 'Use {name}', { name: ar ? other.name_ar || other.name : other.name })}
                  </Button>
                ))}
              </div>
            ) : undefined
          }
        />
      ) : (
        <Tabs value={tab} onValueChange={setTab} className="gap-4">
          <TabsList>
            <TabsTrigger value="documents">{t('themes.tab.documents', 'Documents')}</TabsTrigger>
            <TabsTrigger value="form">{t('themes.tab.form', 'Form printouts')}</TabsTrigger>
          </TabsList>

          {!canWrite && !capabilitiesLoading && (
            <ReadOnlyNotice
              title={t('themes.readOnly.title', 'You can see the theme but not change it')}
              description={t('themes.readOnly.body', 'Someone who manages document themes changes it.')}
            />
          )}
          {problem && (
            <Alert variant="destructive">
              <AlertDescription className="text-sm text-foreground opacity-100">{problem}</AlertDescription>
            </Alert>
          )}

          <TabsContent value="documents">
            <DocumentsTab
              tokens={current}
              schema={data.schema.tokens}
              resolved={theme.resolved?.tokens ?? {}}
              readOnly={!canWrite}
              ar={ar}
              onChange={setDraft}
            />
          </TabsContent>
          <TabsContent value="form">
            <FormPrintoutsTab
              apiClient={apiClient}
              schema={data.schema.form}
              tokens={current}
              readOnly={!canWrite}
              trial={data.trial}
              accent={current.accent ?? theme.resolved?.tokens?.accent ?? FALLBACK_ACCENT}
              workspaceName={workspaceName || title}
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
