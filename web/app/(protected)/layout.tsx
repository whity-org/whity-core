'use client';

import { AuthGate } from '@/components/auth-gate';
import { Sidebar } from '@/components/sidebar';
import { TopBar } from '@/components/shell/top-bar';
import { AppCommandPalette } from '@/components/shell/app-command-palette';
import { MaintenanceNotice } from '@/components/shell/maintenance-notice';

/**
 * The standard app shell: sidebar + a padded, max-width content column.
 *
 * The authentication/first-run rules live in `<AuthGate>` (shared with
 * `app/(editor)/layout.tsx`, the full-screen shell), so this layout is now only
 * about CHROME.
 */
export default function ProtectedLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <AuthGate>
      {/* `fixed inset-0` (not `h-screen`, which is only a same-magnitude
          *coincidence* with the viewport, not a binding to it) takes the shell
          out of document flow entirely: it always exactly covers the viewport
          no matter how tall its content wants to be, so `body` never grows past
          one viewport height and the window itself can never scroll. `main`'s
          own `overflow-auto` remains the ONLY scroll container — without this,
          a tall page (e.g. Settings after WC-fbdc31a2 added the email-addresses
          card) grows `body` too, producing a second, outer window scrollbar
          that clips the fixed sidebar out of view as you scroll it.
          Being fixed, it also ignores body's safe-area padding (#1733), so it
          carries the insets itself (physical sides: the notch doesn't mirror). */}
      <div className="fixed inset-0 flex overflow-hidden bg-background pt-[env(safe-area-inset-top)] pr-[env(safe-area-inset-right)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)]">
        {/* Sidebar - responsive widths handled in component */}
        <Sidebar />

        {/* Main Content */}
        <main className="min-w-0 flex-1 overflow-auto">
          {/* WHIT-147: the top bar holds search (⌘K); the palette mounts once per shell. */}
          <TopBar />
          <MaintenanceNotice />
          {/* Gutters 16px on phones, 24 from 640, 32 from 768 (register 4.5). */}
          <div className="p-4 sm:p-6 md:p-8 max-w-7xl">{children}</div>
        </main>
        <AppCommandPalette />
      </div>
    </AuthGate>
  );
}
