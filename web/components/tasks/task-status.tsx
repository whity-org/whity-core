'use client';

import { Badge } from '@whity/ui/badge';
import { useTranslation } from '@whity/features/i18n';
import type { TaskColumn, TaskRecord } from './task-types';

/**
 * A task's state where it matters (TplTasks): Working, Awaiting review (dashed amber), Sent back,
 * Done and verified (green) or Done, unverified. Nothing for an ordinary open task.
 */
export function GateBadge({ gate, column }: { gate: TaskRecord['gate']; column: TaskColumn }) {
  const t = useTranslation('tasks');
  if (gate === 'awaiting_review') {
    return <Badge variant="warning" className="border border-dashed border-warning">{t('state.review', 'Awaiting review')}</Badge>;
  }
  if (gate === 'sent_back') return <Badge variant="destructive">{t('state.sentBack', 'Sent back')}</Badge>;
  if (gate === 'accepted') return <Badge variant="success">{t('state.verified', 'Done and verified')}</Badge>;
  if (gate === 'unverified') return <Badge variant="outline">{t('state.unverified', 'Done, unverified')}</Badge>;
  if (column === 'done') return <Badge variant="success">{t('state.done', 'Done')}</Badge>;
  if (column === 'doing') return <Badge variant="info">{t('state.working', 'Working')}</Badge>;
  return null;
}
