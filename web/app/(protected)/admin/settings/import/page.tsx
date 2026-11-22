'use client';

/**
 * Settings › Import (WHIT-1326, design section 34 §6.5): every kind the
 * person may import, with a picker, on the shared import screen.
 * `?kind=contacts` starts on one kind.
 */

import { useSearchParams } from 'next/navigation';
import { ImportScreen } from '@/components/import/import-screen';

export default function SettingsImportPage() {
  const kind = useSearchParams().get('kind') ?? undefined;
  return <ImportScreen kind={kind} />;
}
