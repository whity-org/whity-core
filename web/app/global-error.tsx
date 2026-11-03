'use client';

import './globals.css';
import { SystemErrorState } from '@/components/system-error-state';

/** Replaces the root layout when it fails, so it brings its own html and body. */
export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <html lang="en">
      <body className="min-h-full">
        <SystemErrorState digest={error.digest} onRetry={reset} />
      </body>
    </html>
  );
}
