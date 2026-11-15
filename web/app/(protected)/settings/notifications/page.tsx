'use client';

/**
 * You in [workspace] › Notifications (WHIT-1081): push on this device and which
 * kinds, for the workspace you're in. Choices are stored per workspace
 * (`user_notification_preferences.tenant_id`), so the page says where it applies.
 */

import { useTranslation } from '@whity/features/i18n';
import { PushSettings } from '@/components/settings/push-settings';
import { MySettingsHeader } from '@/components/settings/scope';

export default function NotificationSettingsPage() {
  const t = useTranslation('auth');
  return (
    <div className="space-y-6">
      <MySettingsHeader
        title={t('settings.notifications.title', 'Notifications')}
        description={t('settings.notifications.pageDescription', 'What Whity tells you about, and on which devices.')}
        scope="workspace"
      />
      <PushSettings />
    </div>
  );
}
