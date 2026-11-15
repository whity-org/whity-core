'use client';

/**
 * The words of every data table's phone controls (PwLists), translated once
 * for the whole app.
 */

import { useMemo } from 'react';
import { DataTablePhoneLabelsProvider } from '@whity/ui/data-table';
import { useTranslation } from '@whity/features/i18n';

export function DataTableLabels({ children }: { children: React.ReactNode }) {
  const t = useTranslation('common');
  const labels = useMemo(
    () => ({
      sort: t('list.sort', 'Sort'),
      sortTitle: t('list.sortTitle', 'Sort'),
      ascending: (column: string) => t('list.ascending', '{column}, lowest first').replace('{column}', column),
      descending: (column: string) => t('list.descending', '{column}, highest first').replace('{column}', column),
      defaultOrder: t('list.defaultOrder', 'Default order'),
      select: t('list.select', 'Select'),
      cancel: t('list.cancel', 'Cancel'),
      chosen: (count: number) => t('list.chosen', '{count} chosen').replace('{count}', String(count)),
      clearAll: t('list.clearAll', 'Clear all'),
      chooseAll: t('list.chooseAll', 'Choose all'),
      filter: t('list.filter', 'Filter'),
      filtersTitle: t('list.filtersTitle', 'Filters'),
      clearFilters: t('list.clearFilters', 'Clear all'),
      done: t('list.done', 'Done'),
      removeFilter: (label: string) => t('list.removeFilter', 'Remove filter: {label}').replace('{label}', label),
    }),
    [t],
  );
  return <DataTablePhoneLabelsProvider labels={labels}>{children}</DataTablePhoneLabelsProvider>;
}
