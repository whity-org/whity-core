'use client';

/**
 * Your account (docs/design/components/TplAccountPrefs, TplAccountSecurity; W.3).
 * From the person's name at the foot of the sidebar, in four tabs: Profile,
 * Sign-in and security, Sessions and devices, Preferences. The tab lives in the
 * address (`?tab=`) so a link or a refresh lands on the same one.
 */

import { Suspense } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@whity/ui/tabs';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { TwoFactorSettings } from '@/components/TwoFactorSettings';
import { SessionsSettings } from '@/components/SessionsSettings';
import { DevicesSettings } from '@/components/DevicesSettings';
import { DisplayPreferencesSettings } from '@/components/display-preferences-settings';
import { EmailAddressesSettings } from '@/components/EmailAddressesSettings';
import { LanguageSwitcher, useI18nEnabled, useTranslation } from '@whity/features/i18n';
import { ProfileForm } from './profile-form';

const TABS = ['profile', 'security', 'sessions', 'preferences'] as const;
type TabId = (typeof TABS)[number];

function isTab(value: string | null): value is TabId {
  return value !== null && (TABS as readonly string[]).includes(value);
}

function SettingsPageInner() {
  const auth = useAuth();
  const t = useTranslation('auth');
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  // Whether this instance offers a language choice at all (`i18n.enabled`).
  const isI18nEnabled = useI18nEnabled();
  const requested = params.get('tab');
  const tab: TabId = isTab(requested) ? requested : 'profile';

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('settings.title', 'Your account')}</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {t('settings.subtitle', 'Manage your account and security preferences')}
        </p>
      </div>

      <Tabs value={tab} onValueChange={(next) => router.replace(`${pathname}?tab=${next}`, { scroll: false })}>
        <TabsList>
          <TabsTrigger value="profile">{t('settings.tab.profile', 'Profile')}</TabsTrigger>
          <TabsTrigger value="security">{t('settings.tab.security', 'Sign-in and security')}</TabsTrigger>
          <TabsTrigger value="sessions">{t('settings.tab.sessions', 'Sessions and devices')}</TabsTrigger>
          <TabsTrigger value="preferences">{t('settings.tab.preferences', 'Preferences')}</TabsTrigger>
        </TabsList>

        {/* Profile — self-service edit (WC-64) */}
        <TabsContent value="profile" className="space-y-6 pt-4">
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.profile.title', 'Profile')}</CardTitle>
              <CardDescription>
                {t('settings.profile.description', 'Update your email and password')}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div>
                <label className="text-sm font-medium text-muted-foreground">
                  {t('settings.profile.role', 'Role')}
                </label>
                <p className="mt-2 rounded-control bg-muted p-3 text-sm capitalize">{auth.user?.role}</p>
              </div>

              <ProfileForm />
            </CardContent>
          </Card>

          {/* Email addresses — multi-email self-service (WC-54fb5c37) */}
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.emails.title', 'Email addresses')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.emails.description',
                  'Add, verify, and manage the email addresses on your account'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <EmailAddressesSettings />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="security" className="space-y-6 pt-4">
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.security.title', 'Security')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.security.description',
                  'Protect your account with two-factor authentication'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <TwoFactorSettings />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="sessions" className="space-y-6 pt-4">
          {/* Sessions (WC-b-logout-others) — interactive browser/app logins only;
              native-device credentials are a genuinely separate list below (#409). */}
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.sessions.title', 'Sessions')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.sessions.description',
                  'Sign out of your active sessions on other browsers and apps'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <SessionsSettings />
            </CardContent>
          </Card>

          {/* Devices (#409) — long-lived native-client credentials (desktop and
              mobile apps), distinct from the interactive sessions above. */}
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.devices.title', 'Devices')}</CardTitle>
              <CardDescription>
                {t(
                  'settings.devices.description',
                  'Manage native apps and devices with long-lived access to your account'
                )}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <DevicesSettings />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="preferences" className="space-y-6 pt-4">
          <DisplayPreferencesSettings />

          {/* Language. This also sets the interface DIRECTION: each language
              carries its own 'ltr'/'rtl', so choosing Arabic mirrors the interface.
              See lib/direction-context.tsx — there is no separate toggle.

              The WHOLE CARD is gated on `i18n.enabled`, not just the control inside
              it: an instance that offers one language should not show a "Language"
              heading explaining a choice its users do not have. */}
          {isI18nEnabled && (
            <Card>
              <CardHeader>
                <CardTitle>{t('settings.language.title', 'Language')}</CardTitle>
                <CardDescription>
                  {t(
                    'settings.language.description',
                    'Choose the language used across the interface. Right-to-left languages mirror the layout automatically.'
                  )}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <LanguageSwitcher
                  variant="dropdown"
                  className="h-10 w-full max-w-xs rounded-control border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />
              </CardContent>
            </Card>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}

export default function SettingsPage() {
  return (
    <Suspense fallback={null}>
      <SettingsPageInner />
    </Suspense>
  );
}
