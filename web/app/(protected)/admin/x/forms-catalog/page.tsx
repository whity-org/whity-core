'use client';

import { FormsCatalogScreen } from '@whity/features/forms';
import { useFormsEnv } from '@/lib/forms-env';
import { useCapabilities } from '@/hooks/useCapabilities';

/** The forms a person can fill in: `/admin/x/forms-catalog`. */
export default function FormsCatalogPage() {
  const { hasPermission, loading } = useCapabilities();
  const env = useFormsEnv();

  // Fail-closed while loading, which would send somebody who can read forms to the wrong list.
  if (loading) return null;

  return <FormsCatalogScreen env={env} canReadForms={hasPermission('forms:read')} />;
}
