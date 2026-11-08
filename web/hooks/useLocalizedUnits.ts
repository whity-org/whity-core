'use client';

import { useMemo } from 'react';
import { useLanguageCode } from '@whity/features/i18n';
import { localizedName, type LocalizedNames } from '@/lib/localized-name';

/** The name fields of a unit, as `GET /api/ous` lists them (#821). */
export interface UnitNames {
  name: string;
  names?: LocalizedNames | null;
  name_language?: string | null;
}

/**
 * The units with `name` replaced by the name to show this reader: theirs when
 * it exists, otherwise the original.
 *
 * For display and picking only. On these rows `name` is no longer the original,
 * so it must never be sent back to the API. Pickers send the unit's id.
 *
 * It works on rows already fetched, so switching language needs no new request.
 */
export function useLocalizedUnits<T extends UnitNames>(units: readonly T[] | null | undefined): T[] {
  const language = useLanguageCode();
  return useMemo(
    () =>
      (units ?? []).map((unit) => ({
        ...unit,
        name: localizedName(unit.names, language, unit.name_language, unit.name),
      })),
    [units, language]
  );
}
