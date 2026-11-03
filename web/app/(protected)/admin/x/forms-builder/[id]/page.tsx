'use client';

import { useParams } from 'next/navigation';
import { FormBuilderScreen } from '@whity/features/forms';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFormsEnv } from '@/lib/forms-env';

/** One form's record page: `/admin/x/forms-builder/[id]`. */
export default function FormBuilderPage() {
  const params = useParams<{ id: string | string[] }>();
  const raw = Array.isArray(params.id) ? params.id[0] : params.id;
  const formId = Number(raw);
  const env = useFormsEnv();
  const { hasPermission } = useCapabilities();

  // A segment that is not a whole number never reaches a fetch.
  if (!Number.isInteger(formId) || formId <= 0) {
    return null;
  }

  return <FormBuilderScreen env={env} formId={formId} canManage={hasPermission('forms:manage')} />;
}
