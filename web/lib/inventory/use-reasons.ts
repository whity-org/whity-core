'use client';

/**
 * The workspace's reasons for stock changes (WHIT-1233, register N5): its own
 * list, seeded with expired, damaged, end of day unsold, found and the rest.
 * Names come in the reader's language; a code the list doesn't know (an old
 * one, or a plugin's) shows as itself.
 */

import { useCallback } from 'react';
import { useFetch } from '@/hooks/useFetch';
import { useLanguageCode } from '@whity/features/i18n';
import { inventoryApi, nameIn, type InventoryReason } from '@/lib/inventory/api';

export function useReasons(): { reasons: InventoryReason[]; reasonName: (code: string) => string; loading: boolean } {
  const language = useLanguageCode();
  const { data, loading } = useFetch(() => inventoryApi.reasons(), []);
  const reasons = data ?? [];
  const reasonName = useCallback(
    (code: string) => {
      const found = (data ?? []).find((r) => r.code === code);
      return found ? nameIn(found.names, found.name, language) : code;
    },
    [data, language]
  );
  return { reasons, reasonName, loading };
}
