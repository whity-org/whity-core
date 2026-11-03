'use client';

import { SystemErrorState } from '@/components/system-error-state';

export default function ErrorPage({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return <SystemErrorState digest={error.digest} onRetry={reset} />;
}
