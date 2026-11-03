'use client';

import { MySubmissionsScreen } from '@whity/features/forms';
import { useFormsEnv } from '@/lib/forms-env';

/** What a person has sent: `/admin/x/my-form-submissions`. */
export default function MyFormSubmissionsPage() {
  return <MySubmissionsScreen env={useFormsEnv()} />;
}
