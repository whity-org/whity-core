'use client';

import { useEffect, useMemo, useState } from 'react';
import { NoAccess } from '@/components/no-access';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import Link from 'next/link';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { IconDeviceFloppy } from '@tabler/icons-react';
import { useTranslation } from '@whity/features/i18n';
import { TENANTS_READ, TENANTS_WRITE } from '@/lib/capabilities';
import { Input } from '@/components/ui/input';
import {
  SETTINGS_READ,
  SETTINGS_WRITE,
  SETTINGS_MANAGE,
  SYSTEM_TENANT_ID,
  COMPANY_SETTING_KEYS,
  DISPLAY_SETTING_KEYS,
  DOCUMENT_CHECK_SETTING_KEYS,
  qrDetailLevelCopy,
  GENERAL_SETTING_KEYS,
  generalFieldLabels,
  RegistrySettingControl,
  groupRegistry,
  validate,
  errorMessage,
  fieldErrorsFrom,
  SettingsField,
  type RegistryEntry,
  type SettingsMap,
  type SettingKey,
  type AddToast,
} from './settings-shared';

/**
 * General — the CURRENT tenant's overrides (support email, time zone, main
 * language). The product name, logos and colour live on Branding; SSO,
 * Sign-up, Email, and Storage each have their own system-tenant-gated tab.
 */
export default function AdminSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');

  const canRead = hasPermission(SETTINGS_READ);
  const canWrite = hasPermission(SETTINGS_WRITE);
  const canManageGlobal = hasPermission(SETTINGS_MANAGE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!canRead) {
    return (
      <NoAccess area={t('access.area.settings.accessDenied', 'settings')} permission="settings:read" />
    );
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title={t('settings.title', 'General')}
        description={t(
          'settings.description',
          'Your workspace’s name and basics. A cleared field uses the platform default.'
        )}
      />
      <TechnicalDetails>
        <p className="text-sm text-muted-foreground">
          {t('settings.description.permission', 'Editing needs the settings:write permission.')}
        </p>
      </TechnicalDetails>
      <TenantSettingsSection
        canWrite={canWrite}
        canRename={!isSystemTenant && hasPermission(TENANTS_WRITE)}
        addToast={addToast}
      />
      {!isSystemTenant && typeof user?.tenant_id === 'number' && hasPermission(TENANTS_READ) && (
        <WorkspaceRecordLink tenantId={user.tenant_id} />
      )}
      {isSystemTenant && canManageGlobal && <PlatformDefaultsSection addToast={addToast} />}
    </div>
  );
}

/**
 * The way to a workspace's own record, which Tenants in the sidebar no longer gives
 * a workspace admin.
 */
function WorkspaceRecordLink({ tenantId }: { tenantId: number }) {
  const t = useTranslation('admin');

  return (
    <Card className="border border-border bg-card shadow-sm">
      <CardHeader>
        <CardTitle className="text-lg font-semibold font-heading">
          {t('settings.workspaceRecord.title', 'Workspace record')}
        </CardTitle>
        <CardDescription>
          {t('settings.workspaceRecord.description', "This workspace's own record, with its name and address.")}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Button asChild variant="outline">
          <Link href={`/admin/tenants/${tenantId}`}>{t('settings.workspaceRecord.open', 'Open workspace record')}</Link>
        </Button>
      </CardContent>
    </Card>
  );
}

function TenantSettingsSection({
  canWrite,
  canRename,
  addToast,
}: {
  canWrite: boolean;
  /**
   * WHIT-1189: the workspace's owners and admins (tenants:write) rename it here,
   * as TplSettingsGeneral shows. The server audits it (tenant.updated) and
   * refuses a blank name.
   */
  canRename: boolean;
  addToast: AddToast;
}) {
  const t = useTranslation('admin');
  const { user, memberships, refreshAuth } = useAuth();
  const savedWorkspaceName = memberships.find((m) => m.tenant_id === user?.tenant_id)?.tenant_name ?? '';
  const [nameDraft, setNameDraft] = useState<string | undefined>(undefined);
  const workspaceName = nameDraft ?? savedWorkspaceName;
  const renaming = canRename && nameDraft !== undefined && nameDraft.trim() !== savedWorkspaceName;
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings');
    if (body === undefined) {
      throw new Error(errorMessage(getError, t('settings.tenant.loadFailed', 'Failed to load settings')));
    }
    return body.data;
  }, []);

  // Draft overlay: only edited keys live in state; displayed as
  // `draft[key] ?? fetched[key]`, so the form is never seeded via an effect.
  const [draft, setDraft] = useState<Partial<Record<(typeof GENERAL_SETTING_KEYS)[number], string>>>({});
  const [saving, setSaving] = useState(false);

  const effective = data?.effective;
  const overridden = useMemo(() => new Set(data?.overridden ?? []), [data]);
  // WC-224: the system tenant (0) has globals only, so a per-tenant write always
  // 422s — when false we hide the editable form and point at Platform defaults.
  const tenantOverridable = data?.tenant_overridable ?? true;

  useEffect(() => {
    if (error) {
      addToast(error, 'error');
    }
  }, [error, addToast]);

  const valueOf = (key: SettingKey): string => draft[key as keyof typeof draft] ?? effective?.[key] ?? '';

  /**
   * The display preferences that live on this card alongside the four general
   * fields (#1068).
   *
   * Read from the SERVER's registry rather than hard-coded, so the control gets
   * its type (a toggle, because the key is a bool) and its default from the one
   * place that defines them. `GENERAL_SETTING_KEYS` above stays a fixed list
   * because those four have bespoke controls — a timezone picker, a locale
   * picker — that a registry descriptor cannot describe.
   */
  const displayEntries = useMemo(
    () => (data?.registry ?? []).filter((entry) => DISPLAY_SETTING_KEYS.includes(entry.key)),
    [data]
  );

  // WHIT-788: in the order the list gives, not the registry's.
  const companyEntries = useMemo(
    () =>
      COMPANY_SETTING_KEYS.flatMap((key) => (data?.registry ?? []).filter((entry) => entry.key === key)),
    [data]
  );

  // #1730: what the public check of a document's QR code tells a holder.
  const documentCheckEntries = useMemo(
    () =>
      DOCUMENT_CHECK_SETTING_KEYS.flatMap((key) => (data?.registry ?? []).filter((entry) => entry.key === key)),
    [data]
  );

  const setField = (key: SettingKey, value: string) => {
    setDraft((prev) => ({ ...prev, [key]: value }));
  };

  const handleSave = async () => {
    if (!effective) return;

    const current = {
      support_email: valueOf('support_email'),
      timezone: valueOf('timezone'),
      locale: valueOf('locale'),
    };

    if (renaming && workspaceName.trim() === '') {
      addToast(t('settings.workspaceName.required', 'The workspace name cannot be empty.'), 'error');
      return;
    }

    // The product name is on Branding now; only the email is checked here.
    const validationError = validate({ site_name: '', support_email: current.support_email }, false, t);
    if (validationError) {
      addToast(validationError, 'error');
      return;
    }

    // An empty value clears the override (falls back to global/default).
    const settings: Record<string, string | null> = {};
    for (const key of GENERAL_SETTING_KEYS) {
      const value = current[key].trim();
      settings[key] = value === '' ? '' : value;
    }

    // #1068: only keys the reader actually TOUCHED. Sending the effective value
    // of an untouched toggle would write a per-tenant override for something
    // nobody chose, and the difference between "inherited" and "overridden" is
    // the whole of what the badge beside it reports.
    for (const entry of [...displayEntries, ...companyEntries, ...documentCheckEntries]) {
      const edited = draft[entry.key as keyof typeof draft];
      if (edited !== undefined) {
        settings[entry.key] = edited;
      }
    }

    setSaving(true);
    try {
      if (canWrite) {
        const { error: patchError } = await api.PATCH('/api/v1/settings', {
          body: { settings },
        });
        if (patchError) {
          throw new Error(errorMessage(patchError, t('settings.tenant.saveFailed', 'Failed to save settings')));
        }
      }
      if (renaming && typeof user?.tenant_id === 'number') {
        const { error: renameError } = await api.PATCH('/api/v1/tenants/{id}', {
          params: { path: { id: user.tenant_id } },
          body: { name: workspaceName.trim() },
        });
        if (renameError) {
          throw new Error(errorMessage(renameError, t('settings.workspaceName.saveFailed', 'Failed to rename the workspace')));
        }
        setNameDraft(undefined);
        // The workspace switcher and every "Workspace settings for …" line read it.
        void refreshAuth();
      }
      addToast(t('settings.tenant.saved', 'Tenant settings saved.'), 'success');
      setDraft({});
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error ? err.message : t('settings.tenant.saveFailed', 'Failed to save settings'),
        'error'
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="border border-border bg-card shadow-sm">
      <CardHeader>
        <CardTitle className="text-lg font-semibold font-heading">
          <h2>{t('settings.tenant.title', 'Tenant settings')}</h2>
        </CardTitle>
        <CardDescription className="text-sm">
          {t(
            'settings.tenant.description',
            'Overrides for your current tenant. Cleared fields fall back to the global default.'
          )}
          {effective && tenantOverridable && !canWrite &&
            ' ' +
              t(
                'settings.tenant.readOnly',
                'You can read these settings but not change them.'
              )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        {loading || !effective ? (
          <div className="space-y-4">
            {GENERAL_SETTING_KEYS.map((key) => (
              <div key={key} className="h-12 animate-pulse rounded-md bg-muted/40" />
            ))}
          </div>
        ) : !tenantOverridable ? (
          // WC-224: the system tenant has no per-tenant override layer — a write
          // would always 422 — so we never show the editable fields. The Platform
          // defaults card below (rendered on this same page) is where they edit.
          <div
            data-testid="tenant-no-override-notice"
            role="note"
            className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
          >
            <p>
              {t(
                'settings.tenant.noOverrideNotice',
                'As the system tenant, you have no per-tenant overrides. Edit the platform-wide values below.'
              )}
            </p>
          </div>
        ) : (
          <>
            {canRename && (
              // Labelled like the SettingsField rows below it, so the card reads as one list.
              <div className="space-y-1.5">
                <label htmlFor="tenant-workspace-name" className="text-sm font-medium text-foreground">
                  {t('settings.workspaceName.label', 'Workspace name')}
                </label>
                <Input
                  id="tenant-workspace-name"
                  value={workspaceName}
                  maxLength={255}
                  onChange={(e) => setNameDraft(e.target.value)}
                  aria-describedby="tenant-workspace-name-help"
                  data-testid="settings-workspace-name"
                />
                <p id="tenant-workspace-name-help" className="text-xs text-muted-foreground">
                  {t(
                    'settings.workspaceName.help',
                    'What your team sees in the workspace switcher. Names in other languages are on the workspace record.'
                  )}
                </p>
              </div>
            )}
            {GENERAL_SETTING_KEYS.map((key) => (
              <SettingsField
                key={key}
                settingKey={key}
                idPrefix="tenant"
                label={generalFieldLabels(t)[key]}
                value={valueOf(key)}
                disabled={!canWrite}
                onChange={(value) => setField(key, value)}
                status={overridden.has(key) ? 'overridden' : 'inherited'}
              />
            ))}
            {/*
              #1068. On THIS card and not a tab of its own: it is a per-tenant
              preference about how this tenant's own screens read, which is what
              the tenant settings card is for, and a whole tab for one toggle
              would be a worse answer than a row.
            */}
            {displayEntries.map((entry) => (
              <RegistrySettingControl
                key={entry.key}
                entry={entry}
                idPrefix="tenant"
                value={valueOf(entry.key as SettingKey)}
                disabled={!canWrite}
                onChange={(value) => setField(entry.key as SettingKey, value)}
                status={overridden.has(entry.key) ? 'overridden' : 'inherited'}
              />
            ))}
            {companyEntries.length > 0 && (
              <div className="space-y-6 border-t border-border pt-6" data-testid="tenant-company-settings">
                <div>
                  <h3 className="text-sm font-medium text-foreground">{t('settings.company.title', 'Company')}</h3>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {t('settings.company.description', 'What your documents, letterheads and invoices print.')}
                  </p>
                </div>
                {companyEntries.map((entry) => (
                  <RegistrySettingControl
                    key={entry.key}
                    entry={entry}
                    idPrefix="tenant"
                    value={valueOf(entry.key as SettingKey)}
                    disabled={!canWrite}
                    onChange={(value) => setField(entry.key as SettingKey, value)}
                    status={overridden.has(entry.key) ? 'overridden' : 'inherited'}
                  />
                ))}
              </div>
            )}
            {documentCheckEntries.length > 0 && (
              <div className="space-y-6 border-t border-border pt-6" data-testid="tenant-document-check-settings">
                <div>
                  <h3 className="text-sm font-medium text-foreground">
                    {t('settings.documentCheck.title', 'Checking a document')}
                  </h3>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {t(
                      'settings.documentCheck.description',
                      'What anyone who scans the code printed on your documents is told.'
                    )}
                  </p>
                </div>
                {documentCheckEntries.map((entry) => {
                  const value = valueOf(entry.key as SettingKey);
                  if (entry.key === 'documents.qr_public_signer_names') {
                    // Names are only ever shown at the `signed` level, so the
                    // switch appears only once that level is chosen.
                    if (valueOf('documents.qr_public_detail' as SettingKey) !== 'signed') return null;
                  }
                  const level = entry.key === 'documents.qr_public_detail' ? qrDetailLevelCopy(t, value) : null;

                  return (
                    <div key={entry.key} className="space-y-1.5">
                      <RegistrySettingControl
                        entry={entry}
                        idPrefix="tenant"
                        value={value}
                        disabled={!canWrite}
                        optionLabel={(option) => qrDetailLevelCopy(t, option)?.label ?? option}
                        onChange={(next) => setField(entry.key as SettingKey, next)}
                        status={overridden.has(entry.key) ? 'overridden' : 'inherited'}
                      />
                      {level && (
                        <p className="text-xs text-muted-foreground" data-testid="qr-detail-level-help">
                          {level.help}
                        </p>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
            {(canWrite || canRename) && (
              <div className="flex justify-end pt-2">
                <Button onClick={handleSave} disabled={saving} className="gap-2">
                  <IconDeviceFloppy className="w-4 h-4" />
                  {saving
                    ? t('settings.saving', 'Saving…')
                    : t('settings.tenant.save', 'Save tenant settings')}
                </Button>
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * Platform defaults — the system tenant's instance-wide fallback values
 * (site name / timezone / support email / locale) plus the MCP integration
 * toggle. These used to live on the standalone Global Settings page's
 * "General"/"Integrations" sections; folded in here so a system-tenant admin
 * sees their own tenant fields and the platform-wide fallback right next to
 * each other instead of on a separate route.
 */
function PlatformDefaultsSection({ addToast }: { addToast: AddToast }) {
  const t = useTranslation('admin');
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings/global');
    if (body === undefined) {
      throw new Error(
        errorMessage(getError, t('settings.platform.loadFailed', 'Failed to load global defaults'))
      );
    }
    return body.data;
  }, []);

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const global = data?.global as SettingsMap | undefined;
  const registry = useMemo<RegistryEntry[]>(() => data?.registry ?? [], [data]);
  // General + Integrations are core's own platform-wide fields; `plugins` is the
  // section holding whatever the installed plugins declared with `admin => true`
  // (#713 item 1). The remaining sections live on their own dedicated tabs.
  const sections = useMemo(
    () =>
      groupRegistry(registry, t).filter(
        (s) => s.section.id === 'general' ||
          s.section.id === 'integrations' ||
          s.section.id === 'plugin-store' ||
          s.section.id === 'plugins'
      ),
    [registry, t]
  );
  const dirty = Object.keys(draft).length > 0;

  useEffect(() => {
    if (error) addToast(error, 'error');
  }, [error, addToast]);

  const valueOf = (entry: RegistryEntry): string => draft[entry.key] ?? global?.[entry.key] ?? entry.default;

  const setField = (key: string, value: string) => {
    setDraft((prev) => ({ ...prev, [key]: value }));
    setFieldErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const handleSave = async () => {
    if (!global || !dirty) return;

    const settings: Record<string, string> = {};
    for (const key of Object.keys(draft)) {
      settings[key] = draft[key].trim();
    }

    setSaving(true);
    setFieldErrors({});
    try {
      const { error: patchError } = await api.PATCH('/api/v1/settings/global', { body: { settings } });
      if (patchError) {
        setFieldErrors(fieldErrorsFrom(patchError));
        throw new Error(
          errorMessage(patchError, t('settings.platform.saveFailed', 'Failed to save global defaults'))
        );
      }
      addToast(t('settings.platform.saved', 'Global defaults saved.'), 'success');
      setDraft({});
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('settings.platform.saveFailed', 'Failed to save global defaults'),
        'error'
      );
    } finally {
      setSaving(false);
    }
  };

  if (loading || !global) {
    return (
      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-semibold font-heading">
            <h2>{t('settings.platform.title', 'Platform defaults')}</h2>
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-12 animate-pulse rounded-md bg-muted/40" />
          ))}
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {sections.map(({ section, entries }) => (
        <Card
          key={section.id}
          data-testid={`settings-section-${section.id}`}
          className="border border-border bg-card shadow-sm"
        >
          <CardHeader>
            <CardTitle className="text-lg font-semibold font-heading">
              <h2>
                {section.id === 'general'
                  ? t('settings.platform.title', 'Platform defaults')
                  : section.title}
              </h2>
            </CardTitle>
            <CardDescription className="text-sm">
              {section.id === 'general'
                ? t(
                    'settings.platform.description',
                    'Instance-wide fallback values applied to every tenant that has not overridden them.'
                  )
                : section.description}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            {entries.map((entry) => (
              <RegistrySettingControl
                key={entry.key}
                entry={entry}
                idPrefix="platform"
                value={valueOf(entry)}
                error={fieldErrors[entry.key]}
                onChange={(value) => setField(entry.key, value)}
              />
            ))}
          </CardContent>
        </Card>
      ))}

      <div className="flex justify-end">
        <Button
          onClick={handleSave}
          disabled={saving || !dirty}
          className="gap-2"
          data-testid="platform-defaults-save"
        >
          <IconDeviceFloppy className="w-4 h-4" />
          {saving
            ? t('settings.saving', 'Saving…')
            : t('settings.platform.save', 'Save platform defaults')}
        </Button>
      </div>
    </div>
  );
}
