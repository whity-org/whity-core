'use client';

import { AuthGate } from '@/components/auth-gate';
import { Sidebar } from '@/components/sidebar';
import { TopBar } from '@/components/shell/top-bar';
import { AppCommandPalette } from '@/components/shell/app-command-palette';
import { MaintenanceNotice } from '@/components/shell/maintenance-notice';
import { PlanLimitBanner } from '@/components/billing/plan-limit-banner';
import { PhoneNav } from '@/components/shell/phone-nav';
import { DataTableLabels } from '@/components/shell/data-table-labels';
import { PushOffer } from '@/components/pwa/push-offer';
import { AppSwitchSheet } from '@/components/pwa/app-switch-sheet';

/**
 * The standard app shell: sidebar + a padded, max-width content column; on a
 * phone (under 640px) the tab bar instead of the sidebar (`PwNav`).
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
          carries the insets itself (physical sides: the notch doesn't mirror).
          Under 640px the phone's tab bar (PwNav) takes the bottom 4rem, so the
          shell stops above it and `main`, sticky save bars included, ends where
          the bar begins; while the keyboard is open the bar is `hidden` and the
          room comes back. */}
      <div className="fixed inset-0 flex overflow-hidden bg-background pt-[env(safe-area-inset-top)] pr-[env(safe-area-inset-right)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] max-sm:pb-[calc(4rem+env(safe-area-inset-bottom))] max-sm:has-[>nav[hidden]]:pb-[env(safe-area-inset-bottom)]">
        {/* Sidebar - responsive widths handled in component */}
        <Sidebar />

        {/* Main Content */}
        <main className="min-w-0 flex-1 overflow-auto">
          {/* WHIT-147: the top bar holds search (⌘K); the palette mounts once per shell. */}
          <TopBar />
          <MaintenanceNotice />
          {/* TplPlanLimit (BM.11): past a plan limit, for people who can change the plan. */}
          <PlanLimitBanner />
          {/* Gutters 16px on phones, 24 from 640, 32 from 768 (register 4.5).
              A page marked data-full-width (the calendar, CV.1) takes the whole width. */}
          <div className="p-4 sm:p-6 md:p-8 max-w-7xl has-[[data-full-width]]:max-w-none">
            {/* WHIT-1077: the notifications ask, after sign-in on any page, until answered. */}
            <PushOffer className="mb-6" />
            <DataTableLabels>{children}</DataTableLabels>
          </div>
        </main>
        <AppCommandPalette />
        <PhoneNav />
        {/* WHIT-1155: on a phone's browser, open the installed app or install it. */}
        <AppSwitchSheet />
      </div>
    </AuthGate>
  );
}
