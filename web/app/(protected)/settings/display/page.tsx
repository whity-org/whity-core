'use client';

/**
 * My settings › Language and display (W.3; WHIT-1081): the language, then the
 * Display card (light or dark on this device; text size, technical details
 * and animations on the account).
 */

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { LanguageSwitcher, useI18nEnabled, useTranslation } from '@whity/features/i18n';
import { DisplayPreferencesSettings } from '@/components/display-preferences-settings';
import { MySettingsHeader } from '@/components/settings/scope';

export default function DisplaySettingsPage() {
  const t = useTranslation('auth');
  // An instance that offers one language shows no Language card at all.
  const isI18nEnabled = useI18nEnabled();
  return (
    <div className="space-y-6">
      <MySettingsHeader
        title={t('settings.tab.preferences', 'Language and display')}
        description={t('settings.display.pageDescription', 'The language Whity speaks to you, and how it looks.')}
        scope="account"
      />
      {/* Language also sets the direction: Arabic mirrors the interface (lib/direction-context.tsx). */}
      {isI18nEnabled && (
        <Card>
          <CardHeader>
            <CardTitle>{t('settings.language.title', 'Language')}</CardTitle>
            <CardDescription>
              {t(
                'settings.language.description',
                'Choose the language used across the interface. Right-to-left languages mirror the layout automatically.',
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
      <DisplayPreferencesSettings />
    </div>
  );
}
