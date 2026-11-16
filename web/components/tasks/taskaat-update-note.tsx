'use client';

import { Alert, AlertDescription } from '@whity/ui/alert';
import { useTranslation } from '@whity/features/i18n';

/**
 * Shown on Tasks and Boards while the workspace's Taskaat does not have the record tools yet
 * (the API answers 501, code `taskaat_update_needed`). The tasks are still there in Taskaat.
 */
export function TaskaatUpdateNote() {
  const t = useTranslation('tasks');
  return (
    <Alert variant="info">
      <AlertDescription>
        {t('update.note', 'Taskaat needs an update before tasks can show here. Open them in Taskaat meanwhile.')}{' '}
        <a className="underline" href="https://app.taskaat.dev" target="_blank" rel="noopener noreferrer">{t('connected.open', 'Open in Taskaat')}</a>
      </AlertDescription>
    </Alert>
  );
}
