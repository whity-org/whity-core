'use client';

/** My settings › Profile (TplAccountPrefs; WHIT-1081): your name, sign-in details and email addresses. */

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { useTranslation } from '@whity/features/i18n';
import { EmailAddressesSettings } from '@/components/EmailAddressesSettings';
import { MySettingsHeader } from '@/components/settings/scope';
import { MyPhoneSettings } from '@/components/settings/my-phone-settings';
import { ProfileForm } from '../profile-form';

export default function ProfileSettingsPage() {
  const t = useTranslation('auth');
  return (
    <div className="space-y-6">
      <MySettingsHeader
        title={t('settings.profile.title', 'Profile')}
        description={t('settings.profile.pageDescription', 'Your name and the email addresses on your account.')}
        scope="account"
      />
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.profile.title', 'Profile')}</CardTitle>
          <CardDescription>{t('settings.profile.description', 'Update your email and password')}</CardDescription>
        </CardHeader>
        <CardContent>
          <ProfileForm />
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.phone.title', 'Phone number')}</CardTitle>
          <CardDescription>
            {t('settings.phone.description', 'Your own number, on your account in every workspace')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <MyPhoneSettings />
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.emails.title', 'Email addresses')}</CardTitle>
          <CardDescription>
            {t('settings.emails.description', 'Add, verify, and manage the email addresses on your account')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <EmailAddressesSettings />
        </CardContent>
      </Card>
    </div>
  );
}
