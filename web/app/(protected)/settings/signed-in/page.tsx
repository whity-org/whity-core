'use client';

/**
 * You in [workspace] › Where you're signed in (WHIT-1081): browser and app
 * sessions, then apps with long-lived access (#409). Sessions belong to the
 * workspace they were opened in.
 */

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { useTranslation } from '@whity/features/i18n';
import { SessionsSettings } from '@/components/SessionsSettings';
import { DevicesSettings } from '@/components/DevicesSettings';
import { MySettingsHeader } from '@/components/settings/scope';

export default function SignedInSettingsPage() {
  const t = useTranslation('auth');
  return (
    <div className="space-y-6">
      <MySettingsHeader
        title={t('settings.tab.sessions', 'Where you’re signed in')}
        description={t('settings.sessions.pageDescription', 'The browsers, apps and devices signed in as you.')}
        scope="workspace"
      />
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.sessions.title', 'Sessions')}</CardTitle>
          <CardDescription>
            {t('settings.sessions.description', 'Sign out of your active sessions on other browsers and apps')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <SessionsSettings />
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.devices.title', 'Devices')}</CardTitle>
          <CardDescription>
            {t('settings.devices.description', 'Manage native apps and devices with long-lived access to your account')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <DevicesSettings />
        </CardContent>
      </Card>
    </div>
  );
}
