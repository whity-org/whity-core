'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { NoAccess } from '@/components/no-access';
import { api } from '@/lib/api/client';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { TRANSLATIONS_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@whity/ui/button';
import { Badge } from '@whity/ui/badge';
import { Switch } from '@whity/ui/switch';
import { SearchField } from '@whity/ui/search-field';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@whity/ui/select';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { IconPlus } from '@tabler/icons-react';
import { useI18nAvailability, useTranslation } from '@whity/features/i18n';
import { EditableTranslationCell } from './editable-cell';
import { AddKeyModal } from './add-key-modal';
import { errorMessage } from '../languages/shared';
import type { TranslationAdminRow } from './types';

/**
 * Unlike Languages, translation rows ARE tenant-scoped — every tenant holding
 * `translations:manage` may reach this page, but which COLUMN is editable
 * depends on the caller: the system tenant (id 0) edits only the
 * system-default column, a regular tenant edits only its own tenant-override
 * column (WC-583, mirroring the base-roles / global-settings asymmetry).
 *
 * This screen is also the WORKFLOW for everything the extraction pipeline
 * produces. `whity-cli i18n:sync` seeds English and nothing else — deliberately,
 * because a machine-translated or English-copied row is indistinguishable from a
 * finished one — so every other language arrives here as a gap, and the person
 * who closes it is a translator, not an engineer. Hence the coverage panel:
 * before it existed, a list of rows could only show work already DONE, and a
 * language looked most complete exactly when nobody had started it.
 *
 * REACHABLE EVEN WHEN i18n IS SWITCHED OFF (`i18n.enabled`), for the same reason
 * as the Languages page — and with more force, given the paragraph above: doing
 * this work BEFORE the operator turns the feature on is exactly what the flag is
 * for. Every edit here is persisted for real. It reads the PUBLIC language list
 * rather than the admin one deliberately — this page is open to any tenant
 * holding `translations:manage`, while the admin listing is system-tenant-only —
 * which is also why that endpoint keeps serving the whole catalogue with the
 * flag off instead of narrowing to one language.
 */
const SYSTEM_TENANT_ID = 0;
const DEFAULT_DOMAIN = 'common';

export default function TranslationsPage() {
  const { user } = useAuth();
  const { addToast } = useToast();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');
  // Three-valued: the notice below ASSERTS the feature is off, so it must wait
  // for a real answer rather than treating "not loaded yet" as "off".
  const i18n = useI18nAvailability();

  const canManage = hasPermission(TRANSLATIONS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  const [languageCode, setLanguageCode] = useState('en');
  const [domain, setDomain] = useState(DEFAULT_DOMAIN);
  const [untranslatedOnly, setUntranslatedOnly] = useState(false);
  const [search, setSearch] = useState('');
  // Filters apply as they change; there is no Load button.
  const appliedFilter = { languageCode, domain: domain.trim(), untranslatedOnly };
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);

  const { data: coverage, refetch: refetchCoverage } = useFetch(async () => {
    const { data: body, error } = await api.GET('/api/v1/translations/coverage');
    if (body === undefined) {
      throw new Error(errorMessage(error, t('translations.coverage.error', 'Failed to load translation coverage')));
    }
    return body.data;
  }, []);

  const {
    data: rows,
    loading: isLoading,
    error,
    refetch,
  } = useFetch(async () => {
    const { data: body, error } = await api.GET('/api/v1/translations', {
      params: {
        query: {
          language_code: appliedFilter.languageCode,
          domain: appliedFilter.domain,
          ...(appliedFilter.untranslatedOnly ? { untranslated: '1' } : {}),
        },
      },
    });
    if (body === undefined) {
      throw new Error(errorMessage(error, t('translations.error.load', 'Failed to load translations')));
    }
    return body.data;
  }, [languageCode, domain, untranslatedOnly]);

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const languages = useMemo(() => coverage?.languages ?? [], [coverage]);
  const selectedCoverage = useMemo(
    () => languages.find((language) => language.language_code === languageCode),
    [languages, languageCode]
  );
  // Shown only once we KNOW the selected language is not the source language —
  // there is nothing to translate English from, and a column of em-dashes while
  // coverage is still loading is worse than no column.
  const showSourceColumn = coverage != null && languageCode !== coverage.source_language_code;
  const columnCount = showSourceColumn ? 4 : 3;

  const needle = search.trim().toLowerCase();
  const visibleRows = (rows ?? []).filter(
    (row) =>
      needle === '' ||
      row.key.toLowerCase().includes(needle) ||
      (row.source_text ?? '').toLowerCase().includes(needle) ||
      (row.system_default?.translation ?? '').toLowerCase().includes(needle) ||
      (row.tenant_override?.translation ?? '').toLowerCase().includes(needle)
  );

  const handleSaveCell = useCallback(
    async (row: TranslationAdminRow, existingId: number | undefined, value: string) => {
      const { error } = existingId
        ? await api.PATCH('/api/v1/translations/{id}', {
            params: { path: { id: existingId } },
            body: { translation: value },
          })
        : await api.POST('/api/v1/translations', {
            body: {
              language_code: languageCode,
              domain: domain.trim(),
              key: row.key,
              translation: value,
            },
          });
      if (error) {
        throw new Error(errorMessage(error, t('translations.error.save', 'Failed to save translation')));
      }
      addToast(t('translations.saved', 'Translation saved.'), 'success');
      refetch();
      refetchCoverage();
    },
    [languageCode, domain, addToast, refetch, refetchCoverage, t]
  );

  const handleDelete = useCallback(
    async (id: number, label: string) => {
      const { error } = await api.DELETE('/api/v1/translations/{id}', {
        params: { path: { id } },
      });
      if (error) {
        addToast(
          errorMessage(error, t('translations.delete.failed', 'Failed to delete the {label}', { label })),
          'error'
        );
        return;
      }
      addToast(t('translations.deleted', '{label} deleted.', { label }), 'success');
      refetch();
      refetchCoverage();
    },
    [addToast, refetch, refetchCoverage, t]
  );

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!canManage) {
    return (
      <NoAccess area={t('access.area.translations.accessDenied', 'translations')} permission="translations:manage" />
    );
  }

  return (
    <div className="space-y-8">
      <AdminHeader
        title={t('translations.title', 'Translations')}
        description={
          isSystemTenant
            ? t(
                'translations.description.system',
                'Edit the system-default translation strings shared by every tenant.'
              )
            : t(
                'translations.description.tenant',
                "Edit your tenant's translation overrides. System defaults are shown alongside for reference."
              )
        }
        action={
          <TechnicalDetails>
            <Button onClick={() => setIsAddModalOpen(true)} variant="outline" className="gap-2">
              <IconPlus size={18} />
              {t('translations.addKey', 'Add Key')}
            </Button>
          </TechnicalDetails>
        }
      />

      {i18n === 'disabled' && (
        <Alert variant="info" data-testid="i18n-disabled-notice">
          <AlertDescription>
            {t(
              'translations.i18nDisabled',
              'Multiple languages are switched off for this instance, so everyone currently sees the default language. Translations edited here are saved and take effect as soon as an operator turns the feature on under Feature Flags.'
            )}
          </AlertDescription>
        </Alert>
      )}

      <section className="space-y-3">
        <p className="text-sm text-muted-foreground">
          {t(
            'translations.coverage.hint',
            'English comes from the code itself. Every other language is filled in here.'
          )}
        </p>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {languages.map((language) => {
            const isSource = language.language_code === coverage?.source_language_code;
            const percent = language.total > 0 ? Math.floor((language.translated / language.total) * 100) : 100;
            return (
              <button
                key={language.language_code}
                type="button"
                aria-pressed={language.language_code === languageCode}
                onClick={() => setLanguageCode(language.language_code)}
                className={`rounded-card border bg-card p-4 text-start transition-colors ${
                  language.language_code === languageCode ? 'border-primary' : 'border-border hover:bg-muted/40'
                }`}
              >
                <span className="block text-sm text-muted-foreground">
                  {language.name} ({language.language_code})
                </span>
                <span
                  className={`mt-1 block text-2xl font-semibold ${
                    isSource || language.missing === 0 ? 'text-foreground' : 'text-warning'
                  }`}
                  dir="ltr"
                >
                  {percent}%
                </span>
                <span className="mt-1 block text-sm text-muted-foreground">
                  {t('translations.coverage.summary', '{translated} of {total} translated', {
                    translated: language.translated,
                    total: language.total,
                  })}
                </span>
                {isSource ? (
                  <Badge variant="secondary" className="mt-2 text-xs">
                    {t('translations.coverage.sourceLanguage', 'Source language')}
                  </Badge>
                ) : language.missing > 0 ? (
                  <Badge variant="destructive" className="mt-2 text-xs">
                    {t('translations.coverage.missing', '{count} missing', { count: language.missing })}
                  </Badge>
                ) : (
                  <Badge variant="secondary" className="mt-2 text-xs">
                    {t('translations.coverage.complete', 'Complete')}
                  </Badge>
                )}
              </button>
            );
          })}
        </div>

        {selectedCoverage && selectedCoverage.domains.length > 0 && (
          <ul className="divide-y divide-border rounded-md border border-border">
            {selectedCoverage.domains.map((domainCoverage) => (
              <li key={domainCoverage.domain}>
                <button
                  type="button"
                  className="flex w-full items-center justify-between gap-3 p-2.5 text-start text-sm hover:bg-muted/40"
                  onClick={() => {
                    setDomain(domainCoverage.domain);
                    setUntranslatedOnly(domainCoverage.missing > 0);
                  }}
                >
                  <span className="text-sm font-medium">{domainCoverage.domain}</span>
                  <span className="flex items-center gap-2 text-xs text-muted-foreground">
                    {t('translations.coverage.summary', '{translated} of {total} translated', {
                      translated: domainCoverage.translated,
                      total: domainCoverage.total,
                    })}
                    {domainCoverage.missing > 0 && (
                      <Badge variant="destructive" className="text-xs">
                        {t('translations.coverage.missing', '{count} missing', {
                          count: domainCoverage.missing,
                        })}
                      </Badge>
                    )}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <div className="flex flex-wrap items-center gap-3">
        <SearchField
          value={search}
          onValueChange={setSearch}
          label={t('translations.filter.search', 'Search wording')}
          clearLabel={t('translations.filter.clear', 'Clear search')}
          placeholder={t('translations.filter.search', 'Search wording')}
          className="w-64"
        />
        <Select value={languageCode} onValueChange={setLanguageCode}>
          <SelectTrigger className="w-44" aria-label={t('translations.filter.language', 'Language')}>
            <SelectValue placeholder={t('translations.filter.languagePlaceholder', 'Select a language')} />
          </SelectTrigger>
          <SelectContent>
            {languages.map((language) => (
              <SelectItem key={language.language_code} value={language.language_code}>
                {language.name} ({language.language_code})
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select value={domain} onValueChange={setDomain}>
          <SelectTrigger className="w-44" aria-label={t('translations.filter.area', 'Area')}>
            <SelectValue placeholder={t('translations.filter.area', 'Area')} />
          </SelectTrigger>
          <SelectContent>
            {[...new Set([domain, ...(selectedCoverage?.domains ?? []).map((d) => d.domain)])].map((name) => (
              <SelectItem key={name} value={name}>
                {name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <label className="flex items-center gap-2 text-sm font-medium text-foreground">
          <Switch checked={untranslatedOnly} onCheckedChange={setUntranslatedOnly} />
          {t('translations.filter.untranslatedOnly', 'Only untranslated')}
        </label>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            <tr>
              {showSourceColumn && (
                <th className="p-3 text-start font-medium">
                  {t('translations.column.source', 'English source')}
                </th>
              )}
              <th className="p-3 text-start font-medium">
                {t('translations.column.systemDefault', 'Whity’s wording')}
                {isSystemTenant && (
                  <Badge variant="secondary" className="ms-2 text-xs">
                    {t('translations.badge.editable', 'editable')}
                  </Badge>
                )}
              </th>
              <th className="p-3 text-start font-medium">
                {t('translations.column.tenantOverride', 'Yours')}
                {!isSystemTenant && (
                  <Badge variant="secondary" className="ms-2 text-xs">
                    {t('translations.badge.editable', 'editable')}
                  </Badge>
                )}
              </th>
              <TechnicalDetails>
                <th className="p-3 text-start font-medium">{t('translations.column.key', 'Key')}</th>
              </TechnicalDetails>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr>
                <td colSpan={columnCount} className="p-6 text-center text-muted-foreground">
                  {t('translations.loading', 'Loading…')}
                </td>
              </tr>
            ) : visibleRows.length === 0 ? (
              <tr>
                <td colSpan={columnCount} className="p-6 text-center text-muted-foreground">
                  {untranslatedOnly
                    ? t(
                        'translations.empty.untranslated',
                        'Nothing left untranslated in this domain.'
                      )
                    : t(
                        'translations.empty',
                        'No keys found for this language and domain yet. Use "Add Key" to create one.'
                      )}
                </td>
              </tr>
            ) : (
              visibleRows.map((row) => (
                <tr key={row.key} className="border-t border-border align-top">
                  {showSourceColumn && (
                    <td className="p-3 text-sm text-muted-foreground">{row.source_text ?? '—'}</td>
                  )}
                  <td className="p-3">
                    <EditableTranslationCell
                      value={row.system_default?.translation ?? null}
                      editable={isSystemTenant}
                      placeholder={t('translations.cell.noSystemDefault', 'No system default yet')}
                      onSave={(value) => handleSaveCell(row, row.system_default?.id, value)}
                      onDelete={
                        isSystemTenant && row.system_default
                          ? () =>
                              handleDelete(
                                row.system_default!.id,
                                t('translations.label.systemDefault', 'system default')
                              )
                          : undefined
                      }
                    />
                  </td>
                  <td className="p-3">
                    {isSystemTenant ? (
                      <span className="text-sm text-muted-foreground">
                        {t(
                          'translations.systemTenantNoOverride',
                          'The system tenant has no per-tenant override layer.'
                        )}
                      </span>
                    ) : (
                      <EditableTranslationCell
                        value={row.tenant_override?.translation ?? null}
                        editable={!isSystemTenant}
                        placeholder={t(
                          'translations.cell.noOverride',
                          'No override — using the system default'
                        )}
                        onSave={(value) => handleSaveCell(row, row.tenant_override?.id, value)}
                        onDelete={
                          row.tenant_override
                            ? () =>
                                handleDelete(
                                  row.tenant_override!.id,
                                  t('translations.label.override', 'override')
                                )
                            : undefined
                        }
                      />
                    )}
                  </td>
                  <TechnicalDetails>
                    <td className="p-3 font-mono text-xs" dir="ltr">
                      {row.key}
                    </td>
                  </TechnicalDetails>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <AddKeyModal
        isOpen={isAddModalOpen}
        onOpenChange={setIsAddModalOpen}
        languageCode={languageCode}
        domain={domain.trim()}
        onSuccess={() => {
          setIsAddModalOpen(false);
          refetch();
          refetchCoverage();
        }}
      />
    </div>
  );
}
