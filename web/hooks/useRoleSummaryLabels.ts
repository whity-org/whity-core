'use client';

import { useMemo } from 'react';
import type { RoleSummaryLabels } from '@whity/ui/role-summary';
import { useTranslation } from '@whity/features/i18n';

/**
 * The words of `RoleSummary` (WHIT-1175) in the reader's language: "+N more"
 * after the first role, the comma between roles and the name of the focusable
 * count that opens the full list.
 */
export function useRoleSummaryLabels(): RoleSummaryLabels {
  const t = useTranslation('common');
  return useMemo(
    () => ({
      more: (count: number) => t('roles.summary.more', '+{count} more', { count }),
      separator: t('roles.summary.separator', ', '),
      all: t('roles.summary.all', 'All roles: {roles}'),
    }),
    [t],
  );
}
