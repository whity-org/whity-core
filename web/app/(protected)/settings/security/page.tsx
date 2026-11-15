'use client';

/** My settings › Password and two-step (TplAccountSecurity; WHIT-1081). */

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { useTranslation } from '@whity/features/i18n';
import { TwoFactorSettings } from '@/components/TwoFactorSettings';
import { MySettingsHeader } from '@/components/settings/scope';

export default function SecuritySettingsPage() {
  const t = useTranslation('auth');
  return (
    <div className="space-y-6">
      <MySettingsHeader
        title={t('settings.tab.security', 'Password and two-step')}
        description={t('settings.security.pageDescription', 'How you prove it’s you when you sign in.')}
        scope="account"
      />
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.security.title', 'Security')}</CardTitle>
          <CardDescription>
            {t('settings.security.description', 'Protect your account with two-factor authentication')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <TwoFactorSettings />
        </CardContent>
      </Card>
    </div>
  );
}
