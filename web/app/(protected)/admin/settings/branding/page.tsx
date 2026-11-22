'use client';

import { NoAccess } from '@/components/no-access';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { BrandingSettings } from '@/components/branding-settings';
import { ThemePicker } from '@/components/theme-picker';
import { useTranslation } from '@whity/features/i18n';
import { SETTINGS_MANAGE, SETTINGS_READ, SETTINGS_WRITE, SYSTEM_TENANT_ID } from '../settings-shared';

/**
 * Settings › Branding (TplAdminBranding, 09-white-label): the workspace's name,
 * web address, main colour, logos and favicon, with a live preview. The system
 * tenant edits the platform-wide defaults here instead, which takes
 * settings:manage. The default language is on General (section 25). Below it,
 * the theme picker (WHIT-1225).
 */
export default function BrandingSettingsPage() {
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();
  const t = useTranslation('admin');

  if (isCapabilitiesLoading) {
    return (
      <div className="flex min-h-96 items-center justify-center" aria-busy="true">
        <div className="size-8 animate-spin rounded-full border-b-2 border-primary motion-reduce:animate-none" />
      </div>
    );
  }

  if (!hasPermission(SETTINGS_READ)) {
    return (
      <NoAccess area={t('access.area.settings.branding.accessDenied', 'branding')} permission="settings:read" />
    );
  }

  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;
  const scope = isSystemTenant ? 'global' : 'tenant';
  const canWrite = hasPermission(isSystemTenant ? SETTINGS_MANAGE : SETTINGS_WRITE);

  return (
    <div className="mx-auto max-w-6xl px-4 pb-16 md:px-0">
      <BrandingSettings scope={scope} canWrite={canWrite} />
      {/* WHIT-1225: the workspace's theme (TH.13, TH.14). */}
      <div className="mt-6">
        <ThemePicker scope={scope} canWrite={canWrite} />
      </div>
    </div>
  );
}
